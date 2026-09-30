# HARDWARE — table light controller

One controller per billiard table: an ESP32 switches the table lamp through a relay and a contactor. The firmware turns the light OFF at the session end on its own (no network needed) and never keeps it ON longer than the hard cap (default 12 h).

> ⚠️ **Mains voltage (230 V AC).** Everything on the 230 V side must be installed and checked by a **licensed electrician**: RCD (30 mA), a breaker sized for the lamp circuit, PE (earth) connected, IP-rated enclosure, strain relief. The ESP32 side is low voltage (5 V / 3.3 V). This document is a design reference, not a substitute for the electrician's sign-off.

## 1. Parts per table
| # | Part | Notes |
|---|---|---|
| 1 | ESP32 DevKit v1 (ESP32-WROOM-32, 4 MB flash) | USB for the first flash only |
| 2 | 5 V power supply, ≥ 1 A (e.g. HLK-PM05 in a closed enclosure, or a DIN-rail 5 V PSU) | powers ESP32 + relay module |
| 3 | 1-channel 5 V relay module **with optocoupler**, input works at 3.3 V logic | switches only the contactor coil |
| 4 | Modular contactor, 230 V AC coil, 1 NO pole, rated ≥ 16 A (AC-1) | switches the lamp; handles LED-driver inrush |
| 5 | 10 kΩ resistor | pull-down on GPIO26 (keeps the relay OFF during boot) |
| 6 | Breaker (MCB) for the lamp circuit, sized by the electrician | |
| 7 | DIN enclosure / box, terminal blocks, ferrules | |
| 8 | (optional) Keyed bypass switch in parallel with the contactor | lets staff light a table if a controller fails — staff-only, logged manually |

Why a contactor and not the small relay directly: LED lamp drivers draw an inrush current many times their nominal current; small PCB relays weld under it over time. A contactor also separates the ESP32 box from the lamp current. **Open question for the owner:** lamp power per table (see PROGRESS.md) — the electrician confirms the contactor/breaker from it.

Rough sizing: `I = P / 230 V`. Example: 3 × 40 W LED = 120 W → 0.5 A nominal; a 16 A AC-1 contactor has ample margin for inrush and years of switching.

## 2. Pins (firmware `src/Board.h`)
| ESP32 pin | Connected to | Why |
|---|---|---|
| GPIO26 | relay module IN (+ 10 kΩ to GND) | not a boot-strapping pin; pull-down → OFF while booting |
| GPIO2 | on-board LED | status (see §4) |
| GPIO0 | BOOT button | 3 s = setup page, 10 s = factory reset |
| 5V / VIN | PSU +5 V | |
| GND | PSU 0 V, relay GND | common ground |

The relay module must be wired so that **unpowered = contact open = light OFF** (use the NO contact). Power cut, ESP32 crash, watchdog reset → the light goes OFF.

## 3. Wiring diagram
```
 230 V L ──[MCB]──┬──────────────────────────────┐
                  │                              │
               [5 V PSU]                  contactor main pole (NO)
                  │ +5V / 0V                     │
        ┌─────────┴─────────┐                    └──► LAMP ──► N
        │   ESP32 DevKit    │
        │  5V ◄── +5V       │        relay module
        │  GND ◄── 0V ──────┼──► GND
        │  GPIO26 ──────────┼──► IN          COM ◄── 230 V L (via fuse)
        │   └─ 10 kΩ ─ GND  │     VCC ◄─ +5V  NO  ──► contactor coil A1
        └───────────────────┘                        contactor coil A2 ──► N
 PE (earth) ──► lamp body, enclosure (if metal)
```
Keep low-voltage and 230 V wiring physically separated in the box. Mount the ESP32 where the Wi-Fi signal is at least −75 dBm (shown on the device setup page and in Admin → Qurilmalar).

## 4. Status LED
| LED | Meaning |
|---|---|
| fast blink | setup or waiting for pairing (setup Wi-Fi is on) |
| slow blink | paired but the server is unreachable — timers keep working locally |
| steady | paired and online |

## 5. Behaviour you can test on the bench (with a lamp or a test bulb)
1. Start a 10-minute session from the tablet → light ON within a few seconds.
2. At 5 minutes left the light blinks 3 times (and the tablet speaks), then stays ON.
3. At the end → OFF, even if the router was unplugged in the middle.
4. Pull the power during a session and plug it back: the controller resumes until the original end time (it keeps the session in flash).
5. Stop the session early from the admin panel → OFF within ~3 s.

## 6. Label for each controller
Write on the box: **device code** (`ESP32-XXXXXX`), **setup Wi-Fi name** (`BILLIARD-XXXX`) and its **password** (shown once on the computer during the first flash, see ESP32_FLASHING.md), table number.
