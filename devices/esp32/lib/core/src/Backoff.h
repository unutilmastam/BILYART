#pragma once
#include <cstdint>

namespace bl {

/** Exponential backoff 1 s → 30 s for Wi-Fi / API retries (DEVICE_PROTOCOL.md §5). */
class Backoff {
 public:
  explicit Backoff(uint32_t minMs = 1000, uint32_t maxMs = 30000) : min_(minMs), max_(maxMs), cur_(minMs) {}
  uint32_t next() {
    const uint32_t d = cur_;
    cur_ = cur_ >= max_ / 2 ? max_ : cur_ * 2;
    return d;
  }
  void reset() { cur_ = min_; }

 private:
  uint32_t min_, max_, cur_;
};

}  // namespace bl
