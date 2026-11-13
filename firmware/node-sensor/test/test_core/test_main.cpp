// Host unit tests for lib/namaa_core: pio test -e native
#include <string.h>
#include <unity.h>

#include "namaa_core.h"

using namespace namaa;

void setUp() {}
void tearDown() {}

// ---- calibration ----------------------------------------------------------------

void test_moisture_pct_matches_server_formula() {
  TEST_ASSERT_FLOAT_WITHIN(0.01f, 0.0f, moisturePct(3000, 3000, 1300));
  TEST_ASSERT_FLOAT_WITHIN(0.01f, 100.0f, moisturePct(1300, 3000, 1300));
  TEST_ASSERT_FLOAT_WITHIN(0.01f, 50.0f, moisturePct(2150, 3000, 1300));
  TEST_ASSERT_FLOAT_WITHIN(0.01f, 0.0f, moisturePct(4095, 3000, 1300));    // clamped
  TEST_ASSERT_FLOAT_WITHIN(0.01f, 100.0f, moisturePct(0, 3000, 1300));     // clamped
  TEST_ASSERT_TRUE(isnan(moisturePct(-1, 3000, 1300)));                     // missing
  TEST_ASSERT_TRUE(isnan(moisturePct(2000, 1300, 3000)));                   // bad calibration
}

void test_flow_lpm() {
  TEST_ASSERT_FLOAT_WITHIN(0.001f, 1.0f, flowLpm(5880, 5880.0f, 60000));
  TEST_ASSERT_FLOAT_WITHIN(0.001f, 2.0f, flowLpm(450, 450.0f, 30000));
  TEST_ASSERT_EQUAL_FLOAT(0.0f, flowLpm(100, 5880.0f, 0));
}

void test_average_and_backoff() {
  int32_t s[4] = {100, 101, 102, 103};
  TEST_ASSERT_EQUAL_INT32(102, averageRaw(s, 4));  // 101.5 rounds up
  TEST_ASSERT_EQUAL_INT32(-1, averageRaw(s, 0));
  TEST_ASSERT_EQUAL_UINT32(5000, backoffMs(0));
  TEST_ASSERT_EQUAL_UINT32(40000, backoffMs(3));
  TEST_ASSERT_EQUAL_UINT32(60000, backoffMs(4));
  TEST_ASSERT_EQUAL_UINT32(60000, backoffMs(100));
}

// ---- time ---------------------------------------------------------------------------

void test_iso8601_and_epoch_at() {
  char buf[24];
  TEST_ASSERT_TRUE(formatIso8601(0, buf, sizeof buf));
  TEST_ASSERT_EQUAL_STRING("1970-01-01T00:00:00Z", buf);
  TEST_ASSERT_TRUE(formatIso8601(1794312000, buf, sizeof buf));  // 2026-11-10 12:00:00 UTC
  TEST_ASSERT_EQUAL_STRING("2026-11-10T12:00:00Z", buf);
  TEST_ASSERT_TRUE(formatIso8601(1709210096, buf, sizeof buf));  // leap day
  TEST_ASSERT_EQUAL_STRING("2024-02-29T12:34:56Z", buf);
  TEST_ASSERT_FALSE(formatIso8601(0, buf, 10));

  TEST_ASSERT_EQUAL_INT64(1000 - 90, epochAt(1000, 100000, 10000));
  // millis() wrapped between the reading and now.
  TEST_ASSERT_EQUAL_INT64(1000 - 2, epochAt(1000, 1000, 0xFFFFFFFFu - 999));
}

// ---- payload ------------------------------------------------------------------------

void test_payload_full_and_nulls() {
  Reading r;
  r.moisture = 2850;
  r.temperature = 31.04f;
  r.humidity = NAN;  // failed DHT read
  r.flow = 0.8f;
  r.pumpOn = 1;
  PayloadMeta m;
  m.nodeId = 7;
  m.hasRssi = true;
  m.rssi = -61;
  m.uptimeS = 1234;
  m.fwVersion = "namaa-sensor-4.0.0";
  m.via = "wifi";
  char out[384];
  size_t n = buildPayload(r, m, out, sizeof out);
  TEST_ASSERT_TRUE(n > 0);
  TEST_ASSERT_EQUAL_STRING(
      "{\"node_id\":7,\"moisture\":2850,\"flow\":0.8,\"temperature\":31,\"humidity\":null,"
      "\"pump_on\":true,\"water_level\":null,\"rssi\":-61,\"uptime_s\":1234,"
      "\"fw_version\":\"namaa-sensor-4.0.0\",\"via\":\"wifi\"}",
      out);
}

void test_payload_buffered_has_recorded_at_and_no_pump() {
  Reading r;  // everything missing
  PayloadMeta m;
  m.nodeId = 2;
  m.recordedAt = 1794312000;
  char out[384];
  TEST_ASSERT_TRUE(buildPayload(r, m, out, sizeof out) > 0);
  TEST_ASSERT_NOT_NULL(strstr(out, "\"recorded_at\":\"2026-11-10T12:00:00Z\""));
  TEST_ASSERT_NOT_NULL(strstr(out, "\"pump_on\":null"));
  TEST_ASSERT_NOT_NULL(strstr(out, "\"moisture\":null"));
  TEST_ASSERT_NULL(strstr(out, "rssi"));
  TEST_ASSERT_NULL(strstr(out, "via"));
}

void test_payload_too_small() {
  Reading r;
  PayloadMeta m;
  char out[16];
  TEST_ASSERT_EQUAL_UINT32(0, buildPayload(r, m, out, sizeof out));
}

// ---- reply ----------------------------------------------------------------------------

void test_parse_reply() {
  Reply rep;
  const char* j = "{\"pump\":true,\"interval_s\":5,\"local_rule\":{\"min\":30,\"target\":45,\"max_run_s\":120}}";
  TEST_ASSERT_TRUE(parseReply(j, strlen(j), rep));
  TEST_ASSERT_TRUE(rep.pump);
  TEST_ASSERT_EQUAL_UINT32(5, rep.intervalS);
  TEST_ASSERT_TRUE(rep.hasRule);
  TEST_ASSERT_EQUAL_FLOAT(30, rep.rule.minPct);
  TEST_ASSERT_EQUAL_FLOAT(45, rep.rule.targetPct);
  TEST_ASSERT_EQUAL_UINT32(120, rep.rule.maxRunS);

  const char* old = "{\"pump\":false,\"interval_s\":30}";  // older server: no local_rule
  TEST_ASSERT_TRUE(parseReply(old, strlen(old), rep));
  TEST_ASSERT_FALSE(rep.pump);
  TEST_ASSERT_FALSE(rep.hasRule);

  const char* bad = "{\"pump\":false,\"local_rule\":{\"min\":50,\"target\":40}}";  // min >= target
  TEST_ASSERT_TRUE(parseReply(bad, strlen(bad), rep));
  TEST_ASSERT_FALSE(rep.hasRule);

  const char* clamp = "{\"pump\":false,\"interval_s\":0}";
  TEST_ASSERT_TRUE(parseReply(clamp, strlen(clamp), rep));
  TEST_ASSERT_EQUAL_UINT32(2, rep.intervalS);

  TEST_ASSERT_FALSE(parseReply("{\"interval_s\":30}", 17, rep));  // no pump
  TEST_ASSERT_FALSE(parseReply("not json", 8, rep));
}

// ---- pump guard ---------------------------------------------------------------------

static LocalRule rule(float mn, float tg, uint32_t maxRun) {
  LocalRule r;
  r.valid = true;
  r.minPct = mn;
  r.targetPct = tg;
  r.maxRunS = maxRun;
  return r;
}

void test_guard_follows_server_and_safety_cutoff() {
  PumpGuard g;
  TEST_ASSERT_FALSE(g.tick(0, 10));          // no reply yet: off, even when dry
  g.onReply(true, 1000);
  TEST_ASSERT_TRUE(g.tick(2000, 10));
  TEST_ASSERT_TRUE(g.tick(1000 + 179000, 10));
  TEST_ASSERT_FALSE(g.tick(1000 + 180000, 10));  // default 180 s without fresh reply
  g.onReply(true, 200000);                    // fresh reply turns it back on
  TEST_ASSERT_TRUE(g.tick(200001, 10));
  g.onReply(false, 201000);
  TEST_ASSERT_FALSE(g.tick(201001, 10));
}

void test_guard_rule_max_run_used_for_safety() {
  PumpGuard g;
  g.setRule(rule(30, 45, 60));
  g.onReply(true, 0);
  TEST_ASSERT_TRUE(g.tick(59999, 50));
  TEST_ASSERT_FALSE(g.tick(60000, 50));
}

void test_guard_no_local_watering_while_online_or_without_rule() {
  PumpGuard g;
  g.onReply(false, 0);
  g.onPostFailed();
  g.onPostFailed();
  TEST_ASSERT_FALSE(g.tick(1000, 5));  // only 2 failures: still online
  g.onPostFailed();
  TEST_ASSERT_TRUE(g.offline());
  TEST_ASSERT_FALSE(g.tick(2000, 5));  // offline but no rule: never on
}

void test_guard_offline_local_run_stops_at_target_then_cools_down() {
  PumpGuard g;
  g.setRule(rule(30, 45, 180));
  g.onReply(false, 0);
  for (int i = 0; i < 3; i++) g.onPostFailed();
  TEST_ASSERT_FALSE(g.tick(1000, 35));       // above min: off
  TEST_ASSERT_FALSE(g.tick(1500, NAN));      // unknown moisture: off
  TEST_ASSERT_TRUE(g.tick(2000, 25));        // below min: local run
  TEST_ASSERT_TRUE(g.localRunActive());
  TEST_ASSERT_TRUE(g.tick(10000, 40));       // still below target
  TEST_ASSERT_FALSE(g.tick(12000, 45));      // target reached
  TEST_ASSERT_FALSE(g.tick(13000, 20));      // cooldown
  TEST_ASSERT_FALSE(g.tick(12000 + 599999, 20));
  TEST_ASSERT_TRUE(g.tick(12000 + 600000, 20));  // cooldown over
}

void test_guard_offline_local_run_stops_at_max_run() {
  PumpGuard g;
  g.setRule(rule(30, 45, 120));
  for (int i = 0; i < 3; i++) g.onPostFailed();
  TEST_ASSERT_TRUE(g.tick(0, 10));
  TEST_ASSERT_TRUE(g.tick(119999, 10));
  TEST_ASSERT_FALSE(g.tick(120000, 10));
  TEST_ASSERT_FALSE(g.tick(130000, 10));  // cooling down
}

void test_guard_server_reply_ends_local_run() {
  PumpGuard g;
  g.setRule(rule(30, 45, 180));
  for (int i = 0; i < 3; i++) g.onPostFailed();
  TEST_ASSERT_TRUE(g.tick(0, 10));
  g.onReply(false, 5000);
  TEST_ASSERT_FALSE(g.offline());
  TEST_ASSERT_FALSE(g.tick(5001, 10));
  TEST_ASSERT_FALSE(g.localRunActive());
}

void test_guard_invalid_rule_ignored() {
  PumpGuard g;
  LocalRule r;  // valid = false
  r.minPct = 99;
  r.targetPct = 100;
  g.setRule(r);
  TEST_ASSERT_FALSE(g.rule().valid);
}

// ---- relay level and stand pump -------------------------------------------------------

void test_relay_level_mapping() {
  // Active-low module (default on the stand): ON = LOW, OFF = HIGH.
  TEST_ASSERT_FALSE(relayLevelHigh(true, false));
  TEST_ASSERT_TRUE(relayLevelHigh(false, false));
  // Active-high module: ON = HIGH, OFF = LOW.
  TEST_ASSERT_TRUE(relayLevelHigh(true, true));
  TEST_ASSERT_FALSE(relayLevelHigh(false, true));
}

void test_parse_reply_pump_max_and_stand_reply() {
  Reply rep;
  const char* j = "{\"pump\":true,\"interval_s\":2,\"pump_max_s\":30}";
  TEST_ASSERT_TRUE(parseReply(j, strlen(j), rep));
  TEST_ASSERT_TRUE(rep.pump);
  TEST_ASSERT_EQUAL_UINT32(2, rep.intervalS);
  TEST_ASSERT_EQUAL_UINT32(30, rep.pumpMaxS);
  TEST_ASSERT_FALSE(rep.hasRule);  // stand replies carry no local_rule

  const char* none = "{\"pump\":false}";
  TEST_ASSERT_TRUE(parseReply(none, strlen(none), rep));
  TEST_ASSERT_EQUAL_UINT32(0, rep.pumpMaxS);

  const char* big = "{\"pump\":false,\"pump_max_s\":99999}";
  TEST_ASSERT_TRUE(parseReply(big, strlen(big), rep));
  TEST_ASSERT_EQUAL_UINT32(600, rep.pumpMaxS);
  const char* zero = "{\"pump\":false,\"pump_max_s\":0}";
  TEST_ASSERT_TRUE(parseReply(zero, strlen(zero), rep));
  TEST_ASSERT_EQUAL_UINT32(1, rep.pumpMaxS);
}

void test_stand_pump_off_on_boot_until_server_said_off() {
  StandPump p;
  TEST_ASSERT_FALSE(p.tick(0));            // boot: off, no reply yet
  p.onReply(true, 0, 1000);                // stale ON from before the reboot
  TEST_ASSERT_FALSE(p.tick(1001));         // still off: needs a fresh OFF -> ON
  TEST_ASSERT_TRUE(p.locked());
  p.onReply(false, 0, 3000);
  TEST_ASSERT_FALSE(p.tick(3001));
  p.onReply(true, 0, 5000);                // operator presses ON
  TEST_ASSERT_TRUE(p.tick(5001));
  p.onReply(false, 0, 7000);               // operator presses OFF
  TEST_ASSERT_FALSE(p.tick(7001));
}

void test_stand_pump_hard_cap_even_if_server_says_on() {
  StandPump p;  // default cap 30 s
  p.onReply(false, 0, 0);
  p.onReply(true, 0, 1000);
  TEST_ASSERT_TRUE(p.tick(1000));
  TEST_ASSERT_EQUAL_UINT32(30, p.remainingS(1000));
  for (uint32_t t = 3000; t < 31000; t += 2000) {  // server keeps saying ON every 2 s
    p.onReply(true, 0, t);
    TEST_ASSERT_TRUE(p.tick(t));
  }
  p.onReply(true, 0, 30999);
  TEST_ASSERT_TRUE(p.tick(30999));
  TEST_ASSERT_FALSE(p.tick(31000));        // 30 s run: cut off
  p.onReply(true, 0, 33000);
  TEST_ASSERT_FALSE(p.tick(33000));        // still ON on the server: stays off
  p.onReply(false, 0, 35000);
  p.onReply(true, 0, 37000);
  TEST_ASSERT_TRUE(p.tick(37000));         // new run after OFF -> ON
}

void test_stand_pump_max_from_server() {
  StandPump p;
  p.onReply(false, 5, 0);
  TEST_ASSERT_EQUAL_UINT32(5, p.maxRunS());
  p.onReply(true, 5, 100);
  TEST_ASSERT_TRUE(p.tick(100));
  p.onReply(true, 5, 2100);
  TEST_ASSERT_TRUE(p.tick(5099));
  p.onReply(true, 5, 4100);
  TEST_ASSERT_FALSE(p.tick(5100));
}

void test_stand_pump_off_without_check_in_for_10s() {
  StandPump p;
  p.onReply(false, 0, 0);
  p.onReply(true, 0, 1000);
  TEST_ASSERT_TRUE(p.tick(1000));
  TEST_ASSERT_TRUE(p.linkOk(10999));
  TEST_ASSERT_TRUE(p.tick(10999));
  TEST_ASSERT_FALSE(p.tick(11000));        // 10 s without a reply: off
  TEST_ASSERT_FALSE(p.linkOk(11000));
  p.onReply(true, 0, 12000);               // link back, target still ON
  TEST_ASSERT_FALSE(p.tick(12000));        // no silent restart
  p.onReply(false, 0, 14000);
  p.onReply(true, 0, 16000);
  TEST_ASSERT_TRUE(p.tick(16000));
}

void test_stand_pump_millis_wrap() {
  StandPump p;
  uint32_t t0 = 0xFFFFFFFFu - 5000;
  p.onReply(false, 0, t0);
  p.onReply(true, 0, t0 + 1000);
  TEST_ASSERT_TRUE(p.tick(t0 + 1000));
  p.onReply(true, 0, t0 + 9000);            // wraps past zero
  TEST_ASSERT_TRUE(p.tick(t0 + 10000));
  TEST_ASSERT_TRUE(p.linkOk(t0 + 18999));
}

void test_status_led_pattern() {
  TEST_ASSERT_TRUE(statusLedOn(true, false, 0));
  TEST_ASSERT_TRUE(statusLedOn(true, false, 777));
  TEST_ASSERT_TRUE(statusLedOn(false, false, 0));
  TEST_ASSERT_FALSE(statusLedOn(false, false, 250));
  TEST_ASSERT_TRUE(statusLedOn(true, true, 499));
  TEST_ASSERT_FALSE(statusLedOn(true, true, 500));
}

// ---- ring buffer --------------------------------------------------------------------

void test_ring_buffer_fifo_and_overwrite() {
  RingBuffer<int, 3> b;
  TEST_ASSERT_TRUE(b.empty());
  TEST_ASSERT_FALSE(b.push(1));
  TEST_ASSERT_FALSE(b.push(2));
  TEST_ASSERT_FALSE(b.push(3));
  TEST_ASSERT_TRUE(b.full());
  TEST_ASSERT_TRUE(b.push(4));  // drops 1
  TEST_ASSERT_EQUAL_INT(2, b.front()); b.pop();
  TEST_ASSERT_EQUAL_INT(3, b.front()); b.pop();
  TEST_ASSERT_EQUAL_INT(4, b.front()); b.pop();
  TEST_ASSERT_TRUE(b.empty());
  b.pop();  // safe on empty
  TEST_ASSERT_EQUAL_UINT32(0, b.size());
}

void test_ring_buffer_120_readings() {
  static RingBuffer<Reading, 180> b;
  for (uint32_t i = 0; i < 200; i++) {
    Reading r;
    r.takenMs = i;
    b.push(r);
  }
  TEST_ASSERT_EQUAL_UINT32(180, b.size());
  TEST_ASSERT_EQUAL_UINT32(20, b.front().takenMs);  // oldest kept first
}

// ---- serial commands ------------------------------------------------------------------

void test_parse_set_commands() {
  Command c;
  parseCommand("set host 192.168.137.1", c);
  TEST_ASSERT_EQUAL(CmdType::SetHost, c.type);
  TEST_ASSERT_EQUAL_STRING("192.168.137.1", c.a);

  parseCommand("SET PORT 8000", c);
  TEST_ASSERT_EQUAL(CmdType::SetPort, c.type);
  TEST_ASSERT_EQUAL_INT32(8000, c.n1);

  parseCommand("set port 70000", c);
  TEST_ASSERT_EQUAL(CmdType::Error, c.type);

  parseCommand("set wifi1 \"My Hotspot\" secret pass", c);  // too many args
  TEST_ASSERT_EQUAL(CmdType::Error, c.type);

  parseCommand("set wifi1 \"My Hotspot\" \"secret pass\"", c);
  TEST_ASSERT_EQUAL(CmdType::SetWifi1, c.type);
  TEST_ASSERT_EQUAL_STRING("My Hotspot", c.a);
  TEST_ASSERT_EQUAL_STRING("secret pass", c.b);

  parseCommand("set wifi2 OpenNet", c);  // open network, no password
  TEST_ASSERT_EQUAL(CmdType::SetWifi2, c.type);
  TEST_ASSERT_EQUAL_STRING("", c.b);

  parseCommand("set node 12 5|abcdefghij", c);
  TEST_ASSERT_EQUAL(CmdType::SetNode, c.type);
  TEST_ASSERT_EQUAL_INT32(12, c.n1);
  TEST_ASSERT_EQUAL_STRING("5|abcdefghij", c.b);

  parseCommand("set node x tok", c);
  TEST_ASSERT_EQUAL(CmdType::Error, c.type);

  parseCommand("set cal 3000 1300", c);
  TEST_ASSERT_EQUAL(CmdType::SetCal, c.type);
  parseCommand("set cal 1300 3000", c);  // dry must be > wet
  TEST_ASSERT_EQUAL(CmdType::Error, c.type);
}

void test_parse_simple_commands_and_reply() {
  Command c;
  parseCommand("", c);            TEST_ASSERT_EQUAL(CmdType::Empty, c.type);
  parseCommand("   ", c);         TEST_ASSERT_EQUAL(CmdType::Empty, c.type);
  parseCommand("show", c);        TEST_ASSERT_EQUAL(CmdType::Show, c.type);
  parseCommand("mode usb", c);    TEST_ASSERT_EQUAL(CmdType::ModeUsb, c.type);
  parseCommand("mode Wifi", c);   TEST_ASSERT_EQUAL(CmdType::ModeWifi, c.type);
  parseCommand("mode lora", c);   TEST_ASSERT_EQUAL(CmdType::Error, c.type);
  parseCommand("cal", c);         TEST_ASSERT_EQUAL(CmdType::Cal, c.type);
  parseCommand("exit", c);        TEST_ASSERT_EQUAL(CmdType::Exit, c.type);
  parseCommand("reboot", c);      TEST_ASSERT_EQUAL(CmdType::Reboot, c.type);
  parseCommand("factory", c);     TEST_ASSERT_EQUAL(CmdType::Factory, c.type);
  parseCommand("dance", c);       TEST_ASSERT_EQUAL(CmdType::Unknown, c.type);
  parseCommand("NAMAA-REPLY:{\"pump\":true}", c);
  TEST_ASSERT_EQUAL(CmdType::UsbReply, c.type);
  TEST_ASSERT_EQUAL_STRING("{\"pump\":true}", c.json);
}

void test_token_prefix_never_whole_token() {
  char out[12];
  tokenPrefix("5|abcdefghijklmnop", out, sizeof out);
  TEST_ASSERT_EQUAL_STRING("5|ab...", out);
  tokenPrefix("", out, sizeof out);
  TEST_ASSERT_EQUAL_STRING("(none)", out);
}

int main(int, char**) {
  UNITY_BEGIN();
  RUN_TEST(test_moisture_pct_matches_server_formula);
  RUN_TEST(test_flow_lpm);
  RUN_TEST(test_average_and_backoff);
  RUN_TEST(test_iso8601_and_epoch_at);
  RUN_TEST(test_payload_full_and_nulls);
  RUN_TEST(test_payload_buffered_has_recorded_at_and_no_pump);
  RUN_TEST(test_payload_too_small);
  RUN_TEST(test_parse_reply);
  RUN_TEST(test_guard_follows_server_and_safety_cutoff);
  RUN_TEST(test_guard_rule_max_run_used_for_safety);
  RUN_TEST(test_guard_no_local_watering_while_online_or_without_rule);
  RUN_TEST(test_guard_offline_local_run_stops_at_target_then_cools_down);
  RUN_TEST(test_guard_offline_local_run_stops_at_max_run);
  RUN_TEST(test_guard_server_reply_ends_local_run);
  RUN_TEST(test_guard_invalid_rule_ignored);
  RUN_TEST(test_relay_level_mapping);
  RUN_TEST(test_parse_reply_pump_max_and_stand_reply);
  RUN_TEST(test_stand_pump_off_on_boot_until_server_said_off);
  RUN_TEST(test_stand_pump_hard_cap_even_if_server_says_on);
  RUN_TEST(test_stand_pump_max_from_server);
  RUN_TEST(test_stand_pump_off_without_check_in_for_10s);
  RUN_TEST(test_stand_pump_millis_wrap);
  RUN_TEST(test_status_led_pattern);
  RUN_TEST(test_ring_buffer_fifo_and_overwrite);
  RUN_TEST(test_ring_buffer_120_readings);
  RUN_TEST(test_parse_set_commands);
  RUN_TEST(test_parse_simple_commands_and_reply);
  RUN_TEST(test_token_prefix_never_whole_token);
  return UNITY_END();
}
