#include "Portal.h"

#include <DNSServer.h>
#include <WebServer.h>
#include <WiFi.h>

#include "Shared.h"
#include "Store.h"

namespace portal {
namespace {
WebServer server(80);
DNSServer dns;
bool active = false;

String esc(const String& in) {
  String o;
  o.reserve(in.length() + 8);
  for (char c : in) {
    switch (c) {
      case '&': o += "&amp;"; break;
      case '<': o += "&lt;"; break;
      case '>': o += "&gt;"; break;
      case '"': o += "&quot;"; break;
      case '\'': o += "&#39;"; break;
      default: o += c;
    }
  }
  return o;
}

void page() {
  String code, pairing, wifi, srv;
  bool paired;
  {
    Lock l;
    code = g.deviceCode;
    pairing = g.pairingCode;
    wifi = g.wifiStatus;
    srv = g.serverStatus;
    paired = g.paired;
  }
  const store::Net n = store::net();
  String h;
  h.reserve(2600);
  h += F("<!doctype html><html lang='uz'><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>"
         "<title>Bilyart qurilma</title><style>body{font-family:sans-serif;margin:16px;max-width:520px}input,button{font-size:18px;width:100%;padding:10px;margin:6px 0;box-sizing:border-box}"
         ".code{font-size:44px;font-weight:bold;letter-spacing:6px}.box{border:2px solid #047857;border-radius:12px;padding:12px;margin:12px 0}</style>");
  h += "<h2>Qurilma: " + esc(code) + "</h2>";
  h += "<p>Wi-Fi: " + esc(wifi) + "<br>Server: " + esc(srv) + "<br>Dastur: " FW_VERSION "<br>Kanallar: " + String(kChannels) + "</p>";
  if (paired) {
    h += F("<div class='box'>Qurilma ulangan. Bu sahifa endi kerak emas.</div>");
  } else if (pairing.length()) {
    h += "<div class='box'>Ulash kodi:<div class='code'>" + esc(pairing) + "</div>Admin panel → Qurilmalar → ESP32 ulash → shu kod va filialni tanlang. Keyin Stollar sahifasida har bir stolga kanal tanlang.</div>";
  }
  h += F("<form method='post' action='/save'><h3>Wi-Fi sozlamasi</h3><label>Wi-Fi nomi (SSID)<input name='ssid' maxlength='32' required value='");
  h += esc(n.ssid);
  h += F("'></label><label>Wi-Fi paroli<input name='pass' type='password' maxlength='64' placeholder='(o&#39;zgartirmaslik uchun bo&#39;sh qoldiring)'></label><label>Server manzili<input name='api' maxlength='100' value='");
  h += esc(n.apiBase);
  h += F("'></label><button>Saqlash</button></form></html>");
  server.send(200, "text/html; charset=utf-8", h);
}

void save() {
  String ssid = server.arg("ssid");
  String pass = server.arg("pass");
  String apiBase = server.arg("api");
  ssid.trim();
  apiBase.trim();
  if (ssid.isEmpty() || ssid.length() > 32 || pass.length() > 64 || !apiBase.startsWith("https://") || apiBase.length() > 100) {
    server.send(400, "text/html; charset=utf-8", F("<meta charset='utf-8'><p>Ma'lumot noto'g'ri. Server manzili https:// bilan boshlanishi kerak.</p><a href='/'>Orqaga</a>"));
    return;
  }
  if (pass.isEmpty()) pass = store::net().pass;  // keep the old password
  store::saveNet(ssid, pass, apiBase);
  {
    Lock l;
    g.reconfigure = true;
  }
  server.send(200, "text/html; charset=utf-8", F("<meta charset='utf-8'><meta http-equiv='refresh' content='8;url=/'><p>Saqlandi. Ulanmoqda…</p>"));
}
}  // namespace

void start(const String& deviceCode) {
  if (active) return;
  WiFi.mode(WIFI_AP_STA);
  const String ssid = "BILLIARD-" + deviceCode.substring(deviceCode.length() - 4);
  WiFi.softAP(ssid.c_str(), store::portalPassword().c_str());
  dns.start(53, "*", WiFi.softAPIP());
  server.on("/", HTTP_GET, page);
  server.on("/save", HTTP_POST, save);
  server.onNotFound([] {  // captive portal: every URL leads to the setup page
    server.sendHeader("Location", "http://" + WiFi.softAPIP().toString() + "/", true);
    server.send(302, "text/plain", "");
  });
  server.begin();
  active = true;
}

void stop() {
  if (!active) return;
  server.stop();
  dns.stop();
  WiFi.softAPdisconnect(true);
  WiFi.mode(WIFI_STA);
  active = false;
}

bool running() { return active; }

void handle() {
  if (!active) return;
  dns.processNextRequest();
  server.handleClient();
}

}  // namespace portal
