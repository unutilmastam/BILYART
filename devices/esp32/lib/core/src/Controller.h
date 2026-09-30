#pragma once
#include <ArduinoJson.h>
#include "CommandLog.h"
#include "Flasher.h"
#include "SessionTimer.h"
#include "Types.h"

namespace bl {

/** Result of one command, reported in /ack. */
struct Ack {
  char commandId[kIdLen + 1] = {0};
  const char* result = "OK";  // OK | ERROR | IGNORED
  const char* error = nullptr;
};

/** Side effects the glue must perform after handling commands. */
struct Effects {
  bool persistSession = false;
  bool persistConfig = false;
  bool syncState = false;  // call GET /state
  bool ota = false;
  char otaVersion[33] = {0};
  char otaSha256[65] = {0};
  int64_t otaSize = 0;
};

/**
 * The device brain, free of hardware: applies commands and /state, runs the
 * local timer and decides the relay level. The Arduino glue only moves bytes
 * between it and GPIO/NVS/HTTP.
 */
class Controller {
 public:
  explicit Controller(Config cfg = Config{}) : cfg_(cfg) {}

  /** Handles one command from /poll. serverNow = serverTime of that poll response. */
  Ack handle(JsonObjectConst cmd, int64_t serverNow, int64_t now, uint64_t monoMs, Effects& fx);

  /** Applies GET /state (authoritative after boot/reconnect). */
  void applyState(JsonObjectConst state, int64_t now, Effects& fx);

  /** Restores a session from NVS (boot). */
  void restore(const Session& s, bool warned, int64_t now);

  struct Output {
    bool relayOn;
    Light light;
    bool ended;
  };
  /** Call often (≥ 10 Hz). */
  Output tick(int64_t now, uint64_t monoMs);

  const SessionTimer& timer() const { return timer_; }
  Config& config() { return cfg_; }
  const char* lastAppliedCommandId() const { return log_.last(); }

 private:
  bool startFrom(JsonObjectConst payload, int64_t now, const char*& error);
  void applyConfig(JsonObjectConst c);

  Config cfg_;
  SessionTimer timer_;
  Flasher flasher_;
  CommandLog<16> log_;
};

}  // namespace bl
