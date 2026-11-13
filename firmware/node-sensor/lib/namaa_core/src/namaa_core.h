// Hardware-free logic for the NAMAA sensor node.
//
// Nothing here touches Arduino, Wi-Fi or GPIO: every function takes plain values
// (time in milliseconds is passed in), so it builds and runs on the host
// (`pio test -e native`) as well as on the ESP32.
#pragma once

#include <stddef.h>
#include <stdint.h>
#include <math.h>

namespace namaa {

// ---------------------------------------------------------------------------
// Calibration and unit conversion
// ---------------------------------------------------------------------------

// Soil moisture percent from a raw capacitive reading. Same formula as the server
// (docs: data-model, nodes): clamp((dry - raw) / (dry - wet) * 100, 0, 100).
// Capacitive probes read higher when dry, so dry > wet. Returns NAN when the raw
// value is missing (< 0) or the calibration is unusable (dry <= wet).
float moisturePct(int32_t raw, int32_t dryRaw, int32_t wetRaw);

// Flow in litres per minute from pulses counted over elapsedMs.
// YF-S401 is about 5880 pulses per litre, YF-S201 about 450. Returns 0 when
// elapsedMs or pulsesPerLitre is 0.
float flowLpm(uint32_t pulses, float pulsesPerLitre, uint32_t elapsedMs);

// Average of n raw ADC samples, rounded. Returns -1 for n == 0.
int32_t averageRaw(const int32_t* samples, size_t n);

// Wi-Fi reconnect backoff: 5 s, 10 s, 20 s, 40 s, then 60 s cap.
uint32_t backoffMs(uint32_t failedRounds);

// ---------------------------------------------------------------------------
// Time
// ---------------------------------------------------------------------------

// Formats a UTC Unix time as "YYYY-MM-DDTHH:MM:SSZ" (needs 21 bytes).
// Returns false if the buffer is too small.
bool formatIso8601(int64_t epoch, char* out, size_t outSize);

// Unix time at which a reading was taken, from the current Unix time, the current
// millis() and the millis() stamp of the reading. Unsigned subtraction handles the
// 49-day millis() wrap.
int64_t epochAt(int64_t nowEpoch, uint32_t nowMs, uint32_t takenMs);

// ---------------------------------------------------------------------------
// Readings and payload
// ---------------------------------------------------------------------------

// One sensor reading. "Missing" values (sensor disabled or read failed) are
// NAN for floats and -1 for integers / pumpOn, and are sent as JSON null.
struct Reading {
  int32_t moisture = -1;     // raw 0-4095
  float temperature = NAN;   // degrees C
  float humidity = NAN;      // % RH
  float flow = NAN;          // L/min
  int32_t waterLevel = -1;   // raw 0-4095
  int8_t pumpOn = -1;        // 1 on, 0 off, -1 no relay
  uint32_t takenMs = 0;      // millis() when it was read
};

// Metadata sent with a reading. Optional fields are left out when unknown.
struct PayloadMeta {
  int32_t nodeId = 0;
  bool hasRssi = false;
  int32_t rssi = 0;           // dBm
  uint32_t uptimeS = 0;
  const char* fwVersion = nullptr;  // max 32 chars server side
  int64_t recordedAt = 0;     // Unix time; 0 = leave out (server stamps "now")
  const char* via = nullptr;  // "wifi" | "usb"; nullptr = leave out
};

// Builds the POST /api/device/readings JSON body. Returns the length written,
// or 0 if it did not fit.
size_t buildPayload(const Reading& r, const PayloadMeta& m, char* out, size_t outSize);

// ---------------------------------------------------------------------------
// Server reply and local rule
// ---------------------------------------------------------------------------

struct LocalRule {
  bool valid = false;      // false until the server sent one (or it was loaded from NVS)
  float minPct = 0;        // start local watering below this moisture %
  float targetPct = 0;     // stop local watering at this moisture %
  uint32_t maxRunS = 180;  // longest pump run without a fresh server reply
};

struct Reply {
  bool pump = false;
  uint32_t intervalS = 0;  // 0 = not given
  uint32_t pumpMaxS = 0;   // 0 = not given (stand: board keeps its default cap)
  bool hasRule = false;
  LocalRule rule;
};

// Parses {"pump":bool,"interval_s":int,"pump_max_s":int,
//         "local_rule":{"min":..,"target":..,"max_run_s":..}}.
// interval_s is clamped to 2..3600, pump_max_s to 1..600. A malformed local_rule
// is ignored (hasRule stays false). Returns false if the JSON is invalid or
// "pump" is missing.
bool parseReply(const char* json, size_t len, Reply& out);

// ---------------------------------------------------------------------------
// Relay level
// ---------------------------------------------------------------------------

// GPIO level that puts the relay in the wanted state. activeHigh = true: IN=HIGH
// closes the relay; false (most cheap modules): IN=LOW closes it.
// Returns true for HIGH, false for LOW.
inline bool relayLevelHigh(bool on, bool activeHigh) { return on == activeHigh; }

// ---------------------------------------------------------------------------
// Demo stand pump (manual only, server decides, board enforces the safety)
// ---------------------------------------------------------------------------

// Relay decision for the demo stand. No local rule, no automatic watering:
//  - The relay is on only while the last server reply said pump = true AND that
//    reply is younger than linkTimeoutS (board side "no check-in -> OFF").
//  - One run never lasts longer than maxRunS (the reply's pump_max_s, else the
//    default), even if the server keeps saying on.
//  - After a cut-off (max run reached or link lost while on) and after boot the
//    pump stays off until the server has said pump = false once: a fresh ON
//    from the dashboard is needed to start a new run.
class StandPump {
 public:
  static const uint32_t kDefaultMaxRunS = 30;
  static const uint32_t kDefaultLinkTimeoutS = 10;
  static const uint32_t kMaxRunCapS = 600;

  explicit StandPump(uint32_t defaultMaxRunS = kDefaultMaxRunS,
                     uint32_t linkTimeoutS = kDefaultLinkTimeoutS);

  // A successful server reply. maxRunS = 0 keeps the current cap.
  void onReply(bool pumpTarget, uint32_t maxRunS, uint32_t nowMs);

  // Returns the relay state to apply now.
  bool tick(uint32_t nowMs);

  bool on() const { return on_; }
  bool linkOk(uint32_t nowMs) const;
  // True while waiting for pump = false before a new run may start.
  bool locked() const { return locked_; }
  uint32_t maxRunS() const { return maxRunMs_ / 1000UL; }
  // Seconds until the cap stops the current run (0 when off).
  uint32_t remainingS(uint32_t nowMs) const;

 private:
  uint32_t maxRunMs_;
  uint32_t linkMs_;
  bool haveReply_ = false;
  bool target_ = false;
  uint32_t lastReplyMs_ = 0;
  bool on_ = false;
  uint32_t runStartMs_ = 0;
  bool locked_ = true;  // OFF on boot until the server has said "off" once
};

// Status LED pattern: offline = fast blink (250 ms), online and pump off =
// steady on, pump on = slow blink (500 ms).
bool statusLedOn(bool linkOk, bool pumpOn, uint32_t nowMs);

// Decides the relay state. Pure state machine; the caller passes millis().
//
//  - Online: follows the server's last target.
//  - Safety, always: the pump never stays on longer than maxRunS after the last
//    reply that asked for it.
//  - Offline (>= kOfflineAfterFailures failed posts in a row) with a valid local
//    rule: moisture below min starts a local run that stops at target or after
//    maxRunS, followed by a 10 minute cooldown before the next local run.
//  - Any server reply ends a local run: the server decides again.
class PumpGuard {
 public:
  static const uint32_t kOfflineAfterFailures = 3;
  static const uint32_t kCooldownMs = 10UL * 60UL * 1000UL;
  static const uint32_t kDefaultMaxRunS = 180;

  void onReply(bool pumpTarget, uint32_t nowMs);
  void onPostFailed();
  void setRule(const LocalRule& rule);
  const LocalRule& rule() const { return rule_; }

  // Returns the relay state to apply now. moisturePct may be NAN (unknown).
  bool tick(uint32_t nowMs, float moisturePct);

  bool offline() const { return failures_ >= kOfflineAfterFailures; }
  bool localRunActive() const { return localRun_; }
  uint32_t failures() const { return failures_; }

 private:
  uint32_t maxRunMs() const;

  LocalRule rule_;
  bool serverTarget_ = false;
  bool haveReply_ = false;
  uint32_t lastReplyMs_ = 0;
  uint32_t failures_ = 0;
  bool localRun_ = false;
  uint32_t localStartMs_ = 0;
  bool cooling_ = false;
  uint32_t cooldownStartMs_ = 0;
};

// ---------------------------------------------------------------------------
// Ring buffer for offline readings
// ---------------------------------------------------------------------------

// Fixed-size FIFO; when full, push() drops the oldest item.
template <typename T, size_t N>
class RingBuffer {
 public:
  size_t size() const { return count_; }
  bool empty() const { return count_ == 0; }
  bool full() const { return count_ == N; }
  static size_t capacity() { return N; }

  // Returns true if an old item was dropped to make room.
  bool push(const T& item) {
    bool dropped = false;
    if (count_ == N) {
      head_ = (head_ + 1) % N;
      count_--;
      dropped = true;
    }
    items_[(head_ + count_) % N] = item;
    count_++;
    return dropped;
  }
  // Oldest item; only valid when !empty().
  const T& front() const { return items_[head_]; }
  void pop() {
    if (count_ == 0) return;
    head_ = (head_ + 1) % N;
    count_--;
  }
  void clear() { head_ = 0; count_ = 0; }

 private:
  T items_[N];
  size_t head_ = 0;
  size_t count_ = 0;
};

// ---------------------------------------------------------------------------
// Serial commands
// ---------------------------------------------------------------------------

enum class CmdType {
  Empty, Help, Show, SetHost, SetPort, SetWifi1, SetWifi2, SetNode, SetCal,
  ModeWifi, ModeUsb, Cal, Exit, Reboot, Factory, UsbReply, Error, Unknown
};

struct Command {
  CmdType type = CmdType::Empty;
  // Arguments (strings are copied, NUL-terminated).
  char a[64] = {0};   // host / ssid
  char b[128] = {0};  // wifi password / node token
  int32_t n1 = 0;     // port / node id / dry raw
  int32_t n2 = 0;     // wet raw
  const char* json = nullptr;  // UsbReply: points into the input line
  const char* error = nullptr; // Error: usage text
};

// Parses one serial line (without the trailing newline). Lines starting with
// "NAMAA-REPLY:" are USB-bridge replies. Arguments may be "double quoted" to
// hold spaces (Wi-Fi names often do). Commands are case-insensitive.
void parseCommand(const char* line, Command& out);

// Copies at most the first 4 characters of a token followed by "..." (never
// the whole token). Needs 8 bytes.
void tokenPrefix(const char* token, char* out, size_t outSize);

}  // namespace namaa
