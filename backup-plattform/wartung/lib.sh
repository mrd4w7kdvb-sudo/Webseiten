#!/usr/bin/env bash
# Gemeinsame Funktionen der Wartungsskripte. Wird per "source" eingebunden.
set -euo pipefail

BASIS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="${BACKUP_CONFIG:-$BASIS/config.env}"
[[ -f "$CONFIG" ]] || { echo "Fehlt: $CONFIG (Vorlage: config.env.example)" >&2; exit 1; }
# shellcheck source=/dev/null
source "$CONFIG"

GEHEIM_DIR="${GEHEIM_DIR:-$BASIS/geheim}"
KUNDEN_DIR="${KUNDEN_DIR:-$BASIS/kunden}"
PAKET_DIR="${PAKET_DIR:-$BASIS/pakete}"
PROTOKOLL_DIR="${PROTOKOLL_DIR:-$BASIS/protokoll}"
DATEN_BASIS="${DATEN_BASIS:-C:\\Daten}"
umask 077
mkdir -p "$GEHEIM_DIR" "$KUNDEN_DIR" "$PROTOKOLL_DIR"

KLASSEN_ERLAUBT="pflege finanzen personal betrieb unsortiert"

fehler() { echo "FEHLER: $*" >&2; exit 1; }
info()   { echo "==> $*"; }

benoetigt() {
  local p; for p in "$@"; do command -v "$p" >/dev/null 2>&1 || fehler "Programm '$p' fehlt"; done
}

pruefe_kuerzel() { [[ "$1" =~ ^[a-z0-9]{2,12}$ ]] || fehler "Kundenkürzel '$1' ungültig (a-z, 0-9, 2–12 Zeichen)"; }
pruefe_klasse()  { [[ " $KLASSEN_ERLAUBT " == *" $1 "* ]] || fehler "Unbekannte Klasse '$1' (erlaubt: $KLASSEN_ERLAUBT)"; }

# Zufallspasswort nur aus Buchstaben/Ziffern (URL- und YAML-sicher)
zufall() { openssl rand -base64 96 | tr -dc 'A-Za-z0-9' | head -c "${1:-40}"; }

# Aufbewahrung je Datenklasse (restic forget)
aufbewahrung() {
  case "$1" in
    betrieb) echo "--keep-daily 30 --keep-weekly 8 --keep-monthly 12" ;;
    *)       echo "--keep-daily 30 --keep-weekly 8 --keep-monthly 12 --keep-yearly 10" ;;
  esac
}

# Ordnername beim Klienten
ordnername() {
  case "$1" in
    pflege) echo "Pflege" ;; finanzen) echo "Finanzen" ;; personal) echo "Personal" ;;
    betrieb) echo "Betrieb" ;; *) echo "" ;;
  esac
}

# Startzeit der Sicherung je Klasse (gestaffelt)
uhrzeit() {
  case "$1" in
    pflege) echo "21:00" ;; finanzen) echo "21:30" ;; personal) echo "22:00" ;;
    betrieb) echo "22:30" ;; unsortiert) echo "23:00" ;;
  esac
}

# restic-Umgebung für den Wartungszugang
setze_admin_env() {
  [[ -f "$GEHEIM_DIR/admin-rest.pass" ]] || fehler "Fehlt: $GEHEIM_DIR/admin-rest.pass"
  export RESTIC_REST_USERNAME="$ADMIN_USER"
  RESTIC_REST_PASSWORD="$(cat "$GEHEIM_DIR/admin-rest.pass")"
  export RESTIC_REST_PASSWORD
}

admin_repo() { echo "rest:${ADMIN_URL%/}/$1/"; }        # $1 = z. B. k01-pflege
admin_key()  { echo "$GEHEIM_DIR/$1/$2.admin.key"; }     # $1 = Kunde, $2 = Klasse

# Kundendatei laden: setzt KUNDE_NAME, KLASSEN, DATEN_BASIS_KUNDE, HC_<klasse>, HC_fehlablage
lade_kunde() {
  local datei="$KUNDEN_DIR/$1.conf"
  [[ -f "$datei" ]] || fehler "Kunde '$1' unbekannt ($datei fehlt)"
  # werden von den aufrufenden Skripten genutzt
  # shellcheck disable=SC2034
  KUNDE_NAME=""
  # shellcheck disable=SC2034
  KLASSEN=""
  # shellcheck disable=SC2034
  DATEN_BASIS_KUNDE="$DATEN_BASIS"
  local k; for k in $KLASSEN_ERLAUBT fehlablage; do unset "HC_$k"; done
  # shellcheck source=/dev/null
  source "$datei"
}

# Healthchecks-Prüfung anlegen (idempotent über den Namen) und Ping-URL ausgeben.
# $1 Name, $2 Zeitraum (s), $3 Toleranz (s), $4 Tags
hc_pruefung() {
  [[ -n "${HC_API_KEY:-}" ]] || { echo ""; return 0; }
  curl -fsS -m 30 -X POST "${HC_URL%/}/api/v3/checks/" \
    -H "X-Api-Key: $HC_API_KEY" -H "Content-Type: application/json" \
    -d "$(jq -nc --arg n "$1" --arg t "$4" --argjson to "$2" --argjson gr "$3" \
          '{name:$n, tags:$t, timeout:$to, grace:$gr, unique:["name"]}')" \
  | jq -r '.ping_url // empty'
}

# Ping senden; stdin = Protokolltext. $1 = URL, $2 = Suffix ("" | /start | /fail)
hc_ping() {
  [[ -n "${1:-}" ]] || { cat >/dev/null; return 0; }
  curl -fsS -m 30 --retry 3 --data-binary @- "$1${2:-}" >/dev/null || true
}
