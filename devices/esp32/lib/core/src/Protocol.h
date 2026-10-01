#pragma once
#include <ArduinoJson.h>
#include "Controller.h"
#include "Types.h"

namespace bl {

/** Body of POST /device/v1/poll (protocol device.poll.request): one entry per relay channel. Never contains tenant/branch/table. */
inline void buildPoll(JsonDocument& doc, int64_t ts, const char* fw, const Controller& c, int32_t rssi, uint32_t uptimeSec, const char* bootReason) {
  doc.clear();
  doc["ts"] = ts;
  doc["fw"] = fw;
  JsonArray channels = doc["channels"].to<JsonArray>();
  for (int ch = 1; ch <= c.channels(); ch++) {
    JsonObject o = channels.add<JsonObject>();
    o["channel"] = ch;
    o["state"] = lightName(c.light(ch));
    const Session& s = c.timer(ch).session();
    if (s.active) {
      o["sessionId"] = s.id;
      o["endAt"] = s.endAt;
    } else {
      o["sessionId"] = nullptr;
      o["endAt"] = nullptr;
    }
  }
  if (rssi < 0 && rssi >= -127) doc["rssi"] = rssi;
  doc["uptime"] = uptimeSec;
  if (bootReason && *bootReason) doc["bootReason"] = bootReason;
  const char* lastCmd = c.lastAppliedCommandId();
  if (lastCmd && *lastCmd) doc["lastAppliedCommandId"] = lastCmd;
  else doc["lastAppliedCommandId"] = nullptr;
}

/** Body of POST /device/v1/ack (protocol device.ack.request). */
inline void buildAck(JsonDocument& doc, const Ack* acks, size_t n) {
  doc.clear();
  JsonArray arr = doc["acks"].to<JsonArray>();
  for (size_t i = 0; i < n; i++) {
    JsonObject a = arr.add<JsonObject>();
    a["commandId"] = acks[i].commandId;
    a["result"] = acks[i].result;
    if (acks[i].error) a["error"] = acks[i].error;
    else a["error"] = nullptr;
    if (acks[i].channel) {
      a["channel"] = acks[i].channel;
      a["state"] = lightName(acks[i].light);
    }
  }
}

}  // namespace bl
