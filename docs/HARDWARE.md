# HARDWARE — branch light controller

**One controller per branch** (owner decision 2026-10-01): one ESP32 switches the lamps of several billiard tables, **one relay channel per table**, each relay driving that table's contactor. A board has 1–8 channels (default build: 4). A branch with more than 8 tables gets a second controller. The firmware turns each lamp OFF at its own session end without any network and never keeps a lamp ON longer than the hard cap (default 12 h). The lamps run on 220–230 V AC (owner, 2026-10-01).

> ⚠️ **Mains voltage (220–230 V AC).** Everything on the mains side must be installed and checked by a **licensed electrician**: RCD (30 mA), breakers sized for the lamp circuits, PE (earth) connected, IP-rated enclosure, strain relief. The ESP32 side is low voltage (5 V / 3.3 V). This document is a design reference, not a substitute for the electrician's sign-off.

> ⚠️ **One controller = all tables of the branch.** If the controller or its power supply fails, no lamp of that branch can be switched by the system (running games still end — the lamps go OFF). Fit the optional **keyed bypass switch per table** (part 9) so staff can light a table by hand until it is repaired, and keep a spare pre-flashed ESP32 in the branch.

## 1. Parts (one branch, 4 tables)
| # | Part | Qty | Notes |
|---|---|---|---|
| 1 | ESP32 DevKit v1 (ESP32-WROOM-32, 4 MB flash) | 1 | USB for the first flash only |
| 2 | 5 V power supply, ≥ 2 A (DIN-rail 5 V PSU, or HLK-PM05 class in a closed enclosure) | 1 | powers ESP32 + all relay coils |
| 3 | 4-channel 5 V relay module **with optocouplers and a High/Low trigger jumper** | 1 | jumper on **H (high-level trigger)**; switches only the contactor coils. 8 tables → 8-channel module of the same kind |
| 4 | Modular contactor, 230 V AC coil, 1 NO pole, rated ≥ 16 A (AC-1) | 1 per table | switches the lamp; handles LED-driver inrush |
| 5 | 10 kΩ resistor | 1 per channel | pull-down on each relay GPIO (keeps every relay OFF during boot) |
| 6 | Breaker (MCB) per lamp circuit, sized by the electrician | 1 per table (or per group) | |
| 7 | Fuse (e.g. 1 A) for the contactor-coil feed | 1 | protects the thin coil wiring |
| 8 | DIN enclosure, terminal blocks, ferrules, cable to each table | | |
| 9 | (recommended) Keyed bypass switch in parallel with each contactor | 1 per table | staff-only fallback if the controller fails; log its use manually |

Why contactors and not the small relays directly: LED lamp drivers draw an inrush current many times their nominal current; small PCB relays weld under it over time. Contactors also keep the lamp current out of the ESP32 box.

**Still open (owner):** the lamp **power in watts** per table (220 V is the voltage). The electrician sizes the contactors/breakers from it: `I = P / 230 V`. Example: 3 × 40 W LED = 120 W → 0.5 A nominal per table; a 16 A AC-1 contactor has ample margin for inrush and years of switching.

**Relay module trigger level:** many cheap modules are *low-level* triggered (input LOW = relay ON). With those, a booting or unpowered ESP32 could energise the relays. Use a module with an **H/L jumper set to H** (or a high-level-trigger module). Then the pull-downs keep every lamp OFF until the firmware drives a pin HIGH. Check on the bench (§5.1) before connecting lamps.

## 2. Pins (firmware `src/Board.h`)
| Channel | ESP32 pin | Connected to |
|---|---|---|
| 1 | GPIO26 | relay module IN1 (+ 10 kΩ to GND) |
| 2 | GPIO27 | IN2 (+ 10 kΩ to GND) |
| 3 | GPIO25 | IN3 (+ 10 kΩ to GND) |
| 4 | GPIO33 | IN4 (+ 10 kΩ to GND) |
| 5–8 | GPIO32, GPIO23, GPIO22, GPIO21 | IN5–IN8 on an 8-channel build |
| – | GPIO2 | on-board LED: status (see §4) |
| – | GPIO0 | BOOT button: 3 s = setup page, 10 s = factory reset |
| – | 5V / VIN, GND | PSU +5 V / 0 V (common ground with the relay module) |

None of the relay pins is a boot-strapping pin or toggles during boot. The number of channels is fixed per firmware build (`RELAY_CHANNELS`, default 4) and reported to the server at registration; the admin panel only offers channels the board has.

Each relay must be wired so that **unpowered = contact open = lamp OFF** (use the NO contact). Power cut, ESP32 crash, watchdog reset → every lamp goes OFF.

## 3. Wiring diagram (4 tables)
```
 230 V L ──[MCB]──┬────────────────────────────────────────────────┐
                  │                                                │
               [5 V PSU]                                   [MCB per table]
                  │ +5V / 0V                                       │
        ┌─────────┴──────────┐                     contactor K1..K4 main pole (NO)
        │   ESP32 DevKit     │                                     │
        │  5V  ◄── +5V       │     4-ch relay module (jumper H)    └──► LAMP 1..4 ──► N
        │  GND ◄── 0V ───────┼──► GND
        │  GPIO26 ───────────┼──► IN1     COM1..4 ◄── 230 V L (via 1 A fuse)
        │  GPIO27 ───────────┼──► IN2     NO1 ──► K1 coil A1     K1..K4 coil A2 ──► N
        │  GPIO25 ───────────┼──► IN3     NO2 ──► K2 coil A1
        │  GPIO33 ───────────┼──► IN4     NO3 ──► K3 coil A1
        │  each IN: 10 kΩ→GND│     VCC ◄─ +5V  NO4 ──► K4 coil A1
        └────────────────────┘
 Bypass (optional): key switch S1..S4 in parallel with the main pole of K1..K4.
 PE (earth) ──► lamp bodies, enclosure (if metal)
```
Channel N must drive the contactor of the table that the admin wires to channel N (Admin → Stollar → "Chiroq"). Label every contactor with its channel and table number. Keep low-voltage and 230 V wiring physically separated in the box. Mount the box where the Wi-Fi signal is at least −75 dBm (shown on the device setup page and in Admin → Qurilmalar).

## 4. Status LED
| LED | Meaning |
|---|---|
| fast blink | setup or waiting for pairing (setup Wi-Fi is on) |
| slow blink | paired but the server is unreachable — timers keep working locally |
| steady | paired and online |

## 5. Behaviour you can test on the bench (with test bulbs)
1. Power up the controller with nothing paired: **no relay clicks, every bulb OFF** (checks the H jumper and pull-downs).
2. Wire table 1 to channel 1 and table 2 to channel 2. Start a 10-minute session on table 1 from the tablet → only bulb 1 ON within a few seconds.
3. Start table 2 → bulb 2 ON; bulb 1 unaffected.
4. At 5 minutes left bulb 1 blinks 3 times (and the tablet speaks), then stays ON.
5. At the end → bulb 1 OFF, even if the router was unplugged in the middle; bulb 2 keeps running.
6. Pull the power during the sessions and plug it back: each channel resumes until its original end time (sessions are kept in flash).
7. Stop table 2 early from the admin panel → bulb 2 OFF within ~3 s.

## 6. Label for each controller
Write on the box: **device code** (`ESP32-XXXXXX`), **setup Wi-Fi name** (`BILLIARD-XXXX`) and its **password** (shown once on the computer during the first flash, see ESP32_FLASHING.md), branch name, and a channel → table list (e.g. `1 → 1-stol, 2 → 2-stol, …`).

## 7. Bill acceptor box (optional, one per branch)
TOP TB77 (UZS firmware, **PULSE** mode, cable CU-961-1) + ESP32 DevKit + 12 V 3 A supply (TB77) + LM2596 12→5 V (ESP32) + lockable steel box, next to the tablet. Wi-Fi like the lamp controller (Ethernet not required).
- **Never wire TB77 signals straight to the ESP32**: the pulse output and the inhibit input are 12 V-side. Use an optocoupler (PC817) for the pulse signal (TB77 → ESP32 input with pull-up to 3.3 V) and a second optocoupler/transistor for inhibit (ESP32 → TB77). Prefer non-strapping GPIOs (e.g. 32/33) for these lines.
- Pulse settings: 1 000 = 1, 2 000 = 2, 5 000 = 3, 10 000 = 4, 20 000 = 5, 50 000 = 6, 100 000 = 7, 200 000 = 8 pulses.
- Inhibit must be **closed** at power-up and whenever the server is unreachable (DEVICE_PROTOCOL.md §6c). Bench test every bill value with the box's serial log before the hall test.
- The cash box firmware is a separate build (next step); the lamp controller firmware is unchanged.
