#!/usr/bin/env bash
# Wiederherstellungstest: stellt eine zufällige Datei aus dem neuesten Stand wieder her,
# prüft sie und protokolliert das Ergebnis in protokoll/restore-tests.csv.
#
#   ./restore-test.sh <kürzel> [<klasse> ...]     ohne Klasse: alle Klassen des Kunden
source "$(dirname "$0")/lib.sh"
benoetigt restic jq shuf
setze_admin_env

[[ $# -ge 1 ]] || fehler "Aufruf: $0 <kürzel> [<klasse> ...]"
KUNDE="$1"; shift; lade_kunde "$KUNDE"
klassen=("$@"); [[ ${#klassen[@]} -gt 0 ]] || read -r -a klassen <<< "$KLASSEN"

CSV="$PROTOKOLL_DIR/restore-tests.csv"
[[ -f "$CSV" ]] || echo "datum;kunde;klasse;stand;rechner;datei;bytes;sekunden;ergebnis" > "$CSV"
gesamt=0

for kl in "${klassen[@]}"; do
  pruefe_klasse "$kl"
  repo="$KUNDE-$kl"
  r=(restic -r "$(admin_repo "$repo")" --password-file "$(admin_key "$KUNDE" "$kl")")
  start=$(date +%s); ergebnis="FEHLER"; datei="-"; bytes=0; stand="-"; rechner="-"

  snap="$("${r[@]}" snapshots --json --latest 1 | jq -c 'sort_by(.time) | last // empty')"
  if [[ -n "$snap" ]]; then
    stand="$(jq -r '.short_id' <<< "$snap")"; rechner="$(jq -r '.hostname' <<< "$snap")"
    datei="$("${r[@]}" ls "$stand" --json | jq -r 'select(.type == "file" and .size > 0) | .path' | shuf -n 1 || true)"
    if [[ -n "$datei" ]]; then
      ziel="$(mktemp -d)"
      if "${r[@]}" restore "$stand" --target "$ziel" --include "$datei" --quiet; then
        gefunden="$(find "$ziel" -type f | head -n 1)"
        if [[ -n "$gefunden" && -s "$gefunden" ]]; then
          bytes=$(stat -c %s "$gefunden"); ergebnis="OK"
        fi
      fi
      rm -rf "$ziel"
    else
      datei="(keine Datei im Stand)"
    fi
  else
    stand="(kein Stand vorhanden)"
  fi

  dauer=$(( $(date +%s) - start ))
  echo "$(date -I);$KUNDE;$kl;$stand;$rechner;${datei//;/,};$bytes;$dauer;$ergebnis" >> "$CSV"
  echo "$repo: $ergebnis  ($datei, $bytes Bytes, $dauer s)"
  [[ "$ergebnis" == OK ]] || gesamt=1
done
exit $gesamt
