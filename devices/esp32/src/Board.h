#pragma once
#include "Types.h"

// Pin map for an ESP32 DevKit v1 (docs/HARDWARE.md). One ESP32 per branch: relay channel N
// switches the contactor of the table wired to channel N in the admin panel. None of these pins
// is a boot-strapping pin or toggles during boot; each has a 10 kΩ pull-down, so every lamp is
// OFF while the chip boots or is unpowered.
#ifndef RELAY_CHANNELS
#define RELAY_CHANNELS 4  // relay channels on this board (1..8); set per build in platformio.ini
#endif
static_assert(RELAY_CHANNELS >= 1 && RELAY_CHANNELS <= bl::kMaxChannels, "RELAY_CHANNELS must be 1..8");

constexpr int kChannels = RELAY_CHANNELS;
constexpr int kRelayPins[bl::kMaxChannels] = {26, 27, 25, 33, 32, 23, 22, 21};  // channel 1..8
constexpr int kLedPin = 2;       // on-board status LED
constexpr int kButtonPin = 0;    // BOOT button: hold 10 s = factory reset (physical access only)
constexpr bool kRelayActiveHigh = true;  // HIGH = relay energised = light ON (NO contact)
