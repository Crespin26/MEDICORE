#!/usr/bin/env bash
# Connect the Android debug app to the local Laravel API through the USB cable.
set -euo pipefail

project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
flutter_binary="${MEDICORE_FLUTTER_BINARY:-flutter}"
if ! command -v "$flutter_binary" >/dev/null 2>&1; then
    echo 'Flutter est introuvable. Définir MEDICORE_FLUTTER_BINARY avec le chemin du SDK.' >&2
    exit 1
fi

adb_binary="${MEDICORE_ADB_BINARY:-adb}"
if ! command -v "$adb_binary" >/dev/null 2>&1; then
    sdk_path="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-${HOME}/Android/Sdk}}"
    adb_binary="${sdk_path}/platform-tools/adb"
fi
if [[ ! -x "$adb_binary" ]] && ! command -v "$adb_binary" >/dev/null 2>&1; then
    echo 'ADB est introuvable. Installer le SDK Android et définir ANDROID_HOME.' >&2
    exit 1
fi

# Refuse ambiguity and unauthorized devices; never choose a phone silently.
mapfile -t devices < <("$adb_binary" devices | awk 'NR > 1 && $2 == "device" && $1 !~ /^emulator-/ { print $1 }')
device_id="${1:-}"
if [[ -z "$device_id" ]]; then
    if [[ ${#devices[@]} -ne 1 ]]; then
        echo 'Brancher et autoriser un téléphone USB, ou préciser son identifiant en argument.' >&2
        "$adb_binary" devices
        exit 1
    fi
    device_id="${devices[0]}"
fi
if [[ "$("$adb_binary" -s "$device_id" get-state)" != 'device' ]]; then
    echo 'Le téléphone doit être connecté et autorisé pour le débogage USB.' >&2
    exit 1
fi

# Loopback remains private: no need to expose clinical data to the local Wi-Fi.
"$adb_binary" -s "$device_id" reverse tcp:8000 tcp:8000
cd "$project_dir/mobile"
exec "$flutter_binary" run -d "$device_id" \
    --dart-define=MEDICORE_API_URL=http://127.0.0.1:8000/api/v1 \
    --dart-define=MEDICORE_ALLOW_HTTP=true
