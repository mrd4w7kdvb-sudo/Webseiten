#!/usr/bin/env bash
# Monatsbericht für einen Kunden als Markdown: letzte Sicherung je Rechner und Klasse,
# Anzahl Stände, Speicherbedarf, letzter Wiederherstellungstest.
#
#   ./bericht.sh <kürzel>   → protokoll/bericht-<kürzel>-JJJJ-MM.md
source "$(dirname "$0")/lib.sh"
benoetigt restic jq
setze_admin_env

[[ $# -eq 1 ]] || fehler "Aufruf: $0 <kürzel>"
KUNDE="$1"; lade_kunde "$KUNDE"
AUSGABE="$PROTOKOLL_DIR/bericht-$KUNDE-$(date +%Y-%m).md"
CSV="$PROTOKOLL_DIR/restore-tests.csv"

{
  echo "# Backup-Bericht $KUNDE_NAME"
  echo
  echo "Stand: $(date '+%d.%m.%Y')"
  echo
  echo "| Datenklasse | Rechner | Letzte Sicherung | Stände | Speicher (verschlüsselt) | Letzter Restore-Test |"
  echo "| --- | --- | --- | --- | --- | --- |"
  for kl in $KLASSEN; do
    r=(restic -r "$(admin_repo "$KUNDE-$kl")" --password-file "$(admin_key "$KUNDE" "$kl")")
    snaps="$("${r[@]}" snapshots --json)"
    anzahl="$(jq 'length' <<< "$snaps")"
    groesse="$("${r[@]}" stats --mode raw-data --json | jq -r '.total_size | if . >= 1073741824 then (. / 1073741824 * 10 | floor / 10 | tostring) + " GB" else (. / 1048576 * 10 | floor / 10 | tostring) + " MB" end')"
    test="-"
    [[ -f "$CSV" ]] && test="$(awk -F';' -v k="$KUNDE" -v c="$kl" '$2==k && $3==c {t=$1" "$9} END {print t ? t : "-"}' "$CSV")"
    zeilen="$(jq -r 'group_by(.hostname)[] | (max_by(.time)) | "\(.hostname)|\(.time[0:10]) \(.time[11:16])"' <<< "$snaps")"
    if [[ -z "$zeilen" ]]; then
      echo "| $kl | – | keine Sicherung | 0 | $groesse | $test |"
    else
      while IFS='|' read -r host zeit; do
        echo "| $kl | $host | $zeit | $anzahl | $groesse | $test |"
      done <<< "$zeilen"
    fi
  done
  echo
  echo "Alle Daten werden auf den Rechnern verschlüsselt, bevor sie übertragen werden. Gespeichert wird"
  echo "ausschließlich in Rechenzentren in Deutschland."
} > "$AUSGABE"

info "Bericht: $AUSGABE"
