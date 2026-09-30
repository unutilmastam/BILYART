#pragma once
#include <Arduino.h>
#include <ArduinoJson.h>

/** HTTPS JSON client for /device/v1 with the pinned root CAs (DEVICE_PROTOCOL.md). */
namespace api {

struct Response {
  int status = 0;  // 0 = network/TLS failure
  JsonDocument body;
  bool ok() const { return status >= 200 && status < 300; }
  const char* errorCode() const { return body["error"]["code"] | ""; }
};

void setBase(const String& apiBase);
/** Authorization header value ("Device <code>.<token>" or "PollToken <t>"), empty = none. */
Response request(const char* method, const char* path, const JsonDocument* body, const String& authorization, bool idempotent = false);

}  // namespace api
