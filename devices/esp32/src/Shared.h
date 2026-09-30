#pragma once
#include <Arduino.h>
#include <freertos/FreeRTOS.h>
#include <freertos/semphr.h>

#include "Controller.h"
#include "EpochClock.h"

/**
 * State shared between the relay loop (core 1, never blocks) and the network
 * task (core 0, blocking HTTPS). Every access goes through Lock.
 */
struct Shared {
  bl::Controller controller;
  bl::EpochClock clock;
  String deviceCode;
  String pairingCode;  // shown on the setup portal while waiting for the admin
  String wifiStatus = "—";
  String serverStatus = "—";
  bool paired = false;
  bool reconfigure = false;  // portal saved new Wi-Fi settings
  bool portalWanted = false; // set by the network task; the loop task owns the portal itself
};

extern Shared g;
extern SemaphoreHandle_t gLock;

struct Lock {
  Lock() { xSemaphoreTake(gLock, portMAX_DELAY); }
  ~Lock() { xSemaphoreGive(gLock); }
  Lock(const Lock&) = delete;
  Lock& operator=(const Lock&) = delete;
};

inline uint64_t monoMs() { return static_cast<uint64_t>(esp_timer_get_time() / 1000); }
