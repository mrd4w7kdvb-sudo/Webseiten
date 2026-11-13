# Backup-Plattform für Pflegedienste und landwirtschaftliche Betriebe

Eigener Backup-Server bei IONOS oder STRATO, ein Agent auf jedem Klienten-PC, eine unveränderbare Offsite-Kopie
in IONOS S3 und eine eigene Überwachung. Kein NAS beim Kunden, keine Microsoft-Dienste.

```
Klienten-PC (Agent: restic + resticprofile)
   │ HTTPS, verschlüsselt, nur hinzufügen
   ▼
Backup-Server (Caddy → rest-server --append-only --private-repos)
   │ nachts rclone                      ▲ WireGuard: rest-admin (aufräumen, prüfen)
   ▼                                    │
IONOS S3 (Versionierung + Object Lock)  Wartungsrechner bei dir
                Healthchecks (separater vServer) ← Statusmeldungen von allen
```

## Inhalt

| Ordner | Wohin | Zweck |
| --- | --- | --- |
| `server/` | Backup-Server (`/opt/backup-plattform/server`) | Docker Compose, Grundeinrichtung, Offsite-Kopie, Zustandsprüfung |
| `healthchecks/` | separater kleiner vServer | Überwachung und Alarme |
| `wartung/` | dein Wartungsrechner (Linux oder WSL) | Kunden anlegen, Pakete bauen, aufräumen, Restore-Tests, Berichte |
| `agent/` | wird in jedes Kundenpaket kopiert | Installation auf Windows-PCs, Fehlablage-Bericht |

Geheimnisse (Schlüssel, Passwörter, Kundendaten) entstehen nur in `wartung/geheim/`, `wartung/kunden/` und
`wartung/pakete/`. Diese Ordner stehen in `.gitignore` und dürfen nie in ein Repository.

---

## 1. Überwachung einrichten (Healthchecks-vServer)

Zuerst, damit alle weiteren Teile ihre Meldungen gleich senden können. Möglichst ein anderer Anbieter als der
Backup-Server, damit ein Ausfall des Servers auffällt.

1. Kleinsten vServer mit Debian bestellen, Docker installieren, DNS-Eintrag z. B. `hc.example.de` setzen.
2. Ordner `healthchecks/` nach `/opt/healthchecks` kopieren, `cp env.example .env` und ausfüllen
   (Domain, `SECRET_KEY`, SMTP-Zugang für Alarm-Mails).
3. `docker compose up -d`
4. Admin anlegen: `docker compose exec healthchecks /opt/healthchecks/manage.py createsuperuser`
5. Anmelden, im Projekt unter **Settings → API Access** einen API-Schlüssel mit Schreibrecht erzeugen
   (für `wartung/config.env`), unter **Integrations** E-Mail und z. B. Telegram/Signal einrichten.
6. Zwei Prüfungen von Hand anlegen und deren Ping-URLs notieren:
   `offsite` (Zeitraum 1 Tag, Toleranz 12 h) und `server` (1 Tag, 12 h).

## 2. Backup-Server einrichten

### 2.1 Bestellen und Betriebssystem

- Dedicated Server, Standort Deutschland, mind. 4 Datenplatten, 32 GB RAM.
- AV-Vertrag mit dem Hoster im Kundenportal abschließen, 2-Faktor-Anmeldung aktivieren.
- Debian installieren. Die Datenplatten als **RAID 6** (mdadm) oder **ZFS RAID-Z2** zusammenfassen und mit
  **LUKS verschlüsseln**; einhängen unter `/srv/backup`.
- Damit nach einem Neustart niemand vor Ort das Passwort eingeben muss: Datenbereich nicht in `/etc/crypttab`
  automatisch öffnen, sondern nach dem Neustart per SSH entsperren
  (`cryptsetup open … && mount /srv/backup && systemctl restart docker`). Der LUKS-Schlüssel liegt nur in
  deinem Passworttresor. Alternativ das ganze System verschlüsseln und per `dropbear-initramfs` entsperren.
- Eigenen SSH-Schlüssel in `/root/.ssh/authorized_keys` hinterlegen.

### 2.2 Grundeinrichtung

```bash
git clone <dieses Repository> /opt/backup-plattform-repo
cp -r /opt/backup-plattform-repo/backup-plattform /opt/backup-plattform
cd /opt/backup-plattform/server
cp env.example .env && nano .env              # DOMAIN, Ping-URLs offsite/server
ADMIN_IP=<feste IP deines Büros> ./setup-server.sh
```

Das Skript installiert Docker, Firewall (nur 443/tcp, 51820/udp, SSH eingeschränkt), fail2ban, automatische
Sicherheitsupdates und WireGuard, legt den Wartungszugang an und richtet die Cron-Jobs ein.
**Das ausgegebene Passwort für den Benutzer `wartung` sofort in den Passworttresor übernehmen.**

### 2.3 Offsite-Bucket bei IONOS

1. Im IONOS-Portal einen S3-Bucket anlegen, möglichst an einem anderen Standort als der Server.
   **Versionierung an, Object Lock an, Standard-Aufbewahrung Compliance-Modus 30 Tage.**
2. Zugangsschlüssel nur für diesen Bucket erzeugen.
3. `/root/.config/rclone/rclone.conf` nach `rclone.conf.example` anlegen, `RCLONE_ZIEL` in `.env` setzen.
4. Testen: `rclone lsd ionos:` und einmal `backup-offsite-sync` von Hand ausführen.
   Prüfen, dass das Hochladen in den gesperrten Bucket klappt (manche S3-Anbieter verlangen bei Object Lock
   Prüfsummen; bei Fehlern rclone aktualisieren).

### 2.4 Starten

```bash
cd /opt/backup-plattform/server
docker compose up -d
docker compose logs -f caddy       # Zertifikat wird beim ersten Aufruf geholt
curl -I https://<DOMAIN>/          # erwartet: 401 Unauthorized (Anmeldung nötig) = läuft
```

## 3. Wartungsrechner einrichten

Linux oder Windows mit WSL. Den Ordner `wartung/` möglichst auf ein verschlüsseltes Laufwerk legen.

1. Programme: `restic` (**mind. 0.17**, Download von restic.net, Paketquellen sind oft älter),
   `jq`, `curl`, `openssl`, `zip`, `ssh`, `wireguard-tools`.
2. WireGuard-Verbindung zum Server: Schlüssel erzeugen (`wg genkey | tee wg.key | wg pubkey`), auf dem Server in
   `/etc/wireguard/wg0.conf` als `[Peer]` mit `AllowedIPs = 10.8.0.2/32` eintragen,
   `systemctl restart wg-quick@wg0`. Lokal eine `wg0.conf` mit `Address = 10.8.0.2/24`,
   Peer = Server-Schlüssel, `Endpoint = <server>:51820`, `AllowedIPs = 10.8.0.1/32`.
3. `cp config.env.example config.env` und ausfüllen. Das Passwort des Benutzers `wartung` in
   `geheim/admin-rest.pass` speichern.
4. Test: `ping 10.8.0.1` und `ssh root@10.8.0.1 true`.
5. Cron für die wöchentliche Wartung, z. B. sonntags 10 Uhr:
   `0 10 * * 0 /pfad/wartung/wartung.sh >> /pfad/wartung/protokoll/wartung.log 2>&1`

## 4. Agent vorbereiten (einmalig)

In `agent/bin/` die Windows-Programme (64 Bit) ablegen:

- `restic.exe` von <https://github.com/restic/restic/releases> (**mind. 0.17**, Datei `restic_*_windows_amd64.zip`)
- `resticprofile.exe` von <https://github.com/creativeprojects/resticprofile/releases>
  (Datei `resticprofile_*_windows_amd64.zip`)

## 5. Neuen Kunden einrichten

```bash
cd wartung
# Pflegedienst
./kunde-anlegen.sh k01 "Pflegedienst Muster" pflege finanzen personal unsortiert
# Hof
./kunde-anlegen.sh h01 "Hof Beispiel" finanzen betrieb unsortiert

./paket-erstellen.sh k01           # → pakete/k01.zip
```

- Je Datenklasse entstehen ein eigener Server-Zugang, ein eigenes Repository mit **zwei Schlüsseln**
  (Administration, Agent) und eine Healthchecks-Prüfung (Alarm nach 36 h ohne Erfolg).
- Die Administrationsschlüssel aus `geheim/k01/*.admin.key` in den Passworttresor übernehmen und für den
  **Notfallumschlag** des Kunden ausdrucken.
- Standard-Ordner beim Kunden: `C:\Daten\Pflege`, `C:\Daten\Finanzen`, `C:\Daten\Personal`, `C:\Daten\Betrieb`.
  Abweichend (z. B. `D:\Daten`): in `kunden/k01.conf` `DATEN_BASIS_KUNDE` ändern und Paket neu erstellen.
- Weitere Klasse später: `./kunde-anlegen.sh k01 "Pflegedienst Muster" betrieb`, dann Paket neu erstellen und
  auf den PCs erneut `install.ps1` ausführen.

### Installation beim Kunden

Paket auf **verschlüsseltem** USB-Stick mitnehmen. Auf jedem PC (PowerShell als Administrator):

```powershell
cd C:\Temp\k01
powershell -ExecutionPolicy Bypass -File .\install.ps1 -ErsteSicherung
```

Danach den Paketordner löschen. Voraussetzungen und Hinweise:

- Die Nutzer dürfen **keine Administratorrechte** haben, sonst können sie den Agenten entfernen.
- Datenbank-Export der Branchensoftware in `C:\ProgramData\BackupAgent\vorher-pflege.cmd` eintragen.
- Bei schwacher Leitung (Hof) ohne `-ErsteSicherung` installieren; die Erstsicherung läuft dann über
  mehrere Nächte. Sehr große Datenmengen vorher abklären.
- Kontrolle: In Healthchecks muss für jede Klasse eine Meldung eingehen.

## 6. Laufender Betrieb

| Wann | Was | Befehl |
| --- | --- | --- |
| täglich | Alarme in Healthchecks bearbeiten | – |
| wöchentlich (automatisch) | Aufräumen nach Aufbewahrungsregeln, 5 % der Daten prüfen | `wartung.sh` per Cron |
| vierteljährlich | Wiederherstellungstest je Kunde | `./restore-test.sh k01` |
| monatlich | Bericht für den Kunden | `./bericht.sh k01` |
| monatlich | Fehlablage-Berichte in Healthchecks ansehen (Prüfung `k01-fehlablage`) | – |

Aufbewahrung: 30 tägliche, 8 wöchentliche, 12 monatliche und 10 jährliche Stände; Klasse `betrieb` ohne
Jahresstände. Anpassen in `wartung/lib.sh`, Funktion `aufbewahrung`.

## 7. Wiederherstellung

**Einzelne Dateien** (vom Wartungsrechner, Ergebnis per Fernwartung oder USB-SSD zum Kunden):

```bash
export RESTIC_REST_USERNAME=wartung RESTIC_REST_PASSWORD="$(cat geheim/admin-rest.pass)"
R="restic -r rest:http://10.8.0.1:8001/k01-pflege/ --password-file geheim/k01/pflege.admin.key"
$R snapshots
$R find "Akte Mustermann*"
$R restore latest --target ./wiederherstellung --include "/C/Daten/Pflege/Akte Mustermann.pdf"
```

**Server verloren oder kompromittiert:** neuen Server nach Abschnitt 2 aufsetzen, Daten aus S3 zurückholen und
die Agenten-Zugänge neu anlegen (die Passwortdatei wird nicht nach S3 kopiert):

```bash
rclone copy ionos:kunden-backup-offsite /srv/backup/data                 # aktueller Stand
rclone copy ionos:kunden-backup-offsite /srv/backup/data \
  --s3-version-at "2026-11-01 00:00:00"                                  # Stand vor einem Angriff
```

Danach für jedes Repository `printf '%s\n' "$(cat geheim/<kunde>/<klasse>.rest.pass)" | htpasswd -B -i
/srv/backup/data/.htpasswd <kunde>-<klasse>` (bzw. `-c` beim ersten Eintrag) auf dem Server ausführen.

## 8. Teststand

Getestet mit restic 0.18.0, rest-server 0.13.0 und resticprofile 0.34 unter Linux:

- Kunde anlegen, erneut ausführen und Klasse ergänzen
- Sicherung über das erzeugte Agentenprofil inklusive Statusmeldungen (Start, Erfolg, Fehler)
- Agent kann **nicht** löschen (403) und kommt **nicht** an fremde Repositorys
- Wartungslauf (Aufräumen und Prüfung), Restore-Test und Monatsbericht
- `shellcheck` für alle Shell-Skripte, `docker compose config` für beide Compose-Dateien

**Nicht getestet** (vor dem ersten Kunden auf einem Test-PC bzw. Testserver durchspielen):

- `install.ps1` und `fehlablage-bericht.ps1` auf echtem Windows (Zeitpläne, Rechte, Schattenkopie)
- `setup-server.sh` auf einem frischen Debian-Server, Docker-Images im Betrieb, Let's-Encrypt-Zertifikat
- Offsite-Kopie in einen IONOS-Bucket mit Object Lock
- Automatisches Anlegen der Prüfungen über die Healthchecks-API
