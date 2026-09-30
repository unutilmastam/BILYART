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

SavedSession session() {
  SavedSession out;
  String id = prefs.getString("s_id", "");
  if (id.length() != bl::kIdLen) return out;
  out.session.setId(id.c_str());
  out.session.startAt = prefs.getLong64("s_start", 0);
  out.session.endAt = prefs.getLong64("s_end", 0);
  out.session.warnBeforeSec = prefs.getInt("s_warnb", 300);
  out.session.flashCount = prefs.getInt("s_flash", 3);
  out.session.active = true;
  out.warned = prefs.getBool("s_warned", false);
  out.remainingSec = prefs.getLong64("s_rem", 0);
  return out;
}

void saveSession(const bl::Session& s, bool warned, int64_t remainingSec) {
  if (!s.active) {
    prefs.remove("s_id");
    prefs.remove("s_rem");
    return;
  }
  prefs.putString("s_id", s.id);
  prefs.putLong64("s_start", s.startAt);
  prefs.putLong64("s_end", s.endAt);
  prefs.putInt("s_warnb", s.warnBeforeSec);
  prefs.putInt("s_flash", s.flashCount);
  prefs.putBool("s_warned", warned);
  prefs.putLong64("s_rem", remainingSec);
}

void checkpoint(int64_t remainingSec, bool warned) {
  prefs.putLong64("s_rem", remainingSec);
  prefs.putBool("s_warned", warned);
}

void factoryReset() {
  // Forget Wi-Fi, pairing, config and session — but keep the setup password printed on the label.
  const String pw = portalPassword();
  prefs.clear();
  prefs.putString("appw", pw);
}

}  // namespace store
