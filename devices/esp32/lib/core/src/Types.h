#pragma once
#include <cstdint>
#include <cstring>

namespace bl {

/** Reported light state (protocol LightState). */
enum class Light : uint8_t { Off, On, Warning };

inline const char* lightName(Light l) {
  switch (l) {
    case Light::On: return "ON";
    case Light::Warning: return "WARNING";
    default: return "OFF";
  }
}

constexpr size_t kIdLen = 26;  // ULID public id

/** A session as the device keeps it (all times: Unix epoch seconds, UTC — server authoritative). */
struct Session {
  char id[kIdLen + 1] = {0};
  int64_t startAt = 0;
  int64_t endAt = 0;
  int32_t warnBeforeSec = 300;
  int32_t flashCount = 3;
  bool active = false;

  void setId(const char* s) {
    std::strncpy(id, s ? s : "", kIdLen);
    id[kIdLen] = 0;
  }
  bool is(const char* other) const { return active && other && std::strncmp(id, other, kIdLen) == 0; }
};

/** Runtime configuration (CONFIG_UPDATE, persisted in NVS). */
struct Config {
  int32_t pollIntervalSec = 3;
  int32_t maxSessionSec = 12 * 3600;  // hard cap: the light is never ON longer than this
  int32_t warnBeforeSec = 300;        // used when a session comes from /state (no per-session value)
  int32_t flashCount = 3;
};

}  // namespace bl
