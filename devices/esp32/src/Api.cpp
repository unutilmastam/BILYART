#include "Api.h"

#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <esp_random.h>

#include "CaBundle.h"

namespace api {
namespace {
String base;

String uuid4() {
  uint8_t b[16];
  esp_fill_random(b, sizeof b);
  b[6] = (b[6] & 0x0F) | 0x40;
  b[8] = (b[8] & 0x3F) | 0x80;
  char s[37];
  snprintf(s, sizeof s, "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x", b[0], b[1], b[2], b[3], b[4], b[5], b[6], b[7], b[8], b[9], b[10], b[11], b[12], b[13], b[14], b[15]);
  return String(s);
}
}  // namespace

void setBase(const String& apiBase) {
  base = apiBase;
  while (base.endsWith("/")) base.remove(base.length() - 1);
}

Response request(const char* method, const char* path, const JsonDocument* body, const String& authorization, bool idempotent) {
  Response r;
  if (!base.startsWith("https://")) return r;  // TLS is mandatory

  WiFiClientSecure tls;
  tls.setCACert(kCaBundle);  // server certificate must chain to a pinned root
  tls.setTimeout(10);
  HTTPClient http;
  http.setTimeout(10000);
  http.setReuse(false);
  if (!http.begin(tls, base + "/device/v1" + path)) return r;
  http.addHeader("Accept", "application/json");
  if (authorization.length()) http.addHeader("Authorization", authorization);
  if (idempotent) http.addHeader("Idempotency-Key", uuid4());

  String payload;
  if (body) {
    serializeJson(*body, payload);
    http.addHeader("Content-Type", "application/json");
  }
  r.status = http.sendRequest(method, payload);
  if (r.status < 0) {
    r.status = 0;
  } else {
    String text = http.getString();
    if (text.length() && deserializeJson(r.body, text)) r.body.clear();
  }
  http.end();
  return r;
}

}  // namespace api
