#pragma once
#include <ArduinoJson.h>
#include "Controller.h"
#include "Types.h"

namespace bl {

/** Body of POST /device/v1/poll (protocol device.poll.request). Never contains tenant/branch/table. */
inline void buildPoll(JsonDocument& doc, int64_t ts, const char* fw, Light light, const Session& s, int32_t rssi, uint32_t uptimeSec, const char* bootReason, const char* lastCmd) {
  doc.clear();
  doc["ts"] = ts;
  doc["fw"] = fw;
  doc["state"] = lightName(light);
  if (s.active) {
    doc["sessionId"] = s.id;
    doc["endAt"] = s.endAt;
  } else {
    doc["sessionId"] = nullptr;
    doc["endAt"] = nullptr;
  }
  if (rssi < 0 && rssi >= -127) doc["rssi"] = rssi;
  doc["uptime"] = uptimeSec;
  if (bootReason && *bootReason) doc["bootReason"] = bootReason;
  if (lastCmd && *lastCmd) doc["lastAppliedCommandId"] = lastCmd;
  else doc["lastAppliedCommandId"] = nullptr;
}

/** Body of POST /device/v1/ack (protocol device.ack.request). */
inline void buildAck(JsonDocument& doc, const Ack* acks, size_t n, Light light) {
  doc.clear();
  JsonArray arr = doc["acks"].to<JsonArray>();
  for (size_t i = 0; i < n; i++) {
    JsonObject a = arr.add<JsonObject>();
    a["commandId"] = acks[i].commandId;
    a["result"] = acks[i].result;
    if (acks[i].error) a["error"] = acks[i].error;
    else a["error"] = nullptr;
    a["state"] = lightName(light);
  }
}

}  // namespace bl
