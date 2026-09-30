#pragma once
#include "Types.h"

namespace bl {

/**
 * What to do with a session found in NVS after a reboot (DEVICE_PROTOCOL.md §5.4):
 * time known → resume until endAt or stay OFF; time unknown after the wait →
 * resume conservatively for the last remainingSec checkpoint (bounded by the cap).
 */
struct BootDecision {
  enum class Action { Off, ResumeUntilEnd, ResumeForCheckpoint } action = Action::Off;
  int64_t resumeSec = 0;  // for ResumeForCheckpoint
};

inline BootDecision decideBoot(const Session& saved, int64_t checkpointRemainingSec, bool timeKnown, int64_t now, int32_t maxSessionSec) {
  BootDecision d;
  if (!saved.active) return d;
  if (timeKnown) {
    if (now < saved.endAt) d.action = BootDecision::Action::ResumeUntilEnd;
    return d;
  }
  int64_t sec = checkpointRemainingSec;
  if (sec > maxSessionSec) sec = maxSessionSec;
  if (sec > 0) {
    d.action = BootDecision::Action::ResumeForCheckpoint;
    d.resumeSec = sec;
  }
  return d;
}

}  // namespace bl
