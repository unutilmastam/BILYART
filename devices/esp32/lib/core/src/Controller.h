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
  int channel = 0;            // relay channel the command addressed (0 = device-wide command)
  Light light = Light::Off;   // that channel's light after the command
};

/** Side effects the glue must perform after handling commands. */
struct Effects {
  uint8_t persistChannels = 0;  // bit (channel − 1): that channel's session changed → write it to NVS
  bool persistConfig = false;
  bool syncState = false;  // call GET /state
  bool ota = false;
  char otaVersion[33] = {0};
  char otaSha256[65] = {0};
  int64_t otaSize = 0;

  void persist(int channel) { persistChannels |= static_cast<uint8_t>(1u << (channel - 1)); }
  bool persists(int channel) const { return persistChannels & (1u << (channel - 1)); }
};

/**
 * The device brain, free of hardware: applies commands and /state, runs one
 * local timer per relay channel and decides every relay level. One ESP32 per
 * branch drives up to kMaxChannels table lamps; channels never affect each other.
 * The Arduino glue only moves bytes between it and GPIO/NVS/HTTP.
 */
class Controller {
 public:
  explicit Controller(Config cfg = Config{}, int channels = 4) : cfg_(cfg), channels_(channels < 1 ? 1 : channels > kMaxChannels ? kMaxChannels : channels) {}

  int channels() const { return channels_; }
  bool validChannel(int ch) const { return ch >= 1 && ch <= channels_; }

  /** Handles one command from /poll. serverNow = serverTime of that poll response. */
  Ack handle(JsonObjectConst cmd, int64_t serverNow, int64_t now, uint64_t monoMs, Effects& fx);

  /** Applies GET /state (authoritative after boot/reconnect): channels missing from `sessions` are turned off. */
  void applyState(JsonObjectConst state, int64_t now, Effects& fx);

  /** Restores a channel's session from NVS (boot). */
  void restore(int channel, const Session& s, bool warned, int64_t now);

  struct Output {
    bool relayOn[kMaxChannels] = {};
    Light light[kMaxChannels] = {};
    uint8_t ended = 0;  // bit (channel − 1): that session just ended locally
  };
  /** Call often (≥ 10 Hz). */
  Output tick(int64_t now, uint64_t monoMs);

  const SessionTimer& timer(int channel) const { return timers_[channel - 1]; }
  bool anyActive() const;
  /** Reported state of a channel (protocol LightState). */
  Light light(int channel) const;
  Config& config() { return cfg_; }
  const char* lastAppliedCommandId() const { return log_.last(); }

 private:
  bool startFrom(int channel, JsonObjectConst payload, int64_t now, const char*& error);
  void applyConfig(JsonObjectConst c);
  void stopChannel(int channel, Effects& fx);

  Config cfg_;
  int channels_;
  SessionTimer timers_[kMaxChannels];
  Flasher flashers_[kMaxChannels];
  CommandLog<16> log_;
};

}  // namespace bl
