#include "SessionTimer.h"

namespace bl {

SessionTimer::StartResult SessionTimer::start(const Session& in, int64_t now, int32_t maxSessionSec) {
  if (in.id[0] == 0 || in.endAt <= in.startAt || in.warnBeforeSec < 0 || in.flashCount < 0) return StartResult::Invalid;
  if (s_.is(in.id)) return StartResult::AlreadyRunning;
  if (in.endAt <= now) return StartResult::AlreadyEnded;

  s_ = in;
  s_.active = true;
  const int64_t cap = now + (maxSessionSec > 0 ? maxSessionSec : 12 * 3600);
  if (s_.endAt > cap) s_.endAt = cap;
  // Joining a session late (e.g. after reboot) past the warning point: no flash burst, just the WARNING state.
  warned_ = now >= s_.endAt - s_.warnBeforeSec;
  return StartResult::Started;
}

bool SessionTimer::stop(const char* sessionId) {
  if (!s_.active) return false;
  if (sessionId && !s_.is(sessionId)) return false;
  s_ = Session{};
  warned_ = false;
  return true;
}

SessionTimer::Tick SessionTimer::tick(int64_t now) {
  Tick t;
  if (!s_.active) return t;
  if (now >= s_.endAt) {
    s_ = Session{};
    warned_ = false;
    t.ended = true;
    return t;
  }
  t.relayOn = true;
  if (now >= s_.endAt - s_.warnBeforeSec) {
    if (!warned_) {
      warned_ = true;
      t.warnNow = s_.flashCount > 0;
    }
    t.light = Light::Warning;
  } else {
    t.light = Light::On;
  }
  return t;
}

}  // namespace bl
