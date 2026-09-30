#include "Ota.h"

#include <HTTPClient.h>
#include <Update.h>
#include <WiFiClientSecure.h>
#include <esp_ota_ops.h>
#include <esp_task_wdt.h>
#include <mbedtls/md.h>

#include "CaBundle.h"

// Arduino core hook: keep the new image in PENDING_VERIFY until we confirm it ourselves.
extern "C" bool verifyRollbackLater() { return true; }

namespace ota {

Result install(const String& apiBase, const String& auth, const char* version, const char* sha256Hex, int64_t size) {
  WiFiClientSecure tls;
  tls.setCACert(kCaBundle);
  HTTPClient http;
  http.setTimeout(20000);
  if (!http.begin(tls, apiBase + "/device/v1/firmware/" + version)) return Result::DownloadFailed;
  http.addHeader("Authorization", auth);
  if (http.GET() != 200) {
    http.end();
    return Result::DownloadFailed;
  }
  const int len = http.getSize();
  if (len <= 0 || len != size) {
    http.end();
    return Result::BadSize;
  }
  if (!Update.begin(len)) {
    http.end();
    return Result::FlashFailed;
  }

  mbedtls_md_context_t md;
  mbedtls_md_init(&md);
  mbedtls_md_setup(&md, mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), 0);
  mbedtls_md_starts(&md);

  WiFiClient* stream = http.getStreamPtr();
  uint8_t buf[2048];
  int remaining = len;
  uint32_t idleSince = millis();
  while (remaining > 0 && millis() - idleSince < 20000) {
    esp_task_wdt_reset();
    const size_t avail = stream->available();
    if (!avail) {
      delay(5);
      continue;
    }
    const int n = stream->readBytes(buf, min(sizeof buf, avail));
    if (n <= 0) continue;
    mbedtls_md_update(&md, buf, n);
    if (Update.write(buf, n) != static_cast<size_t>(n)) break;
    remaining -= n;
    idleSince = millis();
  }
  http.end();

  uint8_t digest[32];
  mbedtls_md_finish(&md, digest);
  mbedtls_md_free(&md);
  char hex[65];
  for (int i = 0; i < 32; i++) snprintf(hex + i * 2, 3, "%02x", digest[i]);

  if (remaining != 0) {
    Update.abort();
    return Result::DownloadFailed;
  }
  if (strcasecmp(hex, sha256Hex) != 0) {
    Update.abort();  // never boot an image we did not verify
    return Result::BadHash;
  }
  if (!Update.end(true)) return Result::FlashFailed;
  return Result::Ok;
}

bool pendingVerify() {
  esp_ota_img_states_t state;
  const esp_partition_t* running = esp_ota_get_running_partition();
  return esp_ota_get_state_partition(running, &state) == ESP_OK && state == ESP_OTA_IMG_PENDING_VERIFY;
}

void markValid() {
  if (pendingVerify()) esp_ota_mark_app_valid_cancel_rollback();
}

void rollback() {
  if (pendingVerify()) esp_ota_mark_app_invalid_rollback_and_reboot();
}

}  // namespace ota
