// NAMAA sensor node firmware (classic ESP32 and ESP32-S3).
//
// Every report interval: read the enabled sensors and POST them to
// /api/device/readings (Wi-Fi), or print them as `NAMAA:{json}` for the USB bridge.
// The reply sets the pump target, the next interval and the offline local rule.
// The hardware-free logic (payload, reply, pump guard, buffer, serial parsing)
// lives in lib/namaa_core and has host unit tests. Type `help` on the serial port.
#include <Arduino.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <WiFi.h>
#include <Wire.h>
#include <esp_task_wdt.h>
#include <time.h>

#include "config.h"
#include "namaa_core.h"

#if __has_include("secrets.h")
#include "secrets.h"
#else
#warning "include/secrets.h not found: building with secrets.h.example placeholders (set real values over serial)"
#include "secrets.h.example"
#endif

#if FEATURE_DHT
#include <DHT.h>
#endif
#if FEATURE_LCD
#include <LiquidCrystal_I2C.h>
#endif

using namespace namaa;

#define LOG(fmt, ...) Serial.printf("[namaa] " fmt "\n", ##__VA_ARGS__)

static const char* kApiPath = "/api/device/readings";

// ---------------------------------------------------------------------------
// Settings (NVS, falling back to secrets.h)
// ---------------------------------------------------------------------------

struct Settings {
  String ssid1, pass1, ssid2, pass2, host, token;
  uint16_t port = 8000;
  int32_t nodeId = 0;
  bool usbMode = false;
  int32_t dryRaw = SOIL_DRY_RAW, wetRaw = SOIL_WET_RAW;
};

static Preferences prefs;
static Settings cfg;

static void loadSettings() {
  prefs.begin("namaa", true);
  cfg.ssid1 = prefs.getString("ssid1", WIFI_SSID_1);
  cfg.pass1 = prefs.getString("pass1", WIFI_PASS_1);
  cfg.ssid2 = prefs.getString("ssid2", WIFI_SSID_2);
  cfg.pass2 = prefs.getString("pass2", WIFI_PASS_2);
  cfg.host = prefs.getString("host", SERVER_HOST);
  cfg.port = prefs.getUShort("port", SERVER_PORT);
  cfg.nodeId = prefs.getInt("node", NODE_ID);
  cfg.token = prefs.getString("token", NODE_TOKEN);
  cfg.usbMode = prefs.getBool("usb", false);
  cfg.dryRaw = prefs.getInt("dry", SOIL_DRY_RAW);
  cfg.wetRaw = prefs.getInt("wet", SOIL_WET_RAW);
  prefs.end();
}

static LocalRule loadRule() {
  LocalRule r;
  prefs.begin("namaa", true);
  r.valid = prefs.getBool("rule_ok", false);
  r.minPct = prefs.getFloat("rule_min", 0);
  r.targetPct = prefs.getFloat("rule_tgt", 0);
  r.maxRunS = prefs.getUInt("rule_max", PumpGuard::kDefaultMaxRunS);
  prefs.end();
  return r;
}

static void saveRule(const LocalRule& r) {
  prefs.begin("namaa", false);
  prefs.putBool("rule_ok", true);
  prefs.putFloat("rule_min", r.minPct);
  prefs.putFloat("rule_tgt", r.targetPct);
  prefs.putUInt("rule_max", r.maxRunS);
  prefs.end();
}

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

static PumpGuard guard;
static RingBuffer<Reading, OFFLINE_BUFFER_SIZE> backlog;

static uint32_t intervalS = REPORT_INTERVAL_DEFAULT_S;
static uint32_t nextReportMs = 0;
static uint32_t lastOkMs = 0;
static bool everOk = false;
static float lastPct = NAN;
static bool relayOn = false;
static bool calMode = false;
static uint32_t calNextMs = 0;
static bool usbAuto = false;  // switched to USB after Wi-Fi failures (not saved)

// USB mode: waiting for NAMAA-REPLY for this reading.
static bool usbAwaiting = false;
static uint32_t usbAwaitUntil = 0;
static Reading usbPending;

// Wi-Fi state machine.
enum class WifiState { Idle, Connecting, Connected };
static WifiState wifiState = WifiState::Idle;
static uint8_t wifiNet = 0;  // 0 = network 1, 1 = network 2
static uint32_t wifiStartMs = 0, wifiNextMs = 0, wifiFailRounds = 0, wifiUpSinceMs = 0;
static bool ntpStarted = false;

static bool usbActive() { return cfg.usbMode || usbAuto; }

// ---------------------------------------------------------------------------
// Hardware
// ---------------------------------------------------------------------------

#if FEATURE_DHT
static DHT dht(PIN_DHT, DHT_TYPE);
static uint32_t dhtLastMs = 0;
static bool dhtRead = false;
static float dhtT = NAN, dhtH = NAN;
#endif

#if FEATURE_FLOW
static volatile uint32_t flowPulses = 0;
static uint32_t flowLastMs = 0;
static void IRAM_ATTR onFlowPulse() { flowPulses++; }
#endif

#if FEATURE_LCD
static LiquidCrystal_I2C* lcd = nullptr;
static uint32_t lcdNextMs = 0;
#endif

static void setRelay(bool on) {
#if FEATURE_RELAY
  digitalWrite(PIN_RELAY, (on == (bool)RELAY_ACTIVE_HIGH) ? HIGH : LOW);
  if (on != relayOn) LOG("pump %s", on ? "ON" : "OFF");
  relayOn = on;
#else
  (void)on;
#endif
}

static int32_t readAnalog(int pin) {
  int32_t s[ADC_SAMPLES];
  for (int i = 0; i < ADC_SAMPLES; i++) s[i] = analogRead(pin);
  return averageRaw(s, ADC_SAMPLES);
}

static int32_t readSoilRaw() {
#if FEATURE_SOIL
  return readAnalog(PIN_SOIL);
#else
  return -1;
#endif
}

static int32_t readLevelRaw() {
#if FEATURE_WATER_LEVEL
  return readAnalog(PIN_WATER_LEVEL);
#else
  return -1;
#endif
}

static Reading takeReading(uint32_t now) {
  Reading r;
  r.takenMs = now;
  r.moisture = readSoilRaw();
  r.waterLevel = readLevelRaw();
#if FEATURE_DHT
  if (!dhtRead || now - dhtLastMs >= 2000) {  // DHT: at most every 2 s
    dhtT = dht.readTemperature();
    dhtH = dht.readHumidity();
    dhtLastMs = now;
    dhtRead = true;
    if (isnan(dhtT) || isnan(dhtH)) LOG("DHT read failed: sending null");
  }
  r.temperature = dhtT;
  r.humidity = (dhtH >= 0 && dhtH <= 100) ? dhtH : NAN;
#endif
#if FEATURE_FLOW
  noInterrupts();
  uint32_t p = flowPulses;
  flowPulses = 0;
  interrupts();
  r.flow = flowLpm(p, FLOW_PULSES_PER_LITRE, now - flowLastMs);
  flowLastMs = now;
#endif
#if FEATURE_RELAY
  r.pumpOn = relayOn ? 1 : 0;  // the state we actually drive
#endif
  lastPct = moisturePct(r.moisture, cfg.dryRaw, cfg.wetRaw);
  return r;
}

static void lcdInit() {
#if FEATURE_LCD
  Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);
  uint8_t addrs[2] = {LCD_I2C_ADDR, LCD_I2C_ADDR_ALT};
  for (uint8_t a : addrs) {
    Wire.beginTransmission(a);
    if (Wire.endTransmission() == 0) {
      lcd = new LiquidCrystal_I2C(a, 16, 2);
      lcd->init();
      lcd->backlight();
      LOG("LCD found at 0x%02X", a);
      return;
    }
  }
  LOG("no LCD at 0x%02X or 0x%02X (skipped)", LCD_I2C_ADDR, LCD_I2C_ADDR_ALT);
#endif
}

static void lcdUpdate(uint32_t now) {
#if FEATURE_LCD
  if (!lcd || now < lcdNextMs) return;
  lcdNextMs = now + 1000;
  const char* net = usbActive() ? "USB" : (wifiState == WifiState::Connected ? "WiFi" : "offline");
  char l1[17], l2[17], m[8], ago[10];
  if (isnan(lastPct)) snprintf(m, sizeof m, "--"); else snprintf(m, sizeof m, "%d%%", (int)lroundf(lastPct));
  snprintf(l1, sizeof l1, "%-7s M:%-6s", net, m);
  if (everOk) snprintf(ago, sizeof ago, "%lus", (unsigned long)((now - lastOkMs) / 1000)); else snprintf(ago, sizeof ago, "never");
#if FEATURE_RELAY
  const char* p = relayOn ? (guard.localRunActive() ? "LOCAL" : "ON") : "OFF";
#else
  const char* p = "--";
#endif
  snprintf(l2, sizeof l2, "P:%-5s ok:%-6s", p, ago);
  lcd->setCursor(0, 0); lcd->print(l1);
  lcd->setCursor(0, 1); lcd->print(l2);
#endif
}

// ---------------------------------------------------------------------------
// Wi-Fi
// ---------------------------------------------------------------------------

static const String& netSsid(uint8_t i) { return i == 0 ? cfg.ssid1 : cfg.ssid2; }
static const String& netPass(uint8_t i) { return i == 0 ? cfg.pass1 : cfg.pass2; }

static void wifiBegin(uint8_t i, uint32_t now) {
  wifiNet = i;
  wifiState = WifiState::Connecting;
  wifiStartMs = now;
  LOG("Wi-Fi: connecting to network %u \"%s\"", i + 1, netSsid(i).c_str());
  WiFi.disconnect();
  WiFi.begin(netSsid(i).c_str(), netPass(i).c_str());
}

static void wifiLoop(uint32_t now) {
  if (usbActive()) return;
  bool up = WiFi.status() == WL_CONNECTED;

  if (wifiState == WifiState::Connected) {
    if (!up) {
      LOG("Wi-Fi lost");
      wifiState = WifiState::Idle;
      wifiNextMs = now;
    }
    return;
  }
  if (wifiState == WifiState::Connecting) {
    if (up) {
      wifiState = WifiState::Connected;
      wifiFailRounds = 0;
      wifiUpSinceMs = now;
      LOG("Wi-Fi up: \"%s\" ip %s rssi %d dBm", netSsid(wifiNet).c_str(),
          WiFi.localIP().toString().c_str(), WiFi.RSSI());
      if (!ntpStarted) {
        configTime(0, 0, NTP_SERVER_1, NTP_SERVER_2);  // UTC
        ntpStarted = true;
      }
      return;
    }
    if (now - wifiStartMs < WIFI_CONNECT_TIMEOUT_MS) return;
    if (wifiNet == 0 && cfg.ssid2.length() > 0) {  // try network 2
      wifiBegin(1, now);
      return;
    }
    WiFi.disconnect();
    wifiState = WifiState::Idle;
    uint32_t wait = backoffMs(wifiFailRounds);
    wifiFailRounds++;
    wifiNextMs = now + wait;
    LOG("Wi-Fi: no network (round %lu), retry in %lus", (unsigned long)wifiFailRounds,
        (unsigned long)(wait / 1000));
    if (wifiFailRounds >= USB_AUTO_AFTER_WIFI_FAILS && Serial) {
      usbAuto = true;
      WiFi.disconnect();
      LOG("Wi-Fi failed %d times: switching to USB mode (type `mode wifi` to go back)",
          USB_AUTO_AFTER_WIFI_FAILS);
    }
    return;
  }
  // Idle
  if ((int32_t)(now - wifiNextMs) >= 0) {
    if (cfg.ssid1.length() > 0) wifiBegin(0, now);
    else if (cfg.ssid2.length() > 0) wifiBegin(1, now);
    else wifiNextMs = now + 10000;
  }
}

static bool timeValid() { return time(nullptr) > 1700000000; }

// ---------------------------------------------------------------------------
// Reporting
// ---------------------------------------------------------------------------

static size_t payloadFor(const Reading& r, bool buffered, const char* via, char* out, size_t size) {
  PayloadMeta m;
  m.nodeId = cfg.nodeId;
  if (!usbActive() && wifiState == WifiState::Connected) {
    m.hasRssi = true;
    m.rssi = WiFi.RSSI();
  }
  m.uptimeS = millis() / 1000;
  m.fwVersion = FW_VERSION;
  m.via = via;
  if (buffered && timeValid()) m.recordedAt = epochAt(time(nullptr), millis(), r.takenMs);
  return buildPayload(r, m, out, size);
}

// Returns the HTTP status (<= 0 on connection errors).
static int httpPost(const char* body, size_t len, String& response) {
  WiFiClient client;
  HTTPClient http;
  http.setConnectTimeout(HTTP_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);
  if (!http.begin(client, cfg.host, cfg.port, kApiPath)) return -1;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  http.addHeader("Authorization", String("Bearer ") + cfg.token);
  int status = http.POST((uint8_t*)body, len);
  if (status > 0) response = http.getString();
  http.end();
  return status;
}

static void applyReply(const char* json, size_t len, uint32_t now) {
  Reply rep;
  if (!parseReply(json, len, rep)) {
    LOG("reply not understood: ignored");
    return;
  }
  guard.onReply(rep.pump, now);
  if (rep.hasRule) {
    const LocalRule& old = guard.rule();
    if (!old.valid || old.minPct != rep.rule.minPct || old.targetPct != rep.rule.targetPct ||
        old.maxRunS != rep.rule.maxRunS) {
      saveRule(rep.rule);
      LOG("local rule: min %.0f%% target %.0f%% max run %lus", rep.rule.minPct,
          rep.rule.targetPct, (unsigned long)rep.rule.maxRunS);
    }
    guard.setRule(rep.rule);
  }
  if (rep.intervalS) intervalS = rep.intervalS;
  lastOkMs = now;
  everOk = true;
}

static void reportFailed(const Reading& r, bool keep) {
  guard.onPostFailed();
  intervalS = REPORT_INTERVAL_DEFAULT_S;
  if (keep && backlog.push(r)) LOG("offline buffer full: oldest reading dropped");
  if (guard.failures() == PumpGuard::kOfflineAfterFailures)
    LOG("offline: %u posts failed, local rule %s", PumpGuard::kOfflineAfterFailures,
        guard.rule().valid ? "active" : "unknown (pump stays off)");
}

// Sends buffered readings (oldest first) with their recorded_at.
static void flushBacklog(const char* via) {
  if (backlog.empty()) return;
  if (!timeValid()) {
    // Without a clock the readings would be stored at the wrong time: wait for NTP,
    // but not forever.
    if (wifiState == WifiState::Connected && millis() - wifiUpSinceMs > 60000) {
      LOG("no NTP time: dropped %u buffered readings", (unsigned)backlog.size());
      backlog.clear();
    }
    return;
  }
  char body[384];
  for (int i = 0; i < FLUSH_PER_CYCLE && !backlog.empty(); i++) {
    esp_task_wdt_reset();
    const Reading& r = backlog.front();
    int64_t age = (int64_t)time(nullptr) - epochAt(time(nullptr), millis(), r.takenMs);
    if (age > 23 * 3600) { backlog.pop(); continue; }  // server rejects > 24 h
    size_t len = payloadFor(r, true, via, body, sizeof body);
    if (usbActive()) {
      Serial.printf("NAMAA:%.*s\n", (int)len, body);
      backlog.pop();
      continue;
    }
    String resp;
    int status = httpPost(body, len, resp);
    if (status == 200 || status == 201 || status == 422) {
      if (status == 422) LOG("buffered reading rejected (422): dropped");
      backlog.pop();
    } else {
      LOG("buffered send failed (%d): retry later", status);
      return;
    }
  }
  if (!backlog.empty()) LOG("%u buffered readings left", (unsigned)backlog.size());
  else LOG("offline buffer sent");
}

static void report(uint32_t now) {
  Reading r = takeReading(now);
  char body[384];
  nextReportMs = now + intervalS * 1000UL;

  if (usbActive()) {
    size_t len = payloadFor(r, false, "usb", body, sizeof body);
    Serial.printf("NAMAA:%.*s\n", (int)len, body);
    usbAwaiting = true;
    usbAwaitUntil = now + USB_REPLY_TIMEOUT_MS;
    usbPending = r;
    return;
  }
  if (wifiState != WifiState::Connected) {
    reportFailed(r, true);
    nextReportMs = now + intervalS * 1000UL;
    return;
  }
  size_t len = payloadFor(r, false, "wifi", body, sizeof body);
  String resp;
  int status = httpPost(body, len, resp);
  if (status == 200 || status == 201) {
    applyReply(resp.c_str(), resp.length(), now);
    LOG("posted (%d), next in %lus", status, (unsigned long)intervalS);
    flushBacklog("wifi");
  } else {
    const char* why = status <= 0 ? "no connection (wrong host/port or firewall?)"
                    : status == 401 ? "401 wrong token"
                    : status == 403 ? "403 node id does not match the token"
                    : status == 422 ? "422 payload rejected"
                    : status == 429 ? "429 too many requests"
                    : "server error";
    LOG("post failed: %d %s", status, why);
    // Keep the reading only if the server could take it later.
    bool keep = status <= 0 || status == 429 || status >= 500;
    reportFailed(r, keep);
  }
  nextReportMs = now + intervalS * 1000UL;
}

// ---------------------------------------------------------------------------
// Serial commands
// ---------------------------------------------------------------------------

static void printHelp() {
  Serial.println(
      "Commands:\n"
      "  show                         settings and status (no secrets)\n"
      "  set host <ip>                server address (laptop)\n"
      "  set port <n>                 server port (default 8000)\n"
      "  set wifi1 <ssid> <pass>      network 1 (\"quote names with spaces\")\n"
      "  set wifi2 <ssid> <pass>      network 2 (backup)\n"
      "  set node <id> <token>        node id and device token\n"
      "  set cal <dry> <wet>          local soil calibration (LCD % and offline rule)\n"
      "  mode wifi | mode usb         report over Wi-Fi or through the USB bridge\n"
      "  cal                          print raw soil / water level every second; `exit` stops\n"
      "  reboot                       restart\n"
      "  factory                      forget saved settings (back to secrets.h)");
}

static void printShow() {
  char tp[12];
  tokenPrefix(cfg.token.c_str(), tp, sizeof tp);
  Serial.printf("fw %s, board %s\n", FW_VERSION, BOARD_NAME);
  Serial.printf("mode %s%s\n", usbActive() ? "usb" : "wifi", usbAuto ? " (auto, Wi-Fi failed)" : "");
  Serial.printf("wifi1 \"%s\"  wifi2 \"%s\"\n", cfg.ssid1.c_str(), cfg.ssid2.c_str());
  if (wifiState == WifiState::Connected)
    Serial.printf("wifi connected to \"%s\" ip %s rssi %d dBm\n", netSsid(wifiNet).c_str(),
                  WiFi.localIP().toString().c_str(), WiFi.RSSI());
  else
    Serial.println("wifi not connected");
  Serial.printf("server http://%s:%u%s\n", cfg.host.c_str(), cfg.port, kApiPath);
  Serial.printf("node %ld token %s\n", (long)cfg.nodeId, tp);
  Serial.printf("time %s, interval %lus, failed posts %lu, buffered %u\n",
                timeValid() ? "synced" : "not synced", (unsigned long)intervalS,
                (unsigned long)guard.failures(), (unsigned)backlog.size());
  const LocalRule& lr = guard.rule();
  if (lr.valid)
    Serial.printf("local rule min %.0f%% target %.0f%% max run %lus\n", lr.minPct, lr.targetPct,
                  (unsigned long)lr.maxRunS);
  else
    Serial.println("local rule: none yet (no offline watering)");
  Serial.printf("soil cal dry %ld wet %ld, last moisture %s\n", (long)cfg.dryRaw, (long)cfg.wetRaw,
                isnan(lastPct) ? "--" : String(lastPct, 0).c_str());
  Serial.printf("features soil=%d dht=%d(DHT%d) relay=%d(active %s) flow=%d(%.0f p/L) level=%d lcd=%d\n",
                FEATURE_SOIL, FEATURE_DHT, DHT_TYPE, FEATURE_RELAY, RELAY_ACTIVE_HIGH ? "HIGH" : "LOW",
                FEATURE_FLOW, (double)FLOW_PULSES_PER_LITRE, FEATURE_WATER_LEVEL, FEATURE_LCD);
  Serial.printf("pins soil=%d level=%d dht=%d flow=%d relay=%d sda=%d scl=%d\n", PIN_SOIL,
                PIN_WATER_LEVEL, PIN_DHT, PIN_FLOW, PIN_RELAY, PIN_I2C_SDA, PIN_I2C_SCL);
  Serial.printf("pump %s%s\n", relayOn ? "ON" : "OFF", guard.localRunActive() ? " (local rule)" : "");
}

static void putString(const char* key, const char* value) {
  prefs.begin("namaa", false);
  prefs.putString(key, value);
  prefs.end();
}

static void handleLine(const char* line, uint32_t now) {
  Command c;
  parseCommand(line, c);
  switch (c.type) {
    case CmdType::Empty: break;
    case CmdType::UsbReply:
      applyReply(c.json, strlen(c.json), now);
      if (usbAwaiting) {
        usbAwaiting = false;
        nextReportMs = now + intervalS * 1000UL;  // interval counted from the reading
        flushBacklog("usb");
      }
      break;
    case CmdType::Help: printHelp(); break;
    case CmdType::Show: printShow(); break;
    case CmdType::SetHost:
      cfg.host = c.a; putString("host", c.a);
      Serial.printf("host saved: %s\n", c.a);
      break;
    case CmdType::SetPort:
      cfg.port = (uint16_t)c.n1;
      prefs.begin("namaa", false); prefs.putUShort("port", cfg.port); prefs.end();
      Serial.printf("port saved: %u\n", cfg.port);
      break;
    case CmdType::SetWifi1:
    case CmdType::SetWifi2: {
      bool one = c.type == CmdType::SetWifi1;
      (one ? cfg.ssid1 : cfg.ssid2) = c.a;
      (one ? cfg.pass1 : cfg.pass2) = c.b;
      putString(one ? "ssid1" : "ssid2", c.a);
      putString(one ? "pass1" : "pass2", c.b);
      Serial.printf("wifi%d saved: \"%s\" (reconnecting)\n", one ? 1 : 2, c.a);
      wifiState = WifiState::Idle;
      wifiFailRounds = 0;
      wifiNextMs = now;
      break;
    }
    case CmdType::SetNode: {
      cfg.nodeId = c.n1;
      cfg.token = c.b;
      prefs.begin("namaa", false); prefs.putInt("node", c.n1); prefs.putString("token", c.b); prefs.end();
      char tp[12];
      tokenPrefix(c.b, tp, sizeof tp);
      Serial.printf("node saved: id %ld token %s\n", (long)c.n1, tp);
      break;
    }
    case CmdType::SetCal:
      cfg.dryRaw = c.n1; cfg.wetRaw = c.n2;
      prefs.begin("namaa", false); prefs.putInt("dry", c.n1); prefs.putInt("wet", c.n2); prefs.end();
      Serial.printf("soil cal saved: dry %ld wet %ld\n", (long)c.n1, (long)c.n2);
      break;
    case CmdType::ModeWifi:
      cfg.usbMode = false; usbAuto = false; usbAwaiting = false;
      prefs.begin("namaa", false); prefs.putBool("usb", false); prefs.end();
      wifiState = WifiState::Idle; wifiFailRounds = 0; wifiNextMs = now;
      Serial.println("mode wifi saved");
      break;
    case CmdType::ModeUsb:
      cfg.usbMode = true;
      prefs.begin("namaa", false); prefs.putBool("usb", true); prefs.end();
      WiFi.disconnect();
      wifiState = WifiState::Idle;
      Serial.println("mode usb saved: readings print as NAMAA:{json}, replies read as NAMAA-REPLY:{json}");
      break;
    case CmdType::Cal:
      calMode = true; calNextMs = now;
      Serial.println("calibration: raw values every second, type `exit` to stop");
      break;
    case CmdType::Exit:
      if (calMode) { calMode = false; Serial.println("calibration stopped"); }
      break;
    case CmdType::Reboot:
      Serial.println("rebooting");
      setRelay(false);
      delay(200);
      ESP.restart();
      break;
    case CmdType::Factory:
      prefs.begin("namaa", false); prefs.clear(); prefs.end();
      Serial.println("saved settings cleared: back to secrets.h defaults, rebooting");
      setRelay(false);
      delay(200);
      ESP.restart();
      break;
    case CmdType::Error: Serial.println(c.error); break;
    case CmdType::Unknown: Serial.println("unknown command (type `help`)"); break;
  }
}

static void serialLoop(uint32_t now) {
  static char buf[512];
  static size_t len = 0;
  static bool overflow = false;
  while (Serial.available() > 0) {
    char ch = (char)Serial.read();
    if (ch == '\r') continue;
    if (ch == '\n') {
      buf[len] = 0;
      if (overflow) Serial.println("line too long: ignored");
      else handleLine(buf, now);
      len = 0;
      overflow = false;
    } else if (len + 1 < sizeof buf) {
      buf[len++] = ch;
    } else {
      overflow = true;
    }
  }
}

// ---------------------------------------------------------------------------
// Arduino entry points
// ---------------------------------------------------------------------------

void setup() {
#if FEATURE_RELAY
  digitalWrite(PIN_RELAY, RELAY_ACTIVE_HIGH ? LOW : HIGH);  // off before the pin drives
  pinMode(PIN_RELAY, OUTPUT);
#endif
  Serial.begin(115200);
  delay(300);
  LOG("NAMAA sensor node %s on %s", FW_VERSION, BOARD_NAME);

#if ESP_IDF_VERSION_MAJOR >= 5
  esp_task_wdt_config_t wdt = {};
  wdt.timeout_ms = WDT_TIMEOUT_S * 1000;
  wdt.trigger_panic = true;
  esp_task_wdt_reconfigure(&wdt);
#else
  esp_task_wdt_init(WDT_TIMEOUT_S, true);
#endif
  esp_task_wdt_add(NULL);

  loadSettings();
  guard.setRule(loadRule());

  analogReadResolution(12);
#if FEATURE_DHT
  dht.begin();
#endif
#if FEATURE_FLOW
  pinMode(PIN_FLOW, INPUT);
  attachInterrupt(digitalPinToInterrupt(PIN_FLOW), onFlowPulse, FALLING);
  flowLastMs = millis();
#endif
  lcdInit();

  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(false);  // reconnects are ours (two networks, backoff)
  WiFi.persistent(false);
  printShow();
  Serial.println("type `help` for commands");
  nextReportMs = millis() + 3000;  // first report after the sensors settle
}

void loop() {
  uint32_t now = millis();
  esp_task_wdt_reset();
  serialLoop(now);
  wifiLoop(now);

  if (calMode) {
    if ((int32_t)(now - calNextMs) >= 0) {
      calNextMs = now + 1000;
      int32_t s = readSoilRaw(), l = readLevelRaw();
      float pct = moisturePct(s, cfg.dryRaw, cfg.wetRaw);
      Serial.printf("cal soil_raw=%ld soil_pct=%s level_raw=%ld\n", (long)s,
                    isnan(pct) ? "--" : String(pct, 0).c_str(), (long)l);
    }
  } else {
    if (usbAwaiting && (int32_t)(now - usbAwaitUntil) >= 0) {
      usbAwaiting = false;
      LOG("no NAMAA-REPLY from the USB bridge");
      reportFailed(usbPending, true);
      nextReportMs = now + intervalS * 1000UL;
    }
    if (!usbAwaiting && (int32_t)(now - nextReportMs) >= 0) report(millis());
  }

#if FEATURE_RELAY
  // While a local run is going, follow the soil closely so it stops at target.
  static uint32_t soilNextMs = 0;
  if (guard.localRunActive() && (int32_t)(now - soilNextMs) >= 0) {
    soilNextMs = now + 2000;
    lastPct = moisturePct(readSoilRaw(), cfg.dryRaw, cfg.wetRaw);
  }
  setRelay(guard.tick(millis(), lastPct));  // includes the max-run safety cut-off
#endif

  lcdUpdate(now);
  delay(10);
}
