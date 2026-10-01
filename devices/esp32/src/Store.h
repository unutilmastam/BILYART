#pragma once
#include <Arduino.h>
#include "Types.h"

/** NVS-backed persistence (Preferences). Secrets stay on the device; nothing here is logged. */
namespace store {

struct Net {
  String ssid, pass, apiBase;
};

void begin();
Net net();
void saveNet(const String& ssid, const String& pass, const String& apiBase);

String token();
String deviceCode();
void savePairing(const String& deviceCode, const String& token);
void clearPairing();

/** 8-digit password of the setup Wi-Fi, generated on first boot and printed on the serial console (write it on the device label). */
String portalPassword();

bl::Config config();
void saveConfig(const bl::Config& c);

/** A relay channel's session as written to flash (channel 1..bl::kMaxChannels). */
struct SavedSession {
  bl::Session session;
  bool warned = false;
  int64_t remainingSec = 0;
};
SavedSession session(int channel);
void saveSession(int channel, const bl::Session& s, bool warned, int64_t remainingSec);
/** Remaining time + warned flag of every running channel in one small write (every 30 s while any lamp is ON). */
void checkpoint(const int64_t* remainingSec, uint8_t warnedMask, int channels);

/** Clears everything except the setup-portal password (it is written on the device label). */
void factoryReset();

}  // namespace store
