# DEVICE PROTOCOL (v1 — HTTPS polling)

Message schemas: `packages/protocol/schemas/device.*.schema.json` (single source; server tests validate against them).

**Topology (owner decision 2026-10-01):** one ESP32 per branch drives several table lamps — one relay **channel** (1..8, `channelCount` per board, default 4) per table. The device is paired to a **branch**; the admin wires each table to `(device, channel)`. Every per-session message carries its `channel`; channels never affect each other. A branch with more than 8 tables uses a second ESP32.

Base URL: `https://<domain>/device/v1`. TLS required; firmware validates the server certificate against a pinned root CA bundle (ISRG Root X1 + backup). All bodies JSON. All times = **Unix epoch seconds, UTC**.

## 1. Identity
- `hardwareId` = ESP32 eFuse MAC (hex). `deviceCode` = `ESP32-` + last 6 hex chars (shown in admin).
- After pairing the device holds a **device token** (random 32 bytes, base64url). Server stores only its SHA-256 hash.
- Auth header on every call after pairing: `Authorization: Device <deviceCode>.<token>`.
- Server resolves tenant/branch (and, through the admin's wiring, the table of each channel) **only** from the token. Any tenant/branch/table fields sent by the device are ignored.

## 2. Provisioning & pairing
1. First boot (no Wi-Fi creds): AP `BILLIARD-<last4>` + captive portal (password printed on the device label).
2. Owner's phone connects → portal form: Wi-Fi SSID/password → device joins Wi-Fi.
3. `POST /register` `{hardwareId, firmwareVersion, registrationSecret, channelCount}` → `{deviceCode, pairingCode, pairingExpiresAt, pollToken, serverTime}`
   - `registrationSecret` = per-build secret to stop random internet clients from spamming registrations (rate-limited per IP too). It is **not** trusted as ownership proof.
   - `pairingCode` = 6 digits, 15 min TTL, max 5 wrong attempts, stored hashed. Portal page shows it.
4. Owner: Admin → Devices → Add device → code + **branch** → confirm. Server binds device to tenant/branch, audit `device.paired`. Then Admin → Tables → each table → "device · N-kanal" (`PATCH /api/admin/tables/{id} {deviceId, deviceChannel}`, audit `table.device_wired`). Rules enforced by the backend + DB: device in the same branch and tenant (composite FK), channel 1..`channelCount`, one table per channel (unique), no rewiring/moving/unpairing while a wired table has a running session.
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
{ "ts": 1790000000, "fw": "1.0.0",
  "channels": [ { "channel": 1, "state": "ON|OFF|WARNING", "sessionId": "01J...|null", "endAt": 1790003600 }, … ],
  "rssi": -61, "uptime": 86400, "bootReason": "POWERON|WDT|OTA|...", "lastAppliedCommandId": "01J..." }
```
One entry per relay channel. The server stores the per-channel states in `devices.last_state` (admin shows each lamp) and a one-letter-per-channel summary (`N` on, `W` warning, `F` off, e.g. `NFWF`) in heartbeat rows.
### `/poll` response
```json
{ "serverTime": 1790000001,
  "commands": [
    { "commandId": "01J...", "type": "START_SESSION", "expiresAt": 1790000030,
      "payload": { "channel": 2, "sessionId": "01J...", "startAt": 1790000000, "endAt": 1790003600,
                   "warnBeforeSec": 300, "flashCount": 3 } } ],
  "pollIntervalSec": 3,
  "ota": null }
```
Server marks returned commands `SENT`. Heartbeat updates `devices.last_seen_at`; device is **ONLINE** if `now − last_seen_at ≤ 15 s` (configurable), otherwise OFFLINE (real status, never faked).

### Commands
| type | payload | device behaviour |
|---|---|---|
| START_SESSION | channel, sessionId, startAt, endAt, warnBeforeSec, flashCount | persist that channel to NVS, its relay ON, schedule warning + OFF |
| STOP_SESSION | channel, sessionId | that channel's relay OFF, clear its NVS session (ignore if sessionId differs) |
| WARNING | channel, sessionId | flash that lamp N times now (manual trigger) |
| SYNC | – | call `/state` and apply |
| PING | – | ACK only |
| CONFIG_UPDATE | pollIntervalSec, heartbeat settings, maxSessionSec | persist config |
| OTA | version, sha256, size | download `/firmware/{version}`, verify sha256, `esp_https_ota`, reboot |

Rules: a channel the board does not have → `ERROR BAD_CHANNEL` (no relay is touched); a new START/STOP supersedes older pending commands of the **same** channel only; device **ignores commands whose `expiresAt` < serverTime**; commands are **idempotent** by `commandId` (last 16 applied IDs kept in RAM/NVS).

### `/ack`
`{ "acks": [ { "commandId": "01J...", "result": "OK|ERROR|IGNORED", "error": null, "channel": 2, "state": "ON" } ] }` (`channel`/`state` = the addressed channel and its light; omitted for device-wide commands) → `{serverTime, accepted: [commandId…]}`; command `ACKNOWLEDGED` (OK/IGNORED — IGNORED = duplicate or expired, still counts as delivered) / `FAILED` (ERROR). START ack moves session STARTING → ACTIVE.

### Retry policy (server)
A `SENT` command without ACK is re-delivered on next poll after 6 s, max 3 attempts, then `FAILED` (START → session FAILED + STOP queued + notification). A command past `expiresAt` → `EXPIRED`.

## 4. Local session logic (firmware)
- Time source: NTP (`pool.ntp.org` + server `serverTime` fallback). `now` must be known before applying endAt.
- `warnAt = endAt − warnBeforeSec` → flash `flashCount` times (e.g. 300 ms OFF / 300 ms ON), then stay ON.
- One timer per channel. At a channel's `endAt` → that relay OFF, state OFF, clear its session in NVS. **This happens without network.**
- Every 30 s while any lamp is ON, write one checkpoint blob (`remainingSec` + warned flag of all channels) to NVS (wear-limited).
- Hard cap: `maxSessionSec` (default 12 h) — light is never ON longer than this, whatever happens.

## 5. Boot sequence (spec §53)
1. Every relay GPIO set OFF first thing (pins with pull-downs, no strapping pins; relay modules wired so un-powered = OFF).
2. Start task watchdog (e.g. 30 s).
3. Load config + the saved session of every channel from NVS.
4. If any session exists: wait up to 20 s for NTP/server time; then per channel:
   - time known & now < endAt → relay ON, continue.
   - time known & now ≥ endAt → stay OFF, clear.
   - time unknown after 20 s → resume ON for `remainingSec` checkpoint (conservative; bounded by maxSessionSec).
5. Connect Wi-Fi (exponential backoff 1→30 s), authenticate.
6. `GET /state` → server state is authoritative (e.g. session stopped early while offline → OFF).
7. Start poll loop.

## 6. `/state` response
`{ "serverTime": …, "sessions": [ { "channel", "sessionId", "startAt", "endAt", "status" }, … ], "config": {…} }` — one entry per channel whose table has a running session; a channel missing from the list is turned OFF.

## 6a. Implementation notes (server, Phase 10)
- `registrationSecret` = `DEVICE_REGISTRATION_SECRET` (same value compiled into the firmware build via a GitHub secret).
- Re-registering hardware that is still PAIRED → 409 `DEVICE_ALREADY_PAIRED` (unpair in admin first). An UNPAIRED device that re-registers gets a fresh code; old codes expire.
- `/ack` requires an `Idempotency-Key` header (random per ACK batch). `/poll` is exempt: it is a heartbeat and re-delivered commands are de-duplicated by `commandId` on the device.
- Heartbeat rows are stored on state change or every 60 s (not every poll) to keep the database small; `devices.last_seen_at` is updated on every poll.
- Device endpoints keep working when the client's subscription is inactive.

## 6b. Implementation notes (firmware, Phase 11 — `devices/esp32`)
- **Split**: `lib/core` is hardware-free C++ (session timer, flasher, command handling + idempotency log, `/state` apply, boot decision, poll/ack builders) and is unit-tested on the host against the protocol examples in `packages/protocol/examples/valid`. `src/` is thin Arduino glue.
- **Two tasks**: the relay loop (core 1) never blocks — it ticks the local timer every 20 ms, so the light turns OFF at `endAt` even while the network task (core 0) sits in a slow HTTPS call. Shared state behind one mutex.
- **Time**: the server's `serverTime` (every response) is the clock source — backend time is authoritative (CLAUDE.md). Before the first sample the time is unknown and nothing is evaluated. §5.4 "time unknown after 20 s" runs one provisional clock and ends each saved session after its own `remainingSec` checkpoint (capped by `maxSessionSec`); the next `/state` replaces them. Setting the real clock, the boot decision and applying `/state` happen in one critical section.
- **Channels**: `RELAY_CHANNELS` build flag (default 4, max 8) = relays on the board, sent as `channelCount` at registration. Relay GPIOs for channels 1..8: 26, 27, 25, 33, 32, 23, 22, 21. NVS keys per channel (`s<N>_id`, `s<N>_st`, `s<N>_end`, `s<N>_wb`, `s<N>_fl`) + one `s_ckpt` blob; the 1-channel keys of older firmware migrate to channel 1.
- **Sessions from `/state`** carry no `warnBeforeSec`/`flashCount`: the device uses `config.warnBeforeSec`/`flashCount`.
- **Commands**: duplicates (last 16 ids) and expired commands → `IGNORED`; START whose `endAt` already passed → `IGNORED`; malformed → `ERROR BAD_PAYLOAD`; unknown type → `ERROR UNKNOWN_COMMAND`; OTA while any channel plays → `ERROR SESSION_ACTIVE`. The session is written to NVS **before** the ACK is sent.
- **CONFIG_UPDATE** values outside the protocol ranges are ignored (not clamped).
- **Setup portal**: AP `BILLIARD-<last4 of device code>`, WPA2 password = 8 random digits generated on first boot, stored in NVS and printed once on the serial console (for the label). The page shows Wi-Fi/server status and the pairing code; it accepts only `https://` server URLs. On while unconfigured or waiting for pairing; BOOT 3 s → on for 10 min; BOOT 10 s → factory reset (physical access, spec §17).
- **TLS**: `WiFiClientSecure` with the pinned roots in `devices/esp32/certs` (ISRG Root X1/X2 = Let's Encrypt, USERTrust RSA/ECC = Sectigo, i.e. both cPanel AutoSSL providers) compiled into `include/CaBundle.h` (CI checks it is current). Plain `http://` is refused.
- **OTA**: server command → device downloads `/firmware/{version}` with its token, checks `Content-Length` = `size` and SHA-256 = `sha256` while streaming into the inactive slot, reboots. `verifyRollbackLater()` keeps the new image `PENDING_VERIFY` until a successful `/state`; no success within 10 min → rollback to the previous image. Each build embeds `BLYFWVER:<version>`; the server refuses an upload whose marker differs from the typed version, so a rollout always converges. Rollout: Super Admin → Proshivka → `POST /api/super/firmware/{id}/rollout` queues OTA (1 h TTL) for paired devices on another version with no occupying session on any of their tables; repeating skips devices that already have one pending.
- **Build-time values** (`scripts/defaults.py`): `FW_VERSION`, `DEVICE_REGISTRATION_SECRET` (GitHub secret, never committed), `DEFAULT_API_BASE`.

## 6c. Bill acceptor box (kind CASH, owner request 2026-10-05)
One per branch: TOP TB77 (UZS bills, PULSE mode: 1 000 = 1 … 200 000 = 8 pulses) + ESP32 (+ optocouplers on signal and inhibit, see HARDWARE.md §7). Registers with `"kind": "CASH"` and pairs like a lamp device (same token, OTA, offline monitoring); it never receives lamp commands and lamp endpoints answer 403 `DEVICE_KIND_MISMATCH`.
- `POST /device/v1/cash/poll` every `pollIntervalSec` (2 s): `{ts, fw, accepting, queued, rssi?, uptime?, bootReason?}` → `{serverTime, pollIntervalSec, accept, sessionId, required, paid, acceptUntil}`. **Open the acceptor (inhibit off) only while `accept` is true**; close it when `accept` is false, after 5 s without a server answer, or past `acceptUntil`.
- Every accepted bill: decode the nominal (pulses after 400 ms silence; unknown counts are logged, not sent), write `{noteUid, nominal, sessionId, deviceTs}` to flash **first** (`noteUid` = device code + persistent counter), then `POST /device/v1/cash/notes` (Idempotency-Key, up to 20, oldest first). Delete a note from flash only after the server answered it (`CREDITED` / `UNASSIGNED` / `DUPLICATE` all mean stored). After a reboot the queue is re-sent; duplicates are ignored by the server.
- The response carries the same state as `/cash/poll` (e.g. `accept: false` the moment the price is reached).
- Schemas: `device.cash-poll.*`, `device.cash-notes.*` in `packages/protocol`.

## 7. Error codes
401 `DEVICE_UNAUTHORIZED` / `REPAIR_REQUIRED`, 403 `DEVICE_REVOKED`, 429 rate limited (device backs off), 5xx → retry with backoff, keep local timer.

## 8. OTA
Only Super Admin publishes `firmware_releases` (sha256 computed server-side at upload). Device downloads only through authenticated endpoint over pinned TLS, verifies sha256, uses dual OTA partitions with rollback (mark valid only after successful `/state` call post-reboot). Never during an active session unless forced.

## 9. Future MQTT adapter (not v1)
Topic `t/{deviceCode}/cmd` (downlink only), same command JSON. Uplink stays HTTPS. Selected per deployment by `DEVICE_TRANSPORT=http|mqtt`.
