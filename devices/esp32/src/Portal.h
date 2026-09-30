#pragma once
#include <Arduino.h>

/**
 * Setup Wi-Fi + captive portal (DEVICE_PROTOCOL.md §2): SSID "BILLIARD-<last4>",
 * WPA2 password from store::portalPassword(). Lets the owner enter the hall
 * Wi-Fi and read the pairing code from a phone. Off once the device is paired.
 */
namespace portal {
void start(const String& deviceCode);
void stop();
bool running();
void handle();  // call from the loop
}  // namespace portal
