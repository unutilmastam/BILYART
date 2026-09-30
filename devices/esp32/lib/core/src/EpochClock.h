#pragma once
#include <cstdint>

namespace bl {

/**
 * Epoch time from a monotonic millisecond counter + the last trusted sample
 * (NTP or the server's serverTime). Before the first sample the time is unknown
 * and session end times must not be evaluated against the RTC's 1970 value.
 */
class EpochClock {
 public:
  void set(int64_t epochSec, uint64_t monoMs) {
    base_ = epochSec;
    baseMono_ = monoMs;
    known_ = true;
  }
  bool known() const { return known_; }
  int64_t now(uint64_t monoMs) const { return known_ ? base_ + static_cast<int64_t>((monoMs - baseMono_) / 1000) : 0; }

 private:
  int64_t base_ = 0;
  uint64_t baseMono_ = 0;
  bool known_ = false;
};

}  // namespace bl
