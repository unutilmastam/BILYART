#pragma once
#include <cstring>
#include "Types.h"

namespace bl {

/** Last N applied command ids — commands are idempotent by commandId (re-delivery after a lost ACK). */
template <size_t N = 16>
class CommandLog {
 public:
  bool contains(const char* id) const {
    for (size_t i = 0; i < N; i++)
      if (ids_[i][0] && std::strncmp(ids_[i], id, kIdLen) == 0) return true;
    return false;
  }
  void add(const char* id) {
    std::strncpy(ids_[next_], id, kIdLen);
    ids_[next_][kIdLen] = 0;
    next_ = (next_ + 1) % N;
  }
  const char* last() const { return ids_[(next_ + N - 1) % N]; }

 private:
  char ids_[N][kIdLen + 1] = {};
  size_t next_ = 0;
};

}  // namespace bl
