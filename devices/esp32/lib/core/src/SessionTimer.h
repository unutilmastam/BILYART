#pragma once
#include "Types.h"

namespace bl {

/**
 * Local fail-safe session logic (DEVICE_PROTOCOL.md §4): the light turns OFF at
 * endAt and warns at endAt − warnBeforeSec using only the local clock — no
 * network needed. The server stays authoritative through START/STOP/SYNC.
 */
class SessionTimer {
 public:
  struct Tick {
    bool relayOn = false;
    Light light = Light::Off;
    bool warnNow = false;  // start the flash pattern this tick (once per session)
    bool ended = false;    // the session just ended locally
  };

  enum class StartResult { Started, AlreadyRunning, AlreadyEnded, Invalid };

  /** Applies a session. endAt is capped at now + maxSessionSec. */
  StartResult start(const Session& s, int64_t now, int32_t maxSessionSec);

  /** Stops the running session if the id matches (or any when id is null). Returns true if something stopped. */
  bool stop(const char* sessionId);

  Tick tick(int64_t now);

  const Session& session() const { return s_; }
  bool active() const { return s_.active; }
  bool warned() const { return warned_; }
  void setWarned(bool w) { warned_ = w; }
  int64_t remainingSec(int64_t now) const { return s_.active && s_.endAt > now ? s_.endAt - now : 0; }

 private:
  Session s_;
  bool warned_ = false;
};

}  // namespace bl
