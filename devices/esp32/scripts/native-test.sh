#!/usr/bin/env bash
# Runs the host unit tests with plain g++ (same sources as `pio test -e native`), for machines that
# cannot reach the PlatformIO registry. Fetches ArduinoJson + Unity from GitHub once.
set -euo pipefail
cd "$(dirname "$0")/.."
deps=.native-deps
mkdir -p "$deps"
[ -d "$deps/ArduinoJson" ] || git clone -q --depth 1 --branch v7.4.2 https://github.com/bblanchon/ArduinoJson.git "$deps/ArduinoJson"
[ -d "$deps/Unity" ] || git clone -q --depth 1 --branch v2.6.1 https://github.com/ThrowTheSwitch/Unity.git "$deps/Unity"
g++ -std=gnu++17 -Wall -Wextra -Werror -DUNIT_TEST -I lib/core/src -I "$deps/ArduinoJson/src" -I "$deps/Unity/src" \
  test/test_core/test_main.cpp lib/core/src/*.cpp "$deps/Unity/src/unity.c" -o "$deps/core-tests"
"$deps/core-tests"
