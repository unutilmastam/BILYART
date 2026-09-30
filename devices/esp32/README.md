# devices/esp32

Per-table light controller firmware (PlatformIO, Arduino-ESP32). Protocol: [DEVICE_PROTOCOL](../../docs/DEVICE_PROTOCOL.md) (§6b = firmware notes). Wiring: [HARDWARE](../../docs/HARDWARE.md). Flashing and pairing (Uzbek, step by step): [ESP32_FLASHING](../../docs/ESP32_FLASHING.md).

```
lib/core/   hardware-free logic (local timer, commands, boot recovery) — unit-tested on the host
src/        Arduino glue: setup portal, pairing, pinned-TLS HTTPS, poll/ack, NVS, relay, watchdog, OTA + rollback
certs/      pinned root CAs → include/CaBundle.h (python scripts/make_ca_bundle.py)
test/       host tests (read packages/protocol/examples)
```

```
pio test -e native        # host unit tests
pio run -e esp32dev       # firmware (CI builds the .bin + factory image)
scripts/native-test.sh    # same host tests with plain g++ (no PlatformIO registry needed)
```
Release binaries come only from GitHub Actions (`esp32-firmware` artifact). `DEVICE_REGISTRATION_SECRET` is a GitHub secret, never committed.
