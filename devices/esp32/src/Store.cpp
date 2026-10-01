#include "Store.h"

#include <Preferences.h>
#include <esp_random.h>

namespace store {
namespace {
Preferences prefs;
}

void begin() { prefs.begin("bl", false); }

Net net() { return Net{prefs.getString("ssid", ""), prefs.getString("pass", ""), prefs.getString("api", DEFAULT_API_BASE)}; }

void saveNet(const String& ssid, const String& pass, const String& apiBase) {
  prefs.putString("ssid", ssid);
  prefs.putString("pass", pass);
  prefs.putString("api", apiBase);
}

String token() { return prefs.getString("token", ""); }
String deviceCode() { return prefs.getString("code", ""); }

void savePairing(const String& code, const String& tok) {
  prefs.putString("code", code);
  prefs.putString("token", tok);
}

void clearPairing() { prefs.remove("token"); }

String portalPassword() {
  String p = prefs.getString("appw", "");
  if (p.length() != 8) {
    char buf[9];
    snprintf(buf, sizeof buf, "%08lu", static_cast<unsigned long>(esp_random() % 100000000UL));
    p = buf;
    prefs.putString("appw", p);
  }
  return p;
}

bl::Config config() {
  bl::Config c;
  c.pollIntervalSec = prefs.getInt("c_poll", c.pollIntervalSec);
  c.maxSessionSec = prefs.getInt("c_max", c.maxSessionSec);
  c.warnBeforeSec = prefs.getInt("c_warn", c.warnBeforeSec);
  c.flashCount = prefs.getInt("c_flash", c.flashCount);
  return c;
}

void saveConfig(const bl::Config& c) {
  prefs.putInt("c_poll", c.pollIntervalSec);
  prefs.putInt("c_max", c.maxSessionSec);
  prefs.putInt("c_warn", c.warnBeforeSec);
  prefs.putInt("c_flash", c.flashCount);
}

namespace {
// Per-channel NVS keys (max 15 chars): s<ch>_id, s<ch>_st, s<ch>_end, s<ch>_wb, s<ch>_fl.
String key(int ch, const char* field) { return "s" + String(ch) + "_" + field; }

/** remainingSec + warned of all channels in one blob, so a checkpoint is a single NVS write. */
struct Checkpoint {
  int32_t remaining[bl::kMaxChannels];
  uint8_t warned;
};
Checkpoint ckpt{};
bool ckptLoaded = false;

Checkpoint& loadCheckpoint() {
  if (!ckptLoaded) {
    if (prefs.getBytesLength("s_ckpt") != sizeof(Checkpoint) || prefs.getBytes("s_ckpt", &ckpt, sizeof ckpt) != sizeof ckpt) ckpt = Checkpoint{};
    ckptLoaded = true;
  }
  return ckpt;
}

void writeCheckpoint() { prefs.putBytes("s_ckpt", &ckpt, sizeof ckpt); }

/** Firmware ≤ 1.x kept one session under s_*: it becomes channel 1. */
void migrateLegacySession() {
  const String id = prefs.getString("s_id", "");
  if (id.length() != bl::kIdLen) return;
  prefs.putString(key(1, "id").c_str(), id);
  prefs.putLong64(key(1, "st").c_str(), prefs.getLong64("s_start", 0));
  prefs.putLong64(key(1, "end").c_str(), prefs.getLong64("s_end", 0));
  prefs.putInt(key(1, "wb").c_str(), prefs.getInt("s_warnb", 300));
  prefs.putInt(key(1, "fl").c_str(), prefs.getInt("s_flash", 3));
  Checkpoint& c = loadCheckpoint();
  c.remaining[0] = static_cast<int32_t>(prefs.getLong64("s_rem", 0));
  if (prefs.getBool("s_warned", false)) c.warned |= 1;
  writeCheckpoint();
  for (const char* k : {"s_id", "s_start", "s_end", "s_warnb", "s_flash", "s_warned", "s_rem"}) prefs.remove(k);
}
}  // namespace

SavedSession session(int ch) {
  SavedSession out;
  if (ch < 1 || ch > bl::kMaxChannels) return out;
  if (ch == 1) migrateLegacySession();
  const String id = prefs.getString(key(ch, "id").c_str(), "");
  if (id.length() != bl::kIdLen) return out;
  out.session.setId(id.c_str());
  out.session.startAt = prefs.getLong64(key(ch, "st").c_str(), 0);
  out.session.endAt = prefs.getLong64(key(ch, "end").c_str(), 0);
  out.session.warnBeforeSec = prefs.getInt(key(ch, "wb").c_str(), 300);
  out.session.flashCount = prefs.getInt(key(ch, "fl").c_str(), 3);
  out.session.active = true;
  const Checkpoint& c = loadCheckpoint();
  out.warned = c.warned & (1u << (ch - 1));
  out.remainingSec = c.remaining[ch - 1];
  return out;
}

void saveSession(int ch, const bl::Session& s, bool warned, int64_t remainingSec) {
  if (ch < 1 || ch > bl::kMaxChannels) return;
  Checkpoint& c = loadCheckpoint();
  const uint8_t bit = static_cast<uint8_t>(1u << (ch - 1));
  if (!s.active) {
    prefs.remove(key(ch, "id").c_str());
    c.remaining[ch - 1] = 0;
    c.warned &= static_cast<uint8_t>(~bit);
    writeCheckpoint();
    return;
  }
  prefs.putString(key(ch, "id").c_str(), s.id);
  prefs.putLong64(key(ch, "st").c_str(), s.startAt);
  prefs.putLong64(key(ch, "end").c_str(), s.endAt);
  prefs.putInt(key(ch, "wb").c_str(), s.warnBeforeSec);
  prefs.putInt(key(ch, "fl").c_str(), s.flashCount);
  c.remaining[ch - 1] = static_cast<int32_t>(remainingSec);
  if (warned) c.warned |= bit;
  else c.warned &= static_cast<uint8_t>(~bit);
  writeCheckpoint();
}

void checkpoint(const int64_t* remainingSec, uint8_t warnedMask, int channels) {
  Checkpoint& c = loadCheckpoint();
  for (int i = 0; i < channels && i < bl::kMaxChannels; i++) c.remaining[i] = static_cast<int32_t>(remainingSec[i]);
  c.warned = warnedMask;
  writeCheckpoint();
}

void factoryReset() {
  // Forget Wi-Fi, pairing, config and session — but keep the setup password printed on the label.
  const String pw = portalPassword();
  prefs.clear();
  prefs.putString("appw", pw);
  ckpt = Checkpoint{};
  ckptLoaded = true;
}

}  // namespace store
