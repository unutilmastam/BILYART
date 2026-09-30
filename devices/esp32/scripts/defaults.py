# Injects build-time values as C string macros. CI provides FW_VERSION (release version),
# DEVICE_REGISTRATION_SECRET (GitHub secret, never committed) and DEFAULT_API_BASE; local builds get defaults.
import os

Import("env")  # noqa: F821  (provided by PlatformIO/SCons)

values = {
    "FW_VERSION": os.environ.get("FW_VERSION") or "0.0.0-dev",
    "DEVICE_REGISTRATION_SECRET": os.environ.get("DEVICE_REGISTRATION_SECRET") or "",
    "DEFAULT_API_BASE": os.environ.get("DEFAULT_API_BASE") or "https://itcode.uz",
}
env.Append(CPPDEFINES=[(k, env.StringifyMacro(v)) for k, v in values.items()])  # noqa: F821
