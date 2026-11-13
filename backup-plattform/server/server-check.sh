#!/usr/bin/env bash
# Tägliche Zustandsprüfung: Speicherplatz, RAID/ZFS, TLS-Zertifikat. Meldet an Healthchecks.
set -uo pipefail

ENV_DATEI="${BACKUP_ENV:-$(dirname "$0")/.env}"
# shellcheck source=/dev/null
[[ -f "$ENV_DATEI" ]] && source "$ENV_DATEI"

probleme=()

belegt=$(df --output=pcent /srv/backup/data | tail -n 1 | tr -dc '0-9')
(( belegt > 80 )) && probleme+=("Datenbereich zu ${belegt} % belegt")

if [[ -r /proc/mdstat ]] && grep -Eq '\[[U_]*_[U_]*\]' /proc/mdstat; then
  probleme+=("Software-RAID degradiert (siehe /proc/mdstat)")
fi
if command -v zpool >/dev/null 2>&1 && [[ "$(zpool status -x)" != "all pools are healthy" ]]; then
  probleme+=("ZFS-Pool meldet Fehler (zpool status)")
fi

if [[ -n "${DOMAIN:-}" ]]; then
  if ! echo | openssl s_client -connect 127.0.0.1:443 -servername "$DOMAIN" 2>/dev/null \
       | openssl x509 -noout -checkend $((14 * 86400)) >/dev/null 2>&1; then
    probleme+=("TLS-Zertifikat fehlt oder läuft in weniger als 14 Tagen ab")
  fi
fi

for dienst in rest-server rest-admin caddy; do
  if ! docker ps --format '{{.Names}}' | grep -q -- "$dienst"; then
    probleme+=("Container $dienst läuft nicht")
  fi
done

bericht="$(hostname) $(date -Is)
Datenbereich: ${belegt} % belegt
$(printf '%s\n' "${probleme[@]:-keine Probleme}")"
echo "$bericht"

if [[ -n "${HC_SERVER:-}" ]]; then
  suffix=""; (( ${#probleme[@]} > 0 )) && suffix="/fail"
  curl -fsS -m 30 --retry 3 --data-binary "$bericht" "${HC_SERVER}${suffix}" >/dev/null || true
fi
(( ${#probleme[@]} == 0 ))
