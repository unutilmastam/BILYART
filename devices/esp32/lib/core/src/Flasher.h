#pragma once
#include <cstdint>

namespace bl {

/** Warning pattern: `count` × (OFF 300 ms, ON 300 ms), then steady ON. Pure timing, no GPIO. */
class Flasher {
 public:
  static constexpr uint32_t kPhaseMs = 300;

  void start(int32_t count, uint64_t nowMs) {
    count_ = count > 0 ? count : 0;
    startMs_ = nowMs;
  }
  void cancel() { count_ = 0; }
  bool running(uint64_t nowMs) const { return count_ > 0 && nowMs - startMs_ < static_cast<uint64_t>(count_) * 2 * kPhaseMs; }

  /** Relay level while a session is ON: false during the OFF half of each flash. */
  bool relayOn(uint64_t nowMs) const {
    if (!running(nowMs)) return true;
    return ((nowMs - startMs_) / kPhaseMs) % 2 == 1;
  }

 private:
  int32_t count_ = 0;
  uint64_t startMs_ = 0;
};

}  // namespace bl
