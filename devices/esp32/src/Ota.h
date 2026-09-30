#pragma once
#include <Arduino.h>

/**
 * OTA (DEVICE_PROTOCOL.md §8): download over pinned TLS with the device token,
 * verify size + SHA-256 before switching, dual partitions with rollback — the
 * new image is marked valid only after it reached the server (/state).
 */
namespace ota {
enum class Result { Ok, DownloadFailed, BadSize, BadHash, FlashFailed };
Result install(const String& apiBase, const String& auth, const char* version, const char* sha256Hex, int64_t size);

/** True while the running image still waits for confirmation. */
bool pendingVerify();
void markValid();
/** Called when the new image cannot reach the server for too long: back to the previous image. */
void rollback();
}  // namespace ota
