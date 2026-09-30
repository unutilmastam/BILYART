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
}  // namespace

bool Controller::startFrom(JsonObjectConst payload, int64_t now, const char*& error) {
  Session s;
  if (!sessionFrom(payload, cfg_, s)) {
    error = "BAD_PAYLOAD";
    return false;
  }
  switch (timer_.start(s, now, cfg_.maxSessionSec)) {
    case SessionTimer::StartResult::Invalid:
      error = "BAD_PAYLOAD";
      return false;
    case SessionTimer::StartResult::AlreadyEnded:
      error = "ALREADY_ENDED";
      return false;
    default:
      flasher_.cancel();
      return true;
  }
}

Ack Controller::handle(JsonObjectConst cmd, int64_t serverNow, int64_t now, uint64_t monoMs, Effects& fx) {
  Ack ack;
  const char* id = cmd["commandId"] | "";
  std::strncpy(ack.commandId, id, kIdLen);
  const char* type = cmd["type"] | "";
  const int64_t expiresAt = cmd["expiresAt"] | static_cast<int64_t>(0);

  if (!validId(id)) {
    ack.result = "ERROR";
    ack.error = "BAD_COMMAND_ID";
    return ack;
  }
  if (log_.contains(id)) {  // re-delivery after a lost ACK
    ack.result = "IGNORED";
    return ack;
  }
  if (expiresAt != 0 && expiresAt < serverNow) {  // stale command: never act on it
    log_.add(id);
    ack.result = "IGNORED";
    return ack;
  }

  JsonObjectConst p = cmd["payload"];
  if (std::strcmp(type, "START_SESSION") == 0) {
    const char* err = nullptr;
    if (startFrom(p, now, err)) {
      fx.persistSession = true;
    } else if (std::strcmp(err, "ALREADY_ENDED") == 0) {
      ack.result = "IGNORED";
    } else {
      ack.result = "ERROR";
      ack.error = err;
    }
  } else if (std::strcmp(type, "STOP_SESSION") == 0) {
    const char* sid = p["sessionId"] | static_cast<const char*>(nullptr);
    if (timer_.stop(sid)) {
      flasher_.cancel();
      fx.persistSession = true;
    }
  } else if (std::strcmp(type, "WARNING") == 0) {
    const char* sid = p["sessionId"] | static_cast<const char*>(nullptr);
    if (timer_.active() && (!sid || timer_.session().is(sid))) flasher_.start(timer_.session().flashCount > 0 ? timer_.session().flashCount : 3, monoMs);
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
    } else if (timer_.active()) {
      ack.result = "ERROR";
      ack.error = "SESSION_ACTIVE";  // never update during a game
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

  log_.add(id);
  return ack;
}

void Controller::applyState(JsonObjectConst state, int64_t now, Effects& fx) {
  JsonObjectConst s = state["session"];
  if (s.isNull()) {
    if (timer_.stop(nullptr)) {
      flasher_.cancel();
      fx.persistSession = true;
    }
  } else {
    const char* status = s["status"] | "";
    const bool running = std::strcmp(status, "ACTIVE") == 0 || std::strcmp(status, "STARTING") == 0;
    const char* sid = s["sessionId"] | "";
    if (!running) {
      if (timer_.stop(nullptr)) fx.persistSession = true;
    } else if (!timer_.session().is(sid) || timer_.session().endAt != (s["endAt"] | static_cast<int64_t>(0))) {
      timer_.stop(nullptr);
      const char* err = nullptr;
      startFrom(s, now, err);
      fx.persistSession = true;
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

void Controller::restore(const Session& s, bool warned, int64_t now) {
  if (timer_.start(s, now, cfg_.maxSessionSec) == SessionTimer::StartResult::Started) timer_.setWarned(warned || timer_.warned());
}

Controller::Output Controller::tick(int64_t now, uint64_t monoMs) {
  const SessionTimer::Tick t = timer_.tick(now);
  if (t.warnNow) flasher_.start(timer_.session().flashCount, monoMs);
  if (!t.relayOn) flasher_.cancel();
  return Output{t.relayOn && flasher_.relayOn(monoMs), t.light, t.ended};
}

}  // namespace bl
