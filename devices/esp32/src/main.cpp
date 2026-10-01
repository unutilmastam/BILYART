// Billiard light controller (DEVICE_PROTOCOL.md): one ESP32 per branch, one relay channel per
// table lamp. Two tasks:
//  - loop() on core 1: relays + local timers, portal, button, LED. Never blocks → every lamp
//    goes OFF at its endAt even while the network task is stuck in a slow HTTPS call.
//  - netTask on core 0: Wi-Fi, pairing, /state, /poll, /ack, OTA.
#include <Arduino.h>
#include <WiFi.h>
#include <esp_system.h>
#include <esp_task_wdt.h>

#include "Api.h"
#include "Board.h"
#include "BootRecovery.h"
#include "Backoff.h"
#include "Ota.h"
#include "Portal.h"
#include "Protocol.h"
#include "Shared.h"
#include "Store.h"

Shared g;
SemaphoreHandle_t gLock;

// Version marker inside the binary: the server refuses an upload whose marker differs from the
// version typed in the admin panel (otherwise a rollout could never converge).
extern "C" __attribute__((used)) const char kFirmwareMarker[] = "BLYFWVER:" FW_VERSION "\n";

namespace {

constexpr uint32_t kWatchdogSec = 30;
constexpr uint32_t kBootTimeWaitMs = 20000;           // §5.4: wait this long for trusted time
constexpr uint32_t kCheckpointMs = 30000;             // NVS remainingSec checkpoint while ON
constexpr uint32_t kOtaConfirmDeadlineMs = 10 * 60000;  // new image must reach the server within 10 min

String hardwareId;
store::SavedSession saved[bl::kMaxChannels];  // index = channel − 1
volatile bool bootResolved = false;

void relay(int channel, bool on) { digitalWrite(kRelayPins[channel - 1], (on == kRelayActiveHigh) ? HIGH : LOW); }
void allRelaysOff() {
  for (int ch = 1; ch <= kChannels; ch++) relay(ch, false);
}

const char* bootReason() {
  switch (esp_reset_reason()) {
    case ESP_RST_POWERON: return "POWERON";
    case ESP_RST_TASK_WDT:
    case ESP_RST_INT_WDT:
    case ESP_RST_WDT: return "WDT";
    case ESP_RST_PANIC: return "PANIC";
    case ESP_RST_BROWNOUT: return "BROWNOUT";
    case ESP_RST_SW: return "SW";
    default: return "OTHER";
  }
}

String efuseHardwareId() {
  const uint64_t mac = ESP.getEfuseMac();
  char buf[13];
  for (int i = 0; i < 6; i++) snprintf(buf + i * 2, 3, "%02X", static_cast<unsigned>((mac >> (8 * i)) & 0xFF));
  return String(buf);
}

/** Writes the channels/config marked in fx to flash. Caller holds no lock. */
void persist(const bl::Effects& fx) {
  struct Snap {
    bl::Session s;
    bool warned;
    int64_t remaining;
  } snap[bl::kMaxChannels];
  bl::Config cfg;
  {
    Lock l;
    const int64_t now = g.clock.now(monoMs());
    for (int ch = 1; ch <= kChannels; ch++) {
      if (!fx.persists(ch)) continue;
      const bl::SessionTimer& t = g.controller.timer(ch);
      snap[ch - 1] = Snap{t.session(), t.warned(), t.remainingSec(now)};
    }
    cfg = g.controller.config();
  }
  for (int ch = 1; ch <= kChannels; ch++)
    if (fx.persists(ch)) store::saveSession(ch, snap[ch - 1].s, snap[ch - 1].warned, snap[ch - 1].remaining);
  if (fx.persistConfig) store::saveConfig(cfg);
}

/**
 * Boot decision for every channel's saved session once trusted time is known (or the wait
 * expired). Caller holds the lock; channels to clear are marked in fx.
 */
void resolveBootLocked(bool timeKnown, bl::Effects& fx) {
  if (bootResolved) return;
  const int32_t cap = g.controller.config().maxSessionSec;
  for (int ch = 1; ch <= kChannels; ch++) {
    const store::SavedSession& sv = saved[ch - 1];
    if (!sv.session.active) continue;
    const bl::BootDecision d = bl::decideBoot(sv.session, sv.remainingSec, timeKnown, g.clock.now(monoMs()), cap);
    if (d.action == bl::BootDecision::Action::Off) {
      fx.persist(ch);
      continue;
    }
    bl::Session s = sv.session;
    if (d.action == bl::BootDecision::Action::ResumeForCheckpoint) {
      // No trusted time: the lamp stays ON for the checkpointed remainingSec only. /state corrects it later.
      if (!g.clock.known()) g.clock.set(s.endAt - d.resumeSec, monoMs());  // one provisional clock for all channels
      s.endAt = g.clock.now(monoMs()) + d.resumeSec;
      if (s.startAt >= s.endAt) s.startAt = s.endAt - 1;
    }
    g.controller.restore(ch, s, sv.warned, g.clock.now(monoMs()));
  }
  bootResolved = true;
}

String deviceAuth() { return "Device " + store::deviceCode() + "." + store::token(); }

void noteServerTime(const api::Response& r) {
  if (r.body["serverTime"].is<int64_t>()) {
    Lock l;
    g.clock.set(r.body["serverTime"].as<int64_t>(), monoMs());
  }
}

void setServerStatus(const String& s) {
  Lock l;
  g.serverStatus = s;
}

// ---- network task ---------------------------------------------------------

bool ensureWifi(bl::Backoff& backoff) {
  bool reconfigure;
  {
    Lock l;
    reconfigure = g.reconfigure;
    g.reconfigure = false;
  }
  const store::Net net = store::net();
  if (reconfigure) WiFi.disconnect();
  if (WiFi.status() == WL_CONNECTED && !reconfigure) return true;
  if (net.ssid.isEmpty()) {
    Lock l;
    g.portalWanted = true;
    g.wifiStatus = "sozlanmagan";
    return false;
  }
  api::setBase(net.apiBase);
  WiFi.begin(net.ssid.c_str(), net.pass.c_str());
  for (int i = 0; i < 150 && WiFi.status() != WL_CONNECTED; i++) {
    esp_task_wdt_reset();
    vTaskDelay(pdMS_TO_TICKS(100));
  }
  if (WiFi.status() != WL_CONNECTED) {
    {
      Lock l;
      g.wifiStatus = "ulanmadi";
    }
    vTaskDelay(pdMS_TO_TICKS(backoff.next()));
    return false;
  }
  backoff.reset();
  Lock l;
  g.wifiStatus = "ulangan (" + String(WiFi.RSSI()) + " dBm)";
  return true;
}

/** Registration + pairing handshake (§2). Returns when paired or on a transient failure. */
void pairDevice() {
  JsonDocument body;
  body["hardwareId"] = hardwareId;
  body["firmwareVersion"] = FW_VERSION;
  body["registrationSecret"] = DEVICE_REGISTRATION_SECRET;
  body["channelCount"] = kChannels;  // the admin wires one table to each channel
  api::Response reg = api::request("POST", "/register", &body, "");
  noteServerTime(reg);
  if (!reg.ok()) {
    setServerStatus(strcmp(reg.errorCode(), "DEVICE_ALREADY_PAIRED") == 0 ? "Admin panelda qurilmani uzib, qayta ulang" : "ro'yxatdan o'tmadi (" + String(reg.status) + ")");
    vTaskDelay(pdMS_TO_TICKS(10000));
    return;
  }
  const String code = reg.body["deviceCode"] | "";
  const String pollToken = reg.body["pollToken"] | "";
  {
    Lock l;
    g.deviceCode = code;
    g.pairingCode = reg.body["pairingCode"] | "";
    g.portalWanted = true;  // the owner reads the code on the setup page
  }
  store::savePairing(code, "");
  setServerStatus("ulash kutilmoqda");

  for (;;) {
    esp_task_wdt_reset();
    vTaskDelay(pdMS_TO_TICKS(3000));
    api::Response st = api::request("GET", "/pairing-status", nullptr, "PollToken " + pollToken);
    noteServerTime(st);
    const char* status = st.body["status"] | "";
    if (st.ok() && strcmp(status, "PAIRED") == 0 && st.body["token"].is<const char*>()) {
      store::savePairing(code, st.body["token"].as<const char*>());
      Lock l;
      g.pairingCode = "";
      g.paired = true;
      return;
    }
    if ((st.ok() && strcmp(status, "EXPIRED") == 0) || (st.status >= 400 && st.status < 500)) return;  // new code
    if (WiFi.status() != WL_CONNECTED) return;
  }
}

bool syncState() {
  api::Response r = api::request("GET", "/state", nullptr, deviceAuth());
  if (!r.ok()) return false;
  bl::Effects fx;
  {
    // Clock, boot decision and server state in one critical section: the relay loop never
    // evaluates a provisional session against the real clock.
    Lock l;
    if (r.body["serverTime"].is<int64_t>()) g.clock.set(r.body["serverTime"].as<int64_t>(), monoMs());
    resolveBootLocked(true, fx);
    g.controller.applyState(r.body.as<JsonObjectConst>(), g.clock.now(monoMs()), fx);
  }
  persist(fx);
  ota::markValid();  // the new image reached the server: keep it
  return true;
}

void handleAuthFailure(const api::Response& r) {
  if (r.status == 401 || r.status == 403) {  // unpaired / revoked in the admin panel
    store::clearPairing();
    Lock l;
    g.paired = false;
  }
}

void runPaired(bl::Backoff& backoff) {
  if (!syncState()) {
    setServerStatus("serverga ulanib bo'lmadi");
    vTaskDelay(pdMS_TO_TICKS(backoff.next()));
    return;
  }
  {
    Lock l;
    g.portalWanted = false;
  }
  setServerStatus("ulangan");
  backoff.reset();

  for (;;) {
    esp_task_wdt_reset();
    if (WiFi.status() != WL_CONNECTED) return;
    {
      Lock l;
      if (g.reconfigure) return;
    }

    JsonDocument body;
    int32_t interval;
    {
      Lock l;
      bl::buildPoll(body, g.clock.now(monoMs()), FW_VERSION, g.controller, WiFi.RSSI(), millis() / 1000, bootReason());
      interval = g.controller.config().pollIntervalSec;
    }
    api::Response r = api::request("POST", "/poll", &body, deviceAuth());
    if (!r.ok()) {
      handleAuthFailure(r);
      if (r.status == 401 || r.status == 403) return;
      setServerStatus("aloqa yo'q (" + String(r.status) + ")");
      vTaskDelay(pdMS_TO_TICKS(backoff.next()));  // local timer keeps running meanwhile
      continue;
    }
    backoff.reset();
    noteServerTime(r);
    setServerStatus("ulangan");

    bl::Ack acks[10];
    size_t n = 0;
    bl::Effects fx;
    {
      Lock l;
      const int64_t serverNow = r.body["serverTime"] | static_cast<int64_t>(0);
      for (JsonObjectConst cmd : r.body["commands"].as<JsonArrayConst>()) {
        if (n == 10) break;
        acks[n++] = g.controller.handle(cmd, serverNow, g.clock.now(monoMs()), monoMs(), fx);
      }
    }
    persist(fx);  // before the ACK: an acknowledged START is always on flash
    if (n) {
      JsonDocument ack;
      bl::buildAck(ack, acks, n);
      api::Response a = api::request("POST", "/ack", &ack, deviceAuth(), true);
      noteServerTime(a);
    }
    if (fx.syncState) syncState();
    if (fx.ota) {
      setServerStatus(String("yangilanmoqda ") + fx.otaVersion);
      if (ota::install(store::net().apiBase, deviceAuth(), fx.otaVersion, fx.otaSha256, fx.otaSize) == ota::Result::Ok) {
        allRelaysOff();
        ESP.restart();
      }
      setServerStatus("yangilash muvaffaqiyatsiz");
    }
    vTaskDelay(pdMS_TO_TICKS(interval * 1000));
  }
}

void netTask(void*) {
  esp_task_wdt_add(nullptr);
  bl::Backoff backoff;
  for (;;) {
    esp_task_wdt_reset();
    if (!ensureWifi(backoff)) {
      vTaskDelay(pdMS_TO_TICKS(500));
      continue;
    }
    if (store::token().isEmpty()) {
      {
        Lock l;
        g.paired = false;
        g.portalWanted = true;  // status + pairing code stay visible even if the server is unreachable
      }
      pairDevice();
      continue;
    }
    {
      Lock l;
      g.paired = true;
      g.deviceCode = store::deviceCode();
    }
    runPaired(backoff);
  }
}

// ---- relay loop helpers ---------------------------------------------------

uint32_t portalUntil = 0;  // button-requested portal window

void checkButton() {
  static uint32_t pressedAt = 0;
  if (digitalRead(kButtonPin) == LOW) {
    if (!pressedAt) pressedAt = millis();
    if (millis() - pressedAt > 10000) {  // factory reset: forget Wi-Fi and pairing (physical access only)
      allRelaysOff();
      store::factoryReset();
      ESP.restart();
    }
  } else {
    if (pressedAt && millis() - pressedAt > 3000) portalUntil = millis() + 10 * 60000;  // 3 s press: setup page for 10 min
    pressedAt = 0;
  }
}

void updateLed(bool paired, bool online) {
  const uint32_t t = millis();
  bool on;
  if (!paired) on = (t / 150) % 2;          // fast blink: setup / pairing
  else if (!online) on = (t / 1000) % 2;   // slow blink: no server
  else on = true;                           // steady: all good
  digitalWrite(kLedPin, on ? HIGH : LOW);
}

}  // namespace

void setup() {
  // 1. Every lamp OFF before anything else (§5.1).
  for (int ch = 1; ch <= kChannels; ch++) pinMode(kRelayPins[ch - 1], OUTPUT);
  allRelaysOff();
  pinMode(kLedPin, OUTPUT);
  pinMode(kButtonPin, INPUT_PULLUP);

  Serial.begin(115200);
  // 2. Watchdog: a hung task reboots the controller (the relay pin defaults to OFF on reset).
  esp_task_wdt_init(kWatchdogSec, true);
  esp_task_wdt_add(nullptr);

  gLock = xSemaphoreCreateMutex();
  store::begin();
  hardwareId = efuseHardwareId();

  // 3. Config + saved sessions (one per relay channel).
  g.controller.config() = store::config();
  bool anySaved = false;
  for (int ch = 1; ch <= kChannels; ch++) {
    saved[ch - 1] = store::session(ch);
    anySaved = anySaved || saved[ch - 1].session.active;
  }
  g.deviceCode = store::deviceCode().length() ? store::deviceCode() : "ESP32-" + hardwareId.substring(6);

  Serial.print(kFirmwareMarker);  // also keeps the marker in the linked image
  Serial.printf("\nBilyart ESP32 %s | hardwareId %s | device %s | %d channels | boot %s\n", FW_VERSION, hardwareId.c_str(), g.deviceCode.c_str(), kChannels, bootReason());
  Serial.printf("Setup Wi-Fi: BILLIARD-%s  password: %s  (write this on the device label)\n", g.deviceCode.substring(g.deviceCode.length() - 4).c_str(), store::portalPassword().c_str());
  if (strlen(DEVICE_REGISTRATION_SECRET) < 16) Serial.println("WARNING: firmware built without DEVICE_REGISTRATION_SECRET — registration will be refused.");

  if (!anySaved) bootResolved = true;
  WiFi.mode(WIFI_STA);
  xTaskCreatePinnedToCore(netTask, "net", 12288, nullptr, 1, nullptr, 0);
}

void loop() {
  esp_task_wdt_reset();
  checkButton();
  bool wantPortal;
  String code;
  {
    Lock l;
    wantPortal = g.portalWanted || (portalUntil && millis() < portalUntil);
    code = g.deviceCode;
  }
  if (wantPortal && !portal::running()) portal::start(code);
  if (!wantPortal && portal::running()) portal::stop();
  portal::handle();

  // 4. Saved sessions but no trusted time after 20 s: resume from the checkpoint (§5.4).
  if (!bootResolved && millis() > kBootTimeWaitMs) {
    bl::Effects fx;
    {
      Lock l;
      resolveBootLocked(false, fx);
    }
    persist(fx);
  }
  // OTA safety: a new image that cannot reach the server is rolled back.
  if (millis() > kOtaConfirmDeadlineMs && ota::pendingVerify()) ota::rollback();

  bl::Controller::Output out;  // all OFF until the boot decision is made
  bool anyActive = false, paired, online;
  uint8_t warnedMask = 0;
  int64_t remaining[bl::kMaxChannels] = {};
  {
    Lock l;
    if (bootResolved && g.clock.known()) {
      const int64_t now = g.clock.now(monoMs());
      out = g.controller.tick(now, monoMs());
      for (int ch = 1; ch <= kChannels; ch++) {
        const bl::SessionTimer& t = g.controller.timer(ch);
        anyActive = anyActive || t.active();
        if (t.warned()) warnedMask |= static_cast<uint8_t>(1u << (ch - 1));
        remaining[ch - 1] = t.remainingSec(now);
      }
    }
    paired = g.paired;
    online = g.serverStatus == "ulangan";
  }
  for (int ch = 1; ch <= kChannels; ch++) relay(ch, out.relayOn[ch - 1]);  // local fail-safe: OFF at endAt with or without network
  if (out.ended) {
    bl::Effects fx;
    fx.persistChannels = out.ended;
    persist(fx);
  }
  static uint32_t lastCheckpoint = 0;
  if (anyActive && millis() - lastCheckpoint > kCheckpointMs) {
    store::checkpoint(remaining, warnedMask, kChannels);
    lastCheckpoint = millis();
  }
  updateLed(paired, online);
  delay(20);
}
