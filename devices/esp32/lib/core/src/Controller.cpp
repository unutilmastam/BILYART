#include "Controller.h"

#include <cstring>

namespace bl {

namespace {
bool validId(const char* s) { return s && std::strlen(s) == kIdLen; }

bool sessionFrom(JsonObjectConst p, const Config& cfg, Session& s) {
  const char* id = p["sessionId"] | static_cast<const char*>(nullptr);
  if (!validId(id) || !p["startAt"].is<int64_t>() || !p["endAt"].is<int64_t>()) return false;
  s.setId(id);
  s.startAt = p["startAt"].as<int64_t>();
  s.endAt = p["endAt"].as<int64_t>();
  s.warnBeforeSec = p["warnBeforeSec"] | cfg.warnBeforeSec;
  s.flashCount = p["flashCount"] | cfg.flashCount;
  return true;
}

int channelOf(JsonObjectConst p) { return p["channel"].is<int>() ? p["channel"].as<int>() : 0; }
}  // namespace

bool Controller::anyActive() const {
  for (int i = 0; i < channels_; i++)
    if (timers_[i].active()) return true;
  return false;
}

Light Controller::light(int channel) const {
  const SessionTimer& t = timers_[channel - 1];
  if (!t.active()) return Light::Off;
  return t.warned() ? Light::Warning : Light::On;
}

bool Controller::startFrom(int channel, JsonObjectConst payload, int64_t now, const char*& error) {
  Session s;
  if (!sessionFrom(payload, cfg_, s)) {
    error = "BAD_PAYLOAD";
    return false;
  }
  switch (timers_[channel - 1].start(s, now, cfg_.maxSessionSec)) {
    case SessionTimer::StartResult::Invalid:
      error = "BAD_PAYLOAD";
      return false;
    case SessionTimer::StartResult::AlreadyEnded:
      error = "ALREADY_ENDED";
      return false;
    default:
      flashers_[channel - 1].cancel();
      return true;
  }
}

void Controller::stopChannel(int channel, Effects& fx) {
  if (timers_[channel - 1].stop(nullptr)) {
    flashers_[channel - 1].cancel();
    fx.persist(channel);
  }
}

Ack Controller::handle(JsonObjectConst cmd, int64_t serverNow, int64_t now, uint64_t monoMs, Effects& fx) {
  Ack ack;
  const char* id = cmd["commandId"] | "";
  std::strncpy(ack.commandId, id, kIdLen);
  const char* type = cmd["type"] | "";
  const int64_t expiresAt = cmd["expiresAt"] | static_cast<int64_t>(0);
  JsonObjectConst p = cmd["payload"];
  const bool perChannel = std::strcmp(type, "START_SESSION") == 0 || std::strcmp(type, "STOP_SESSION") == 0 || std::strcmp(type, "WARNING") == 0;
  const int ch = perChannel ? channelOf(p) : 0;
  ack.channel = ch;

  if (!validId(id)) {
    ack.result = "ERROR";
    ack.error = "BAD_COMMAND_ID";
    return ack;
  }
  if (perChannel && !validChannel(ch)) {  // a channel this board does not have: never touch another relay
    ack.channel = 0;
    ack.result = "ERROR";
    ack.error = "BAD_CHANNEL";
    log_.add(id);
    return ack;
  }
  if (log_.contains(id)) {  // re-delivery after a lost ACK
    ack.result = "IGNORED";
    if (ch) ack.light = light(ch);
    return ack;
  }
  if (expiresAt != 0 && expiresAt < serverNow) {  // stale command: never act on it
    log_.add(id);
    ack.result = "IGNORED";
    if (ch) ack.light = light(ch);
    return ack;
  }

  if (std::strcmp(type, "START_SESSION") == 0) {
    const char* err = nullptr;
    if (startFrom(ch, p, now, err)) {
      fx.persist(ch);
    } else if (std::strcmp(err, "ALREADY_ENDED") == 0) {
      ack.result = "IGNORED";
    } else {
      ack.result = "ERROR";
      ack.error = err;
    }
  } else if (std::strcmp(type, "STOP_SESSION") == 0) {
    const char* sid = p["sessionId"] | static_cast<const char*>(nullptr);
    if (timers_[ch - 1].stop(sid)) {
      flashers_[ch - 1].cancel();
      fx.persist(ch);
    }
  } else if (std::strcmp(type, "WARNING") == 0) {
    const char* sid = p["sessionId"] | static_cast<const char*>(nullptr);
    const SessionTimer& t = timers_[ch - 1];
    if (t.active() && (!sid || t.session().is(sid))) flashers_[ch - 1].start(t.session().flashCount > 0 ? t.session().flashCount : 3, monoMs);
    else ack.result = "IGNORED";
  } else if (std::strcmp(type, "SYNC") == 0) {
    fx.syncState = true;
  } else if (std::strcmp(type, "PING") == 0) {
    // ACK only
  } else if (std::strcmp(type, "CONFIG_UPDATE") == 0) {
    applyConfig(p);
    fx.persistConfig = true;
  } else if (std::strcmp(type, "OTA") == 0) {
    const char* ver = p["version"] | "";
    const char* sha = p["sha256"] | "";
    if (!*ver || std::strlen(sha) != 64 || !p["size"].is<int64_t>()) {
      ack.result = "ERROR";
      ack.error = "BAD_PAYLOAD";
    } else if (anyActive()) {
      ack.result = "ERROR";
      ack.error = "SESSION_ACTIVE";  // never update while any table is playing
    } else {
      fx.ota = true;
      std::strncpy(fx.otaVersion, ver, sizeof(fx.otaVersion) - 1);
      std::strncpy(fx.otaSha256, sha, sizeof(fx.otaSha256) - 1);
      fx.otaSize = p["size"].as<int64_t>();
    }
  } else {
    ack.result = "ERROR";
    ack.error = "UNKNOWN_COMMAND";
  }

  if (ch) ack.light = light(ch);
  log_.add(id);
  return ack;
}

void Controller::applyState(JsonObjectConst state, int64_t now, Effects& fx) {
  JsonArrayConst sessions = state["sessions"];
  for (int ch = 1; ch <= channels_; ch++) {
    JsonObjectConst s;
    for (JsonObjectConst e : sessions)
      if (channelOf(e) == ch) s = e;
    if (s.isNull()) {  // nothing runs on this channel (e.g. stopped early while we were offline)
      stopChannel(ch, fx);
      continue;
    }
    const char* status = s["status"] | "";
    const bool running = std::strcmp(status, "ACTIVE") == 0 || std::strcmp(status, "STARTING") == 0;
    const char* sid = s["sessionId"] | "";
    SessionTimer& t = timers_[ch - 1];
    if (!running) {
      stopChannel(ch, fx);
    } else if (!t.session().is(sid) || t.session().endAt != (s["endAt"] | static_cast<int64_t>(0))) {
      t.stop(nullptr);
      const char* err = nullptr;
      startFrom(ch, s, now, err);
      fx.persist(ch);
    }
  }
  JsonObjectConst c = state["config"];
  if (!c.isNull()) {
    applyConfig(c);
    fx.persistConfig = true;
  }
}

void Controller::applyConfig(JsonObjectConst c) {
  // Ranges from protocol DeviceConfig; out-of-range values are ignored, not clamped silently into danger.
  auto in = [](JsonVariantConst v, int32_t lo, int32_t hi) { return v.is<int32_t>() && v.as<int32_t>() >= lo && v.as<int32_t>() <= hi; };
  if (in(c["pollIntervalSec"], 1, 60)) cfg_.pollIntervalSec = c["pollIntervalSec"];
  if (in(c["maxSessionSec"], 60, 86400)) cfg_.maxSessionSec = c["maxSessionSec"];
  if (in(c["warnBeforeSec"], 0, 3600)) cfg_.warnBeforeSec = c["warnBeforeSec"];
  if (in(c["flashCount"], 0, 10)) cfg_.flashCount = c["flashCount"];
}

void Controller::restore(int channel, const Session& s, bool warned, int64_t now) {
  if (!validChannel(channel)) return;
  SessionTimer& t = timers_[channel - 1];
  if (t.start(s, now, cfg_.maxSessionSec) == SessionTimer::StartResult::Started) t.setWarned(warned || t.warned());
}

Controller::Output Controller::tick(int64_t now, uint64_t monoMs) {
  Output out;
  for (int i = 0; i < channels_; i++) {
    const SessionTimer::Tick t = timers_[i].tick(now);
    if (t.warnNow) flashers_[i].start(timers_[i].session().flashCount, monoMs);
    if (!t.relayOn) flashers_[i].cancel();
    out.relayOn[i] = t.relayOn && flashers_[i].relayOn(monoMs);
    out.light[i] = t.light;
    if (t.ended) out.ended |= static_cast<uint8_t>(1u << i);
  }
  return out;
}

}  // namespace bl
