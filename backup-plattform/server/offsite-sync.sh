#!/usr/bin/env bash
# Nächtliche Offsite-Kopie aller Repositorys nach IONOS S3 (Bucket mit Versionierung + Object Lock).
# Die Daten sind bereits clientseitig verschlüsselt; hier wird kein Schlüssel benötigt.
set -uo pipefail

ENV_DATEI="${BACKUP_ENV:-$(dirname "$0")/.env}"
# shellcheck source=/dev/null
[[ -f "$ENV_DATEI" ]] && source "$ENV_DATEI"
: "${RCLONE_ZIEL:?RCLONE_ZIEL in .env setzen}"

ping() {  # $1 = Suffix ("", "/start", "/fail"), stdin = Protokoll
  [[ -n "${HC_OFFSITE:-}" ]] || { cat >/dev/null; return 0; }
  curl -fsS -m 30 --retry 3 --data-binary @- "${HC_OFFSITE}$1" >/dev/null || true
}

echo "" | ping /start
start=$(date +%s)
tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT
rclone sync /srv/backup/data "$RCLONE_ZIEL" \
  --size-only --fast-list \
  --exclude ".htpasswd" --exclude "/*/locks/**" \
  --stats-one-line --stats 0 -v >"$tmp" 2>&1
rc=$?
log="$(tail -n 50 "$tmp")"
echo "$(date -Is) rclone beendet mit $rc nach $(( $(date +%s) - start )) s"
echo "$log"

if [[ $rc -eq 0 ]]; then
  echo "$log" | ping ""
else
  echo "$log" | ping /fail
fi
exit $rc
