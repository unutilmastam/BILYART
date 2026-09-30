#pragma once
// Pin map for an ESP32 DevKit v1 (docs/HARDWARE.md). Relay pin is not a strapping pin
// and has an external pull-down, so the light is OFF while the chip boots or is unpowered.
constexpr int kRelayPin = 26;    // HIGH = relay energised = light ON (NO contact)
constexpr int kLedPin = 2;       // on-board status LED
constexpr int kButtonPin = 0;    // BOOT button: hold 10 s = factory reset (physical access only)
constexpr bool kRelayActiveHigh = true;
