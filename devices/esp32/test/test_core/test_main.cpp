// Host unit tests for the firmware logic: `pio test -e native`.
// Protocol fixtures are read from packages/protocol/examples (single source of truth).
#include <unity.h>

#include <fstream>
#include <sstream>
#include <string>

#include "Backoff.h"
#include "BootRecovery.h"
#include "Controller.h"
#include "EpochClock.h"
#include "Flasher.h"
#include "Protocol.h"
#include "SessionTimer.h"

using namespace bl;

static const char* SID = "01J9ZQ3K8M4N5P6Q7R8S9T0V1W";
static const char* SID2 = "01J9ZQ3K8M4N5P6Q7R8S9T0V2X";
static const int64_t T0 = 1790000000;

void setUp() {}
void tearDown() {}

static std::string fixture(const char* name) {
  for (const char* base : {"../../packages/protocol/examples/valid/", "../../../packages/protocol/examples/valid/"}) {
    std::ifstream f(std::string(base) + name);
    if (f) {
      std::stringstream ss;
      ss << f.rdbuf();
      return ss.str();
    }
  }
  TEST_FAIL_MESSAGE("protocol fixture not found");
  return "";
}

static Session makeSession(const char* id = SID, int64_t start = T0, int64_t end = T0 + 3600) {
  Session s;
  s.setId(id);
  s.startAt = start;
  s.endAt = end;
  return s;
}

static std::string startCmd(const char* cmdId, const char* sid, int64_t end, int64_t expires = T0 + 30, int channel = 1) {
  char buf[400];
  snprintf(buf, sizeof buf,
           R"({"commandId":"%s","type":"START_SESSION","expiresAt":%lld,"payload":{"channel":%d,"sessionId":"%s","startAt":%lld,"endAt":%lld,"warnBeforeSec":300,"flashCount":3}})",
           cmdId, (long long)expires, channel, sid, (long long)T0, (long long)end);
  return buf;
}

static std::string stopCmd(const char* cmdId, const char* sid, int channel = 1) {
  char buf[220];
  snprintf(buf, sizeof buf, R"({"commandId":"%s","type":"STOP_SESSION","expiresAt":%lld,"payload":{"channel":%d,"sessionId":"%s"}})", cmdId, (long long)(T0 + 30), channel, sid);
  return buf;
}

static Ack run(Controller& c, const std::string& json, int64_t serverNow, Effects& fx, int64_t now = T0) {
  JsonDocument d;
  TEST_ASSERT_FALSE(deserializeJson(d, json));
  return c.handle(d.as<JsonObjectConst>(), serverNow, now, 0, fx);
}

// ---- session timing -------------------------------------------------------

void test_light_is_on_until_end_and_off_exactly_at_end_without_network() {
  SessionTimer t;
  TEST_ASSERT_TRUE(t.start(makeSession(), T0, 43200) == SessionTimer::StartResult::Started);
  TEST_ASSERT_TRUE(t.tick(T0).relayOn);
  TEST_ASSERT_TRUE(t.tick(T0 + 3599).relayOn);
  SessionTimer::Tick end = t.tick(T0 + 3600);
  TEST_ASSERT_FALSE(end.relayOn);
  TEST_ASSERT_TRUE(end.ended);
  TEST_ASSERT_FALSE(t.active());
  TEST_ASSERT_FALSE(t.tick(T0 + 3601).relayOn);
}

void test_warning_fires_once_at_end_minus_warn_before() {
  SessionTimer t;
  t.start(makeSession(), T0, 43200);
  TEST_ASSERT_FALSE(t.tick(T0 + 3299).warnNow);
  TEST_ASSERT_EQUAL(static_cast<int>(Light::On), static_cast<int>(t.tick(T0 + 3299).light));
  SessionTimer::Tick w = t.tick(T0 + 3300);
  TEST_ASSERT_TRUE(w.warnNow);
  TEST_ASSERT_EQUAL(static_cast<int>(Light::Warning), static_cast<int>(w.light));
  TEST_ASSERT_FALSE(t.tick(T0 + 3301).warnNow);
}

void test_hard_cap_limits_any_session() {
  SessionTimer t;
  t.start(makeSession(SID, T0, T0 + 48 * 3600), T0, 12 * 3600);
  TEST_ASSERT_EQUAL_INT64(T0 + 12 * 3600, t.session().endAt);
  TEST_ASSERT_FALSE(t.tick(T0 + 12 * 3600).relayOn);
}

void test_invalid_or_already_ended_sessions_are_not_started() {
  SessionTimer t;
  TEST_ASSERT_TRUE(t.start(makeSession(SID, T0, T0), T0, 43200) == SessionTimer::StartResult::Invalid);
  TEST_ASSERT_TRUE(t.start(makeSession(SID, T0 - 7200, T0 - 3600), T0, 43200) == SessionTimer::StartResult::AlreadyEnded);
  TEST_ASSERT_FALSE(t.active());
}

void test_joining_late_past_the_warning_point_does_not_flash_again() {
  SessionTimer t;
  t.start(makeSession(), T0 + 3500, 43200);
  SessionTimer::Tick k = t.tick(T0 + 3500);
  TEST_ASSERT_FALSE(k.warnNow);
  TEST_ASSERT_EQUAL(static_cast<int>(Light::Warning), static_cast<int>(k.light));
}

void test_flasher_pattern_off_on_then_steady() {
  Flasher f;
  f.start(3, 1000);
  TEST_ASSERT_FALSE(f.relayOn(1000));
  TEST_ASSERT_TRUE(f.relayOn(1300));
  TEST_ASSERT_FALSE(f.relayOn(1600));
  TEST_ASSERT_TRUE(f.relayOn(1900));
  TEST_ASSERT_FALSE(f.relayOn(2200));
  TEST_ASSERT_TRUE(f.relayOn(2500));
  TEST_ASSERT_TRUE(f.relayOn(2800));  // done: steady ON
  TEST_ASSERT_FALSE(f.running(2800));
}

// ---- commands -------------------------------------------------------------

void test_start_command_turns_light_on_and_is_idempotent() {
  Controller c;
  Effects fx;
  const std::string cmd = startCmd("01JCMD00000000000000000001", SID, T0 + 3600);
  Ack a = run(c, cmd, T0, fx);
  TEST_ASSERT_EQUAL_STRING("OK", a.result);
  TEST_ASSERT_TRUE(fx.persists(1));
  TEST_ASSERT_EQUAL(1, a.channel);
  TEST_ASSERT_EQUAL(static_cast<int>(Light::On), static_cast<int>(a.light));
  TEST_ASSERT_TRUE(c.tick(T0 + 1, 0).relayOn[0]);

  Effects fx2;
  Ack again = run(c, cmd, T0 + 6, fx2);  // re-delivered after a lost ACK
  TEST_ASSERT_EQUAL_STRING("IGNORED", again.result);
  TEST_ASSERT_EQUAL(0, fx2.persistChannels);
  TEST_ASSERT_TRUE(c.tick(T0 + 7, 0).relayOn[0]);
}

void test_expired_commands_are_never_applied() {
  Controller c;
  Effects fx;
  Ack a = run(c, startCmd("01JCMD00000000000000000002", SID, T0 + 3600, T0 + 30), T0 + 31, fx);
  TEST_ASSERT_EQUAL_STRING("IGNORED", a.result);
  TEST_ASSERT_FALSE(c.timer(1).active());
  TEST_ASSERT_FALSE(c.tick(T0 + 31, 0).relayOn[0]);
}

void test_stop_only_stops_the_matching_session() {
  Controller c;
  Effects fx;
  run(c, startCmd("01JCMD00000000000000000003", SID, T0 + 3600), T0, fx);
  run(c, stopCmd("01JCMD00000000000000000004", SID2), T0, fx);
  TEST_ASSERT_TRUE(c.timer(1).active());
  Effects fx2;
  TEST_ASSERT_EQUAL_STRING("OK", run(c, stopCmd("01JCMD00000000000000000005", SID), T0 + 10, fx2).result);
  TEST_ASSERT_TRUE(fx2.persists(1));
  TEST_ASSERT_FALSE(c.tick(T0 + 11, 0).relayOn[0]);
}

void test_unknown_or_malformed_commands_are_errors() {
  Controller c;
  Effects fx;
  Ack u = run(c, R"({"commandId":"01JCMD00000000000000000006","type":"SELF_DESTRUCT","expiresAt":1790000030,"payload":{}})", T0, fx);
  TEST_ASSERT_EQUAL_STRING("ERROR", u.result);
  TEST_ASSERT_EQUAL_STRING("UNKNOWN_COMMAND", u.error);
  Ack m = run(c, R"({"commandId":"01JCMD00000000000000000007","type":"START_SESSION","expiresAt":1790000030,"payload":{"channel":1,"sessionId":"01J9ZQ3K8M4N5P6Q7R8S9T0V1W"}})", T0, fx);
  TEST_ASSERT_EQUAL_STRING("ERROR", m.result);
  TEST_ASSERT_FALSE(c.timer(1).active());
}

void test_ota_is_refused_during_a_game() {
  Controller c;
  Effects fx;
  run(c, startCmd("01JCMD00000000000000000008", SID, T0 + 3600, T0 + 30, 3), T0, fx);  // only channel 3 plays
  const char* ota = R"({"commandId":"01JCMD00000000000000000009","type":"OTA","expiresAt":1790000030,"payload":{"version":"1.1.0","sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","size":900000}})";
  Effects fx2;
  Ack a = run(c, ota, T0, fx2);
  TEST_ASSERT_EQUAL_STRING("SESSION_ACTIVE", a.error);
  TEST_ASSERT_FALSE(fx2.ota);
}

void test_config_update_respects_protocol_ranges() {
  Controller c;
  Effects fx;
  run(c, R"({"commandId":"01JCMD0000000000000000000A","type":"CONFIG_UPDATE","expiresAt":1790000030,"payload":{"pollIntervalSec":5,"maxSessionSec":30,"warnBeforeSec":120,"flashCount":2}})", T0, fx);
  TEST_ASSERT_TRUE(fx.persistConfig);
  TEST_ASSERT_EQUAL(5, c.config().pollIntervalSec);
  TEST_ASSERT_EQUAL(12 * 3600, c.config().maxSessionSec);  // 30 s is below the protocol minimum → ignored
  TEST_ASSERT_EQUAL(120, c.config().warnBeforeSec);
}

// ---- protocol fixtures ----------------------------------------------------

void test_every_command_in_the_protocol_poll_example_is_handled() {
  JsonDocument d;
  TEST_ASSERT_FALSE(deserializeJson(d, fixture("device.poll.response.json")));
  Controller c(Config{}, 4);
  const int64_t serverNow = d["serverTime"];
  for (JsonObjectConst cmd : d["commands"].as<JsonArrayConst>()) {
    Effects fx;
    Ack a = c.handle(cmd, serverNow, serverNow, 0, fx);
    TEST_ASSERT_NOT_EQUAL(0, std::strcmp(a.result, "ERROR"));
  }
}

void test_state_example_resumes_each_channel_and_missing_channels_stop() {
  JsonDocument d;
  TEST_ASSERT_FALSE(deserializeJson(d, fixture("device.state.response.json")));
  Controller c(Config{}, 4);
  Effects fx;
  const int64_t now = d["serverTime"];
  c.applyState(d.as<JsonObjectConst>(), now, fx);
  TEST_ASSERT_TRUE(c.timer(1).active());
  TEST_ASSERT_FALSE(c.timer(2).active());
  TEST_ASSERT_TRUE(c.timer(3).active());
  Controller::Output out = c.tick(now, 0);
  TEST_ASSERT_TRUE(out.relayOn[0]);
  TEST_ASSERT_FALSE(out.relayOn[1]);
  TEST_ASSERT_EQUAL(static_cast<int>(Light::Warning), static_cast<int>(out.light[2]));  // channel 3 ends at +100 s

  JsonDocument one;
  deserializeJson(one, R"({"serverTime":1790000150,"sessions":[{"channel":3,"sessionId":"01J9ZQ3K8M4N5P6Q7R8S9T0V4Z","startAt":1789999000,"endAt":1790000200,"status":"ACTIVE"}],"config":{"pollIntervalSec":3,"maxSessionSec":43200,"warnBeforeSec":300,"flashCount":3}})");
  Effects fx2;
  c.applyState(one.as<JsonObjectConst>(), now + 50, fx2);  // channel 1 was stopped early while we were offline
  TEST_ASSERT_FALSE(c.timer(1).active());
  TEST_ASSERT_TRUE(c.timer(3).active());
  TEST_ASSERT_TRUE(fx2.persists(1));
  TEST_ASSERT_FALSE(fx2.persists(3));  // unchanged channel: no flash write
}

void test_channels_are_independent() {
  Controller c(Config{}, 4);
  Effects fx;
  run(c, startCmd("01JCMD0000000000000000000B", SID, T0 + 600, T0 + 30, 1), T0, fx);
  run(c, startCmd("01JCMD0000000000000000000C", SID2, T0 + 3600, T0 + 30, 4), T0, fx);
  Controller::Output out = c.tick(T0 + 1, 0);
  TEST_ASSERT_TRUE(out.relayOn[0]);
  TEST_ASSERT_FALSE(out.relayOn[1]);
  TEST_ASSERT_FALSE(out.relayOn[2]);
  TEST_ASSERT_TRUE(out.relayOn[3]);

  // Channel 1 ends locally at its endAt (no network); channel 4 keeps running.
  out = c.tick(T0 + 600, 0);
  TEST_ASSERT_EQUAL(1, out.ended);
  TEST_ASSERT_FALSE(out.relayOn[0]);
  TEST_ASSERT_TRUE(out.relayOn[3]);

  // A STOP for channel 4's session sent to channel 1 does nothing.
  Effects fx2;
  run(c, stopCmd("01JCMD0000000000000000000D", SID2, 1), T0, fx2, T0 + 700);
  TEST_ASSERT_TRUE(c.timer(4).active());
  run(c, stopCmd("01JCMD0000000000000000000E", SID2, 4), T0, fx2, T0 + 700);
  TEST_ASSERT_FALSE(c.timer(4).active());
  TEST_ASSERT_TRUE(fx2.persists(4));
}

void test_commands_for_a_channel_the_board_lacks_are_rejected() {
  Controller c(Config{}, 2);
  Effects fx;
  Ack a = run(c, startCmd("01JCMD0000000000000000000F", SID, T0 + 600, T0 + 30, 3), T0, fx);
  TEST_ASSERT_EQUAL_STRING("ERROR", a.result);
  TEST_ASSERT_EQUAL_STRING("BAD_CHANNEL", a.error);
  Ack none = run(c, R"({"commandId":"01JCMD0000000000000000000G","type":"START_SESSION","expiresAt":1790000030,"payload":{"sessionId":"01J9ZQ3K8M4N5P6Q7R8S9T0V1W","startAt":1790000000,"endAt":1790000600}})", T0, fx);
  TEST_ASSERT_EQUAL_STRING("BAD_CHANNEL", none.error);
  Controller::Output out = c.tick(T0 + 1, 0);
  TEST_ASSERT_FALSE(out.relayOn[0]);
  TEST_ASSERT_FALSE(out.relayOn[1]);
  TEST_ASSERT_EQUAL(0, fx.persistChannels);
}

void test_poll_body_has_protocol_fields_and_no_tenant_data() {
  Controller c(Config{}, 4);
  Effects fx;
  run(c, startCmd("01JCMD00000000000000000001", SID, T0 + 3600, T0 + 30, 2), T0, fx);
  JsonDocument d;
  buildPoll(d, T0, "1.0.0", c, -61, 86400, "POWERON");
  TEST_ASSERT_EQUAL(4, d["channels"].size());
  TEST_ASSERT_EQUAL(1, d["channels"][0]["channel"].as<int>());
  TEST_ASSERT_EQUAL_STRING("OFF", d["channels"][0]["state"]);
  TEST_ASSERT_TRUE(d["channels"][0]["sessionId"].isNull());
  TEST_ASSERT_EQUAL_STRING("ON", d["channels"][1]["state"]);
  TEST_ASSERT_EQUAL_STRING(SID, d["channels"][1]["sessionId"]);
  TEST_ASSERT_EQUAL_INT64(T0 + 3600, d["channels"][1]["endAt"].as<int64_t>());
  TEST_ASSERT_EQUAL_STRING("01JCMD00000000000000000001", d["lastAppliedCommandId"]);
  TEST_ASSERT_FALSE(d["tenantId"].is<const char*>());
  TEST_ASSERT_FALSE(d["tableId"].is<const char*>());
  // Same key sets as the protocol example.
  JsonDocument ex;
  deserializeJson(ex, fixture("device.poll.request.json"));
  for (JsonPairConst kv : d.as<JsonObjectConst>()) TEST_ASSERT_TRUE_MESSAGE(ex[kv.key()].isNull() == false || kv.value().isNull(), kv.key().c_str());
  for (JsonPairConst kv : d["channels"][0].as<JsonObjectConst>()) TEST_ASSERT_TRUE_MESSAGE(ex["channels"][0].as<JsonObjectConst>()[kv.key()].isUnbound() == false, kv.key().c_str());

  // ACK carries the channel and its light.
  Ack acks[1];
  acks[0] = run(c, stopCmd("01JCMD00000000000000000002", SID, 2), T0 + 5, fx);
  JsonDocument a;
  buildAck(a, acks, 1);
  TEST_ASSERT_EQUAL(2, a["acks"][0]["channel"].as<int>());
  TEST_ASSERT_EQUAL_STRING("OFF", a["acks"][0]["state"]);
}

// ---- boot + clocks ---------------------------------------------------------

void test_boot_recovery_decisions() {
  Session s = makeSession();
  s.active = true;
  TEST_ASSERT_TRUE(decideBoot(s, 1200, true, T0 + 100, 43200).action == BootDecision::Action::ResumeUntilEnd);
  TEST_ASSERT_TRUE(decideBoot(s, 1200, true, T0 + 3600, 43200).action == BootDecision::Action::Off);
  BootDecision unknown = decideBoot(s, 1200, false, 0, 43200);
  TEST_ASSERT_TRUE(unknown.action == BootDecision::Action::ResumeForCheckpoint);
  TEST_ASSERT_EQUAL_INT64(1200, unknown.resumeSec);
  TEST_ASSERT_EQUAL_INT64(600, decideBoot(s, 99999, false, 0, 600).resumeSec);  // bounded by the cap
  TEST_ASSERT_TRUE(decideBoot(s, 0, false, 0, 43200).action == BootDecision::Action::Off);
  Session none;
  TEST_ASSERT_TRUE(decideBoot(none, 500, true, T0, 43200).action == BootDecision::Action::Off);
}

void test_epoch_clock_and_backoff() {
  EpochClock clk;
  TEST_ASSERT_FALSE(clk.known());
  clk.set(T0, 5000);
  TEST_ASSERT_EQUAL_INT64(T0 + 10, clk.now(15000));
  Backoff b;
  TEST_ASSERT_EQUAL_UINT32(1000, b.next());
  TEST_ASSERT_EQUAL_UINT32(2000, b.next());
  for (int i = 0; i < 10; i++) b.next();
  TEST_ASSERT_EQUAL_UINT32(30000, b.next());
  b.reset();
  TEST_ASSERT_EQUAL_UINT32(1000, b.next());
}

int main() {
  UNITY_BEGIN();
  RUN_TEST(test_light_is_on_until_end_and_off_exactly_at_end_without_network);
  RUN_TEST(test_warning_fires_once_at_end_minus_warn_before);
  RUN_TEST(test_hard_cap_limits_any_session);
  RUN_TEST(test_invalid_or_already_ended_sessions_are_not_started);
  RUN_TEST(test_joining_late_past_the_warning_point_does_not_flash_again);
  RUN_TEST(test_flasher_pattern_off_on_then_steady);
  RUN_TEST(test_start_command_turns_light_on_and_is_idempotent);
  RUN_TEST(test_expired_commands_are_never_applied);
  RUN_TEST(test_stop_only_stops_the_matching_session);
  RUN_TEST(test_unknown_or_malformed_commands_are_errors);
  RUN_TEST(test_ota_is_refused_during_a_game);
  RUN_TEST(test_config_update_respects_protocol_ranges);
  RUN_TEST(test_every_command_in_the_protocol_poll_example_is_handled);
  RUN_TEST(test_state_example_resumes_each_channel_and_missing_channels_stop);
  RUN_TEST(test_channels_are_independent);
  RUN_TEST(test_commands_for_a_channel_the_board_lacks_are_rejected);
  RUN_TEST(test_poll_body_has_protocol_fields_and_no_tenant_data);
  RUN_TEST(test_boot_recovery_decisions);
  RUN_TEST(test_epoch_clock_and_backoff);
  return UNITY_END();
}
