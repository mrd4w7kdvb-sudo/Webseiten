#!/usr/bin/env bash
# Legt einen Kunden mit seinen Datenklassen an (oder ergänzt Klassen). Kann mehrfach laufen.
#
#   ./kunde-anlegen.sh <kürzel> "<Name>" <klasse> [<klasse> ...]
#   ./kunde-anlegen.sh k01 "Pflegedienst Muster" pflege finanzen personal unsortiert
#   ./kunde-anlegen.sh h01 "Hof Beispiel" finanzen betrieb unsortiert
#
# Je Klasse entsteht: Server-Zugang (k01-pflege), Repository, Administrations- und Agentenschlüssel,
# Healthchecks-Prüfung. Geheimnisse landen in geheim/<kürzel>/ – danach in den Passworttresor übernehmen.
source "$(dirname "$0")/lib.sh"
benoetigt restic openssl jq curl

[[ $# -ge 3 ]] || fehler "Aufruf: $0 <kürzel> \"<Name>\" <klasse> [<klasse> ...]"
KUNDE="$1"; NAME="$2"; shift 2
pruefe_kuerzel "$KUNDE"
for kl in "$@"; do pruefe_klasse "$kl"; done

setze_admin_env
mkdir -p "$GEHEIM_DIR/$KUNDE"

# Bestehende Angaben übernehmen
KLASSEN_ALT=""
if [[ -f "$KUNDEN_DIR/$KUNDE.conf" ]]; then
  lade_kunde "$KUNDE"; KLASSEN_ALT="$KLASSEN"
else
  KUNDE_NAME=""; DATEN_BASIS_KUNDE="$DATEN_BASIS"
fi
KUNDE_NAME="$NAME"
KLASSEN="$(printf '%s\n' $KLASSEN_ALT "$@" | awk 'NF && !seen[$0]++' | xargs)"

server_benutzer() {  # $1 Benutzer, stdin Passwort
  # htpasswd ändert eine leere Datei nur mit -c (legt sie dann neu an)
  local cmd="f='$SERVER_HTPASSWD'; if [ -s \"\$f\" ]; then htpasswd -B -i \"\$f\" '$1'; else htpasswd -c -B -i \"\$f\" '$1'; fi"
  if [[ -n "${SERVER_SSH:-}" ]]; then
    ssh "$SERVER_SSH" "$cmd"
  else
    bash -c "$cmd"
  fi
}

for kl in "$@"; do
  repo="$KUNDE-$kl"
  dir="$GEHEIM_DIR/$KUNDE"
  info "Klasse $kl → Repository $repo"

  # 1. Server-Zugang für den Agenten (nur hinzufügen, nur dieses Repository)
  if [[ ! -s "$dir/$kl.rest.pass" ]]; then
    pw="$(zufall 40)"
    printf '%s\n' "$pw" | server_benutzer "$repo" \
      || fehler "Zugang $repo konnte auf dem Server nicht angelegt werden"
    printf '%s' "$pw" > "$dir/$kl.rest.pass"
    echo "    Server-Zugang angelegt (rest-server liest die Passwortdatei nach spätestens ~30 s neu ein)"
  else
    echo "    Server-Zugang existiert bereits"
  fi

  # 2. Repository mit Administrationsschlüssel anlegen, Agentenschlüssel hinzufügen
  [[ -s "$dir/$kl.admin.key" ]] || zufall 48 > "$dir/$kl.admin.key"
  [[ -s "$dir/$kl.agent.key" ]] || zufall 48 > "$dir/$kl.agent.key"
  r=(restic -r "$(admin_repo "$repo")")
  if "${r[@]}" --password-file "$dir/$kl.admin.key" cat config >/dev/null 2>&1; then
    echo "    Repository existiert bereits"
  else
    "${r[@]}" --password-file "$dir/$kl.admin.key" init
    echo "    Repository angelegt (Administrationsschlüssel)"
  fi
  if "${r[@]}" --password-file "$dir/$kl.agent.key" cat config >/dev/null 2>&1; then
    echo "    Agentenschlüssel vorhanden"
  else
    "${r[@]}" --password-file "$dir/$kl.admin.key" \
      key add --new-password-file "$dir/$kl.agent.key" --host "agent-$KUNDE"
    echo "    Agentenschlüssel hinzugefügt"
  fi

  # 3. Überwachung: Alarm, wenn 36 h keine erfolgreiche Sicherung
  var="HC_$kl"
  if [[ -z "${!var:-}" ]]; then
    printf -v "$var" '%s' "$(hc_pruefung "$repo" 86400 43200 "$KUNDE backup")"
  fi
done

# Wöchentlicher Fehlablage-Bericht (Alarm, wenn 8 Tage keiner kommt)
if [[ -z "${HC_fehlablage:-}" ]]; then
  HC_fehlablage="$(hc_pruefung "$KUNDE-fehlablage" 604800 86400 "$KUNDE bericht")"
fi

# Kundendatei schreiben
{
  echo "# Kundendatei – erzeugt von kunde-anlegen.sh am $(date -I)"
  printf 'KUNDE_NAME=%q\n' "$KUNDE_NAME"
  printf 'KLASSEN=%q\n' "$KLASSEN"
  printf 'DATEN_BASIS_KUNDE=%q\n' "$DATEN_BASIS_KUNDE"
  for kl in $KLASSEN; do var="HC_$kl"; printf '%s=%q\n' "$var" "${!var:-}"; done
  printf 'HC_fehlablage=%q\n' "${HC_fehlablage:-}"
} > "$KUNDEN_DIR/$KUNDE.conf"

info "Fertig: $KUNDEN_DIR/$KUNDE.conf"
[[ -n "${HC_API_KEY:-}" ]] || echo "Hinweis: HC_API_KEY leer – Ping-URLs bei Bedarf von Hand in $KUNDEN_DIR/$KUNDE.conf eintragen."
echo "WICHTIG: Schlüssel aus $GEHEIM_DIR/$KUNDE/*.admin.key in den Passworttresor übernehmen"
echo "         und für den Notfallumschlag des Kunden ausdrucken."
