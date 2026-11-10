#include "namaa_core.h"

#include <ArduinoJson.h>
#include <ctype.h>
#include <stdio.h>
#include <string.h>

namespace namaa {

// ---------------------------------------------------------------------------
// Calibration and unit conversion
// ---------------------------------------------------------------------------

float moisturePct(int32_t raw, int32_t dryRaw, int32_t wetRaw) {
  if (raw < 0 || dryRaw <= wetRaw) return NAN;
  float pct = (float)(dryRaw - raw) / (float)(dryRaw - wetRaw) * 100.0f;
  if (pct < 0) pct = 0;
  if (pct > 100) pct = 100;
  return pct;
}

float flowLpm(uint32_t pulses, float pulsesPerLitre, uint32_t elapsedMs) {
  if (elapsedMs == 0 || pulsesPerLitre <= 0) return 0;
  float litres = (float)pulses / pulsesPerLitre;
  return litres * 60000.0f / (float)elapsedMs;
}

int32_t averageRaw(const int32_t* samples, size_t n) {
  if (n == 0) return -1;
  int64_t sum = 0;
  for (size_t i = 0; i < n; i++) sum += samples[i];
  return (int32_t)((sum + (int64_t)(n / 2)) / (int64_t)n);
}

uint32_t backoffMs(uint32_t failedRounds) {
  if (failedRounds >= 4) return 60000;
  uint32_t ms = 5000UL << failedRounds;
  return ms > 60000 ? 60000 : ms;
}

// ---------------------------------------------------------------------------
// Time
// ---------------------------------------------------------------------------

// Howard Hinnant's civil_from_days: days since 1970-01-01 -> y/m/d.
static void civilFromDays(int64_t z, int64_t& y, unsigned& m, unsigned& d) {
  z += 719468;
  const int64_t era = (z >= 0 ? z : z - 146096) / 146097;
  const unsigned doe = (unsigned)(z - era * 146097);
  const unsigned yoe = (doe - doe / 1460 + doe / 36524 - doe / 146096) / 365;
  y = (int64_t)yoe + era * 400;
  const unsigned doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
  const unsigned mp = (5 * doy + 2) / 153;
  d = doy - (153 * mp + 2) / 5 + 1;
  m = mp < 10 ? mp + 3 : mp - 9;
  if (m <= 2) y += 1;
}

bool formatIso8601(int64_t epoch, char* out, size_t outSize) {
  if (outSize < 21) return false;
  int64_t days = epoch / 86400;
  int64_t secs = epoch % 86400;
  if (secs < 0) { secs += 86400; days -= 1; }
  int64_t y; unsigned m, d;
  civilFromDays(days, y, m, d);
  snprintf(out, outSize, "%04d-%02u-%02uT%02d:%02d:%02dZ", (int)y, m, d,
           (int)(secs / 3600), (int)((secs % 3600) / 60), (int)(secs % 60));
  return true;
}

int64_t epochAt(int64_t nowEpoch, uint32_t nowMs, uint32_t takenMs) {
  uint32_t ageMs = nowMs - takenMs;  // wraps correctly
  return nowEpoch - (int64_t)(ageMs / 1000);
}

// ---------------------------------------------------------------------------
// Payload
// ---------------------------------------------------------------------------

static float round1(float v) { return roundf(v * 10.0f) / 10.0f; }
static float round2(float v) { return roundf(v * 100.0f) / 100.0f; }

size_t buildPayload(const Reading& r, const PayloadMeta& m, char* out, size_t outSize) {
  JsonDocument doc;
  doc["node_id"] = m.nodeId;
  if (r.moisture >= 0) doc["moisture"] = r.moisture; else doc["moisture"] = nullptr;
  if (!isnan(r.flow)) doc["flow"] = round2(r.flow); else doc["flow"] = nullptr;
  if (!isnan(r.temperature)) doc["temperature"] = round1(r.temperature); else doc["temperature"] = nullptr;
  if (!isnan(r.humidity)) doc["humidity"] = round1(r.humidity); else doc["humidity"] = nullptr;
  if (r.pumpOn >= 0) doc["pump_on"] = r.pumpOn == 1; else doc["pump_on"] = nullptr;
  if (r.waterLevel >= 0) doc["water_level"] = r.waterLevel; else doc["water_level"] = nullptr;
  if (m.hasRssi) doc["rssi"] = m.rssi;
  doc["uptime_s"] = m.uptimeS;
  if (m.fwVersion) doc["fw_version"] = m.fwVersion;
  if (m.recordedAt > 0) {
    char ts[24];
    if (formatIso8601(m.recordedAt, ts, sizeof ts)) doc["recorded_at"] = ts;
  }
  if (m.via) doc["via"] = m.via;

  size_t need = measureJson(doc);
  if (need + 1 > outSize) return 0;
  return serializeJson(doc, out, outSize);
}

// ---------------------------------------------------------------------------
// Reply and local rule
// ---------------------------------------------------------------------------

bool parseReply(const char* json, size_t len, Reply& out) {
  out = Reply();
  JsonDocument doc;
  if (deserializeJson(doc, json, len)) return false;
  if (!doc["pump"].is<bool>()) return false;
  out.pump = doc["pump"].as<bool>();
  if (doc["interval_s"].is<int>()) {
    long iv = doc["interval_s"].as<long>();
    if (iv < 2) iv = 2;
    if (iv > 3600) iv = 3600;
    out.intervalS = (uint32_t)iv;
  }
  JsonVariantConst lr = doc["local_rule"];
  if (lr.is<JsonObjectConst>() && lr["min"].is<float>() && lr["target"].is<float>()) {
    float mn = lr["min"].as<float>();
    float tg = lr["target"].as<float>();
    long mr = lr["max_run_s"].is<int>() ? lr["max_run_s"].as<long>() : (long)PumpGuard::kDefaultMaxRunS;
    if (mn >= 0 && tg <= 100 && mn < tg && mr > 0) {
      if (mr > 3600) mr = 3600;
      out.hasRule = true;
      out.rule.valid = true;
      out.rule.minPct = mn;
      out.rule.targetPct = tg;
      out.rule.maxRunS = (uint32_t)mr;
    }
  }
  return true;
}

uint32_t PumpGuard::maxRunMs() const {
  uint32_t s = rule_.maxRunS ? rule_.maxRunS : kDefaultMaxRunS;
  return s * 1000UL;
}

void PumpGuard::onReply(bool pumpTarget, uint32_t nowMs) {
  serverTarget_ = pumpTarget;
  haveReply_ = true;
  lastReplyMs_ = nowMs;
  failures_ = 0;
  if (localRun_) {  // server is back: it decides, but a finished run still cools down
    localRun_ = false;
    cooling_ = true;
    cooldownStartMs_ = nowMs;
  }
}

void PumpGuard::onPostFailed() {
  if (failures_ < 0xFFFF) failures_++;
}

void PumpGuard::setRule(const LocalRule& rule) {
  if (rule.valid) rule_ = rule;
}

bool PumpGuard::tick(uint32_t nowMs, float pct) {
  if (cooling_ && nowMs - cooldownStartMs_ >= kCooldownMs) cooling_ = false;

  // Server target, with the safety cut-off.
  bool serverOn = haveReply_ && serverTarget_ && (nowMs - lastReplyMs_ < maxRunMs());

  if (localRun_) {
    bool stop = isnan(pct) || pct >= rule_.targetPct || nowMs - localStartMs_ >= maxRunMs();
    if (stop) {
      localRun_ = false;
      cooling_ = true;
      cooldownStartMs_ = nowMs;
    }
  } else if (offline() && rule_.valid && !cooling_ && !serverOn && !isnan(pct) &&
             pct < rule_.minPct) {
    localRun_ = true;
    localStartMs_ = nowMs;
  }
  return serverOn || localRun_;
}

// ---------------------------------------------------------------------------
// Serial commands
// ---------------------------------------------------------------------------

// Splits into up to maxTok tokens; "double quotes" group spaces. Writes into buf.
static size_t tokenize(const char* line, char* buf, size_t bufSize, char** tok, size_t maxTok) {
  size_t n = 0, w = 0;
  const char* p = line;
  while (*p && n < maxTok) {
    while (*p == ' ' || *p == '\t') p++;
    if (!*p) break;
    tok[n++] = buf + w;
    if (*p == '"') {
      p++;
      while (*p && *p != '"' && w + 1 < bufSize) buf[w++] = *p++;
      if (*p == '"') p++;
    } else {
      while (*p && *p != ' ' && *p != '\t' && w + 1 < bufSize) buf[w++] = *p++;
    }
    if (w < bufSize) buf[w++] = 0;
  }
  return n;
}

static bool ieq(const char* a, const char* b) {
  while (*a && *b) {
    if (tolower((unsigned char)*a) != tolower((unsigned char)*b)) return false;
    a++; b++;
  }
  return *a == *b;
}

static bool parseInt(const char* s, int32_t lo, int32_t hi, int32_t& out) {
  if (!s || !*s) return false;
  char* end = nullptr;
  long v = strtol(s, &end, 10);
  if (*end || v < lo || v > hi) return false;
  out = (int32_t)v;
  return true;
}

static bool copyArg(const char* src, char* dst, size_t dstSize) {
  size_t len = strlen(src);
  if (len >= dstSize) return false;
  memcpy(dst, src, len + 1);
  return true;
}

static void fail(Command& out, const char* usage) {
  out.type = CmdType::Error;
  out.error = usage;
}

void parseCommand(const char* line, Command& out) {
  out = Command();
  static const char kReply[] = "NAMAA-REPLY:";
  if (strncmp(line, kReply, sizeof kReply - 1) == 0) {
    out.type = CmdType::UsbReply;
    out.json = line + sizeof kReply - 1;
    return;
  }

  char buf[256];
  char* t[5] = {0};
  size_t n = tokenize(line, buf, sizeof buf, t, 5);
  if (n == 0) return;  // Empty

  if (ieq(t[0], "help") || ieq(t[0], "?")) { out.type = CmdType::Help; return; }
  if (ieq(t[0], "show")) { out.type = CmdType::Show; return; }
  if (ieq(t[0], "cal")) { out.type = CmdType::Cal; return; }
  if (ieq(t[0], "exit")) { out.type = CmdType::Exit; return; }
  if (ieq(t[0], "reboot")) { out.type = CmdType::Reboot; return; }
  if (ieq(t[0], "factory")) { out.type = CmdType::Factory; return; }

  if (ieq(t[0], "mode")) {
    if (n == 2 && ieq(t[1], "wifi")) { out.type = CmdType::ModeWifi; return; }
    if (n == 2 && ieq(t[1], "usb")) { out.type = CmdType::ModeUsb; return; }
    return fail(out, "usage: mode wifi|usb");
  }

  if (ieq(t[0], "set")) {
    if (n < 2) return fail(out, "usage: set host|port|wifi1|wifi2|node|cal ...");
    if (ieq(t[1], "host")) {
      if (n != 3 || !copyArg(t[2], out.a, sizeof out.a)) return fail(out, "usage: set host <ip-or-name>");
      out.type = CmdType::SetHost; return;
    }
    if (ieq(t[1], "port")) {
      if (n != 3 || !parseInt(t[2], 1, 65535, out.n1)) return fail(out, "usage: set port <1-65535>");
      out.type = CmdType::SetPort; return;
    }
    if (ieq(t[1], "wifi1") || ieq(t[1], "wifi2")) {
      // Password may be left out for an open network.
      if (n < 3 || n > 4 || !copyArg(t[2], out.a, 33) || (n == 4 && !copyArg(t[3], out.b, 64)))
        return fail(out, "usage: set wifi1|wifi2 <ssid> <password>  (quote names with spaces)");
      out.type = ieq(t[1], "wifi1") ? CmdType::SetWifi1 : CmdType::SetWifi2; return;
    }
    if (ieq(t[1], "node")) {
      if (n != 4 || !parseInt(t[2], 1, 2147483647, out.n1) || !copyArg(t[3], out.b, sizeof out.b))
        return fail(out, "usage: set node <id> <token>");
      out.type = CmdType::SetNode; return;
    }
    if (ieq(t[1], "cal")) {
      if (n != 4 || !parseInt(t[2], 0, 4095, out.n1) || !parseInt(t[3], 0, 4095, out.n2) || out.n1 <= out.n2)
        return fail(out, "usage: set cal <dry-raw> <wet-raw>  (dry > wet)");
      out.type = CmdType::SetCal; return;
    }
    return fail(out, "usage: set host|port|wifi1|wifi2|node|cal ...");
  }

  out.type = CmdType::Unknown;
}

void tokenPrefix(const char* token, char* out, size_t outSize) {
  if (outSize < 8) { if (outSize) out[0] = 0; return; }
  size_t len = token ? strlen(token) : 0;
  if (len == 0) { snprintf(out, outSize, "(none)"); return; }
  size_t k = len < 4 ? len : 4;
  memcpy(out, token, k);
  memcpy(out + k, "...", 4);
}

}  // namespace namaa
