#!/bin/sh
# Geraetewaechter-Agent - Installation fuer Linux (inkl. Raspberry Pi) und macOS
# Aufruf (als root):
#   curl -fsSL __GW_SERVER__/install.sh | sudo sh -s -- <REGISTRIERUNGSSCHLUESSEL>
set -eu
SERVER="__GW_SERVER__"
TOKEN="${1:-}"
[ -n "$TOKEN" ] || { echo "Fehler: Registrierungsschluessel fehlt." >&2; exit 1; }
[ "$(id -u)" = 0 ] || { echo "Fehler: Bitte mit sudo bzw. als root ausfuehren." >&2; exit 1; }

OS=$(uname -s | tr '[:upper:]' '[:lower:]')
case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  armv7l|armv6l|armhf) ARCH=arm ;;
  *) echo "Fehler: Prozessorarchitektur $(uname -m) wird nicht unterstuetzt." >&2; exit 1 ;;
esac
case "$OS" in linux|darwin) ;; *) echo "Fehler: Betriebssystem $OS wird nicht unterstuetzt." >&2; exit 1 ;; esac

BIN="gw-agent-${OS}_${ARCH}"
mkdir -p /usr/local/bin
# Download nach /usr/local/bin (nicht /tmp, das oft "noexec" eingehaengt ist)
TMP=/usr/local/bin/.gw-agent-download
trap 'rm -f "$TMP" "$TMP.sums"' EXIT

# Nur HTTPS erlaubt (Ausnahme: lokaler Testserver)
PROTO='=https'
case "$SERVER" in http://localhost:*|http://127.0.0.1:*) PROTO='=http,https' ;; esac

echo "Lade $BIN ..."
curl -fsSL --proto "$PROTO" --tlsv1.2 "$SERVER/downloads/$BIN" -o "$TMP"
curl -fsSL --proto "$PROTO" --tlsv1.2 "$SERVER/downloads/SHA256SUMS" -o "$TMP.sums"
EXPECTED=$(awk -v f="$BIN" '$2==f {print $1}' "$TMP.sums")
if command -v sha256sum >/dev/null 2>&1; then
  ACTUAL=$(sha256sum "$TMP" | awk '{print $1}')
else
  ACTUAL=$(shasum -a 256 "$TMP" | awk '{print $1}')
fi
[ -n "$EXPECTED" ] && [ "$EXPECTED" = "$ACTUAL" ] || { echo "Fehler: Pruefsumme stimmt nicht - Abbruch." >&2; exit 1; }
chmod 0755 "$TMP"

# setup kopiert das Programm nach /usr/local/bin/gw-agent, registriert das
# Geraet und richtet den Systemdienst ein.
"$TMP" setup --server "$SERVER" --token "$TOKEN"
