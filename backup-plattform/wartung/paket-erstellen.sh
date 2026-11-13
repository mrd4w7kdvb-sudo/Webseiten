#!/usr/bin/env bash
# Erzeugt das Installationspaket für die PCs eines Kunden: pakete/<kürzel>/ und pakete/<kürzel>.zip
#
#   ./paket-erstellen.sh <kürzel>
#
# Das Paket enthält Zugangsdaten und Agentenschlüssel. Nur auf verschlüsseltem USB-Stick
# transportieren und nach der Installation löschen.
source "$(dirname "$0")/lib.sh"

[[ $# -eq 1 ]] || fehler "Aufruf: $0 <kürzel>"
KUNDE="$1"; pruefe_kuerzel "$KUNDE"; lade_kunde "$KUNDE"

AGENT_DIR="$BASIS/../agent"
for f in restic.exe resticprofile.exe; do
  [[ -f "$AGENT_DIR/bin/$f" ]] || fehler "agent/bin/$f fehlt – siehe README, Abschnitt 'Agent vorbereiten'"
done

ZIEL="$PAKET_DIR/$KUNDE"
rm -rf "$ZIEL"; mkdir -p "$ZIEL/bin" "$ZIEL/keys"
cp "$AGENT_DIR/bin/restic.exe" "$AGENT_DIR/bin/resticprofile.exe" "$ZIEL/bin/"
cp "$AGENT_DIR/install.ps1" "$AGENT_DIR/fehlablage-bericht.ps1" "$AGENT_DIR/LIESMICH.txt" "$ZIEL/"

PROG='C:\Program Files\BackupAgent'
CONF='C:\ProgramData\BackupAgent'

yaml_q() { printf "'%s'" "${1//\'/\'\'}"; }   # YAML-String in einfachen Anführungszeichen

{
  echo "# Agent-Konfiguration $KUNDE ($KUNDE_NAME) – erzeugt am $(date -I). Nicht von Hand ändern,"
  echo "# sondern auf dem Wartungsrechner neu erzeugen."
  echo 'version: "1"'
  echo
  echo "global:"
  echo "  restic-binary: $(yaml_q "$PROG\\restic.exe")"
  echo
  for kl in $KLASSEN; do
    repo="$KUNDE-$kl"
    cp "$GEHEIM_DIR/$KUNDE/$kl.agent.key" "$ZIEL/keys/$kl.key"
    var="HC_$kl"; hc="${!var:-}"
    echo "$kl:"
    echo "  repository: $(yaml_q "rest:${PUBLIC_URL%/}/$repo/")"
    echo "  password-file: $(yaml_q "$CONF\\keys\\$kl.key")"
    echo "  cache-dir: $(yaml_q "$CONF\\cache")"
    echo "  env:"
    echo "    RESTIC_REST_USERNAME: $(yaml_q "$repo")"
    echo "    RESTIC_REST_PASSWORD: $(yaml_q "$(cat "$GEHEIM_DIR/$KUNDE/$kl.rest.pass")")"
    echo "  backup:"
    echo "    source:"
    if [[ "$kl" == unsortiert ]]; then
      echo "      - 'C:\\Users'"
      echo "    iexclude:"
      # Achtung: resticprofile ersetzt $NAME und ~ – ein $ wird daher als $$ geschrieben
      for m in AppData 'ntuser.dat*' 'UsrClass.dat*' '$$Recycle.Bin' '*.tmp'; do
        echo "      - $(yaml_q "$m")"
      done
    else
      echo "      - $(yaml_q "$DATEN_BASIS_KUNDE\\$(ordnername "$kl")")"
      echo "    run-before: $(yaml_q "$CONF\\vorher-$kl.cmd")"
    fi
    echo "    use-fs-snapshot: true"
    echo "    exclude-caches: true"
    echo "    schedule: $(yaml_q "*-*-* $(uhrzeit "$kl"):00")"
    echo "    schedule-permission: system"
    echo "    schedule-lock-wait: 2h"
    if [[ -n "$hc" ]]; then
      echo "    send-before:"
      echo "      - url: $(yaml_q "$hc/start")"
      echo "    send-after:"
      echo "      - url: $(yaml_q "$hc")"
      echo "    send-after-fail:"
      echo "      - url: $(yaml_q "$hc/fail")"
    fi
    echo
  done
} > "$ZIEL/profiles.yaml"

# Klassenliste, Ordner, Fehlablage-URL, Vorab-Skripte
printf '%s\n' $KLASSEN > "$ZIEL/klassen.txt"
: > "$ZIEL/ordner.txt"
for kl in $KLASSEN; do
  o="$(ordnername "$kl")"
  [[ -n "$o" ]] || continue
  printf '%s\r\n' "$DATEN_BASIS_KUNDE\\$o" >> "$ZIEL/ordner.txt"
  printf '@echo off\r\nrem Wird vor jeder Sicherung der Klasse "%s" ausgefuehrt.\r\nrem Hier z. B. den Datenbank-Export der Branchensoftware nach %s\\%s starten.\r\nrem Ein Exit-Code ungleich 0 bricht die Sicherung ab und loest einen Alarm aus.\r\nexit /b 0\r\n' \
    "$kl" "$DATEN_BASIS_KUNDE" "$o" > "$ZIEL/vorher-$kl.cmd"
done
printf '%s\r\n' "${HC_fehlablage:-}" > "$ZIEL/fehlablage.url"

chmod -R go-rwx "$ZIEL"
( cd "$PAKET_DIR" && rm -f "$KUNDE.zip" && zip -qr "$KUNDE.zip" "$KUNDE" )

info "Paket erstellt: $PAKET_DIR/$KUNDE.zip"
echo "  Klassen: $KLASSEN"
echo "  Enthält Zugangsdaten – nur verschlüsselt transportieren und nach der Installation löschen."
