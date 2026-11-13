#!/usr/bin/env bash
# Wöchentliche Wartung aller Repositorys: alte Stände aufräumen (Aufbewahrung je Klasse),
# Speicher freigeben, Stichprobe der Daten auf Lesbarkeit prüfen. Meldet an Healthchecks.
#
#   ./wartung.sh               alle Kunden
#   ./wartung.sh k01 h01       nur diese Kunden
#
# Cron auf dem Wartungsrechner, z. B. sonntags 10 Uhr:
#   0 10 * * 0  /pfad/zu/wartung/wartung.sh >> /pfad/zu/wartung/protokoll/wartung.log 2>&1
source "$(dirname "$0")/lib.sh"
benoetigt restic curl jq
setze_admin_env

STICHPROBE="${STICHPROBE:-5%}"

if [[ -z "${HC_WARTUNG:-}" && -n "${HC_API_KEY:-}" ]]; then
  HC_WARTUNG="$(hc_pruefung "wartung" 604800 86400 "wartung")"
fi
echo "" | hc_ping "${HC_WARTUNG:-}" /start

if [[ $# -gt 0 ]]; then kunden=("$@"); else
  kunden=(); for f in "$KUNDEN_DIR"/*.conf; do [[ -e "$f" ]] && kunden+=("$(basename "$f" .conf)"); done
fi

bericht=""; fehlerzahl=0
notiere() { bericht+="$*"$'\n'; echo "$*"; }

for kunde in "${kunden[@]}"; do
  lade_kunde "$kunde"
  for kl in $KLASSEN; do
    repo="$kunde-$kl"; key="$(admin_key "$kunde" "$kl")"
    r=(restic -r "$(admin_repo "$repo")" --password-file "$key" --retry-lock 30m)
    notiere "== $repo"
    # shellcheck disable=SC2046
    if "${r[@]}" unlock >/dev/null 2>&1 \
       && "${r[@]}" forget $(aufbewahrung "$kl") --prune --quiet \
       && "${r[@]}" check --read-data-subset="$STICHPROBE" --quiet; then
      letzte="$("${r[@]}" snapshots --json --latest 1 | jq -r 'map(.time[0:16] + " " + .hostname) | join(", ")')"
      notiere "   OK – letzte Sicherung(en): ${letzte:-keine}"
    else
      notiere "   FEHLER bei Aufräumen oder Prüfung"
      fehlerzahl=$((fehlerzahl + 1))
    fi
  done
done

notiere "Wartung beendet $(date -Is), Fehler: $fehlerzahl"
if (( fehlerzahl > 0 )); then
  printf '%s' "$bericht" | hc_ping "${HC_WARTUNG:-}" /fail; exit 1
fi
printf '%s' "$bericht" | hc_ping "${HC_WARTUNG:-}" ""
