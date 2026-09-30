# DEVICE PROTOCOL (v1 — HTTPS polling)

Message schemas: `packages/protocol/schemas/device.*.schema.json` (single source; server tests validate against them).

Base URL: `https://<domain>/device/v1`. TLS required; firmware validates the server certificate against a pinned root CA bundle (ISRG Root X1 + backup). All bodies JSON. All times = **Unix epoch seconds, UTC**.

## 1. Identity
- `hardwareId` = ESP32 eFuse MAC (hex). `deviceCode` = `ESP32-` + last 6 hex chars (shown in admin).
- After pairing the device holds a **device token** (random 32 bytes, base64url). Server stores only its SHA-256 hash.
- Auth header on every call after pairing: `Authorization: Device <deviceCode>.<token>`.
- Server resolves tenant/branch/table **only** from the token. Any tenant/branch/table fields sent by the device are ignored.

## 2. Provisioning & pairing
1. First boot (no Wi-Fi creds): AP `BILLIARD-<last4>` + captive portal (password printed on the device label).
2. Owner's phone connects → portal form: Wi-Fi SSID/password → device joins Wi-Fi.
3. `POST /register` `{hardwareId, firmwareVersion, registrationSecret}` → `{deviceCode, pairingCode, pairingExpiresAt, pollToken, serverTime}`
   - `registrationSecret` = per-build secret to stop random internet clients from spamming registrations (rate-limited per IP too). It is **not** trusted as ownership proof.
   - `pairingCode` = 6 digits, 15 min TTL, max 5 wrong attempts, stored hashed. Portal page shows it.
4. Owner: Admin → Devices → Add device → code + branch + table → confirm. Server binds device to tenant/branch/table, audit `device.paired`.
5. Device polls `GET /pairing-status` (header `Authorization: PollToken <pollToken>`) every 3 s → `{status: WAITING|PAIRED|EXPIRED, serverTime}` → once bound: `{status:"PAIRED", token}` returned **exactly once**. Device stores token in NVS (encrypted NVS partition if enabled).
6. Unpair (admin) → token revoked, device goes back to step 3 on next call (401 → `REPAIR_REQUIRED`). Re-pairing to another tenant requires physical access (portal) — spec §17.

## 3. Endpoints
| Method | Path | Purpose |
|---|---|---|
| POST | `/register` | see above (rate-limited) |
| GET | `/pairing-status` | pairing handshake |
| POST | `/poll` | heartbeat + fetch pending commands (main loop, every 3 s) |
| POST | `/ack` | acknowledge one or more commands |
| GET | `/state` | authoritative state after boot/reconnect (SYNC) |
| GET | `/firmware/{version}` | OTA binary (auth required, only published versions) |

### `/poll` request (= heartbeat)
```json
{ "ts": 1790000000, "fw": "1.0.0", "state": "ON|OFF|WARNING",
  "sessionId": "01J...|null", "endAt": 1790003600, "rssi": -61, "uptime": 86400,
  "bootReason": "POWERON|WDT|OTA|...", "lastAppliedCommandId": "01J..." }
```
### `/poll` response
```json
{ "serverTime": 1790000001,
  "commands": [
    { "commandId": "01J...", "type": "START_SESSION", "expiresAt": 1790000030,
      "payload": { "sessionId": "01J...", "startAt": 1790000000, "endAt": 1790003600,
                   "warnBeforeSec": 300, "flashCount": 3 } } ],
  "pollIntervalSec": 3,
  "ota": null }
```
Server marks returned commands `SENT`. Heartbeat updates `devices.last_seen_at`; device is **ONLINE** if `now − last_seen_at ≤ 15 s` (configurable), otherwise OFFLINE (real status, never faked).

### Commands
| type | payload | device behaviour |
|---|---|---|
| START_SESSION | sessionId, startAt, endAt, warnBeforeSec, flashCount | persist to NVS, relay ON, schedule warning + OFF |
| STOP_SESSION | sessionId | relay OFF, clear NVS session (ignore if sessionId differs) |
| WARNING | sessionId | flash N times now (manual trigger) |
| SYNC | – | call `/state` and apply |
| PING | – | ACK only |
| CONFIG_UPDATE | pollIntervalSec, heartbeat settings, maxSessionSec | persist config |
| OTA | version, sha256, size | download `/firmware/{version}`, verify sha256, `esp_https_ota`, reboot |

Rules: device **ignores commands whose `expiresAt` < serverTime**; commands are **idempotent** by `commandId` (last 16 applied IDs kept in RAM/NVS).

### `/ack`
`{ "acks": [ { "commandId": "01J...", "result": "OK|ERROR|IGNORED", "error": null, "state": "ON" } ] }` → `{serverTime, accepted: [commandId…]}`; command `ACKNOWLEDGED` (OK/IGNORED — IGNORED = duplicate or expired, still counts as delivered) / `FAILED` (ERROR). START ack moves session STARTING → ACTIVE.

### Retry policy (server)
A `SENT` command without ACK is re-delivered on next poll after 6 s, max 3 attempts, then `FAILED` (START → session FAILED + STOP queued + notification). A command past `expiresAt` → `EXPIRED`.

## 4. Local session logic (firmware)
- Time source: NTP (`pool.ntp.org` + server `serverTime` fallback). `now` must be known before applying endAt.
- `warnAt = endAt − warnBeforeSec` → flash `flashCount` times (e.g. 300 ms OFF / 300 ms ON), then stay ON.
- At `endAt` → relay OFF, state OFF, clear session in NVS. **This happens without network.**
- Every 30 s while ON, write `remainingSec` checkpoint to NVS (wear-limited).
- Hard cap: `maxSessionSec` (default 12 h) — light is never ON longer than this, whatever happens.

## 5. Boot sequence (spec §53)
1. GPIO relay pin set OFF first thing (pin with pull-down, not a strapping pin; relay module wired so un-powered = OFF).
2. Start task watchdog (e.g. 30 s).
3. Load config + session from NVS.
4. If session exists: wait up to 20 s for NTP/server time.
   - time known & now < endAt → relay ON, continue.
   - time known & now ≥ endAt → stay OFF, clear.
   - time unknown after 20 s → resume ON for `remainingSec` checkpoint (conservative; bounded by maxSessionSec).
5. Connect Wi-Fi (exponential backoff 1→30 s), authenticate.
6. `GET /state` → server state is authoritative (e.g. session stopped early while offline → OFF).
7. Start poll loop.

## 6. `/state` response
`{ "serverTime": …, "session": { "sessionId", "startAt", "endAt", "status" } | null, "config": {…} }`

## 6a. Implementation notes (server, Phase 10)
- `registrationSecret` = `DEVICE_REGISTRATION_SECRET` (same value compiled into the firmware build via a GitHub secret).
- Re-registering hardware that is still PAIRED → 409 `DEVICE_ALREADY_PAIRED` (unpair in admin first). An UNPAIRED device that re-registers gets a fresh code; old codes expire.
- `/ack` requires an `Idempotency-Key` header (random per ACK batch). `/poll` is exempt: it is a heartbeat and re-delivered commands are de-duplicated by `commandId` on the device.
- Heartbeat rows are stored on state change or every 60 s (not every poll) to keep the database small; `devices.last_seen_at` is updated on every poll.
- Device endpoints keep working when the client's subscription is inactive.

## 7. Error codes
401 `DEVICE_UNAUTHORIZED` / `REPAIR_REQUIRED`, 403 `DEVICE_REVOKED`, 429 rate limited (device backs off), 5xx → retry with backoff, keep local timer.

## 8. OTA
Only Super Admin publishes `firmware_releases` (sha256 computed server-side at upload). Device downloads only through authenticated endpoint over pinned TLS, verifies sha256, uses dual OTA partitions with rollback (mark valid only after successful `/state` call post-reboot). Never during an active session unless forced.

## 9. Future MQTT adapter (not v1)
Topic `t/{deviceCode}/cmd` (downlink only), same command JSON. Uplink stays HTTPS. Selected per deployment by `DEVICE_TRANSPORT=http|mqtt`.
