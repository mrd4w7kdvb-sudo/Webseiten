# Gerätewächter (Stockwerk IT)

Selbst gehostetes Monitoring für die Geräte deiner Kunden – zugeschnitten auf **mobile Pflegedienste** und **landwirtschaftliche Betriebe**. Datensparsam aufgebaut: Personenbezogene Daten werden schon auf dem Kundengerät entfernt.

```
 Kundengerät (Windows / Linux / Raspberry Pi / macOS)          Deine Cloud-VM
 ┌──────────────────────────────┐   HTTPS (TLS ≥ 1.2)   ┌───────────────────────────────┐
 │ gw-agent (Systemdienst)      │ ────────────────────▶ │ Caddy (Let's Encrypt)         │
 │  • Kennzahlen (CPU, RAM, …)  │   nur ausgehend,      │   └─ gw-server                │
 │  • Programme/Dienste laufen? │   alle 60 s, gzip     │       • SQLite-Datenbank      │
 │  • System- & Software-Logs   │                       │       • Alarm-Engine          │
 │  • Datenschutz-Filter  ◀──── │ hier wird bereinigt   │       • Weboberfläche         │
 │  • Offline-Puffer            │                       │       • E-Mail/ntfy/Webhook/  │
 └──────────────────────────────┘                       │         Telegram              │
                                                        └───────────────────────────────┘
```

## Funktionen

- **Mandantenfähig:** Kunden mit Branche (Pflege, Landwirtschaft, sonstige), je Kunde eigener Registrierungsschlüssel.
- **Agent je Betriebssystem:** Windows (x64/ARM), Linux (x64/ARM64/ARMv7 – auch Raspberry Pi), macOS (Intel/Apple Silicon). Ein einzelnes Programm ohne Abhängigkeiten, läuft als Windows-Dienst, systemd-Dienst oder launchd-Daemon.
- **Erhobene Daten:** CPU, Arbeitsspeicher, Auslagerung, Festplatten, Netzwerkdurchsatz/-fehler, Laufzeit, Prozessanzahl, Akku (Tourengeräte), CPU-Temperatur (Linux, z.B. Stall-PC), Laufzustand eingetragener Programme und Dienste, System-Logs (Windows-Ereignisprotokoll System/Anwendung, systemd-Journal, macOS Unified Log) und Logs eingetragener Software.
- **Eigene Alarmregeln** – global, je Kunde oder je Gerät:
  - *Messwert-Schwelle* (`disk.percent > 90`, optional „seit X Minuten“, Label-Filter wie `mount=C:` oder `name=*`)
  - *Erreichbarkeit* (keine Meldung seit X Minuten)
  - *Log-Ereignisse* (≥ N Einträge bis Stufe X, Quelle/Text als regulärer Ausdruck, in X Minuten)
  - Schwere (info/warnung/kritisch), Erinnerung bei Daueralarm, automatische Entwarnung, Quittieren.
- **Branchen-Vorlagen** per Klick: „Basis“, „Mobiler Pflegedienst“ (Pflegesoftware, Akku, Synchronisation, Backup, Tourengerät offline) und „Landwirtschaft / Hof“ (Steuerungsrechner, Dienste, Temperatur, Kamera-Speicher, Netzwerkabbrüche).
- **Benachrichtigungen:** E-Mail (SMTP mit TLS), ntfy (Push aufs Handy), Telegram, Webhook (Teams, Slack, Mattermost …).
- **Weboberfläche:** Übersicht, Alarme, Kunden, Geräte mit Verlaufsdiagrammen und Logs, Regeln, Kanäle. Keine externen Skripte/Fonts/CDNs.

## Datenschutz in Kürze

Ausführlich in [docs/DATENSCHUTZ.md](docs/DATENSCHUTZ.md), zur Sicherheit der Verbindung, zu Virenscannern und Code-Signierung siehe [docs/SICHERHEIT.md](docs/SICHERHEIT.md).

- Feste Datenstruktur – Benutzernamen, Dateien, Kommandozeilen, Fenstertitel, Verbindungen, Standort usw. *können* gar nicht übertragen werden.
- Bereinigung **auf dem Gerät** (und ein zweites Mal auf dem Server): Namen nach Anrede/Rolle („Frau Müller“, „Patient Hans Meier“), lokale Konten und deren Klarnamen, Rechnername, E-Mail, Telefon, IBAN, Krankenversicherten- und SV-Nummer, Geburtsdaten, Adressen/PLZ+Ort, IP/MAC, GPS-Koordinaten, Benutzerpfade, Dokument-/Bilddateinamen, Passwörter/Tokens, URL-Pfade.
- Software-Logs standardmäßig **nur als Metadaten** (Zeit, Quelle, Stufe, nicht umkehrbarer Fingerabdruck – kein Text).
- Datenschutz-Einstellungen sind **nur lokal** änderbar. Der Server kann keine Befehle ausführen und keine Dateien anfordern.
- Kontrolle für den Kunden: `gw-agent preview` zeigt eine Meldung, ohne sie zu senden; `letzte-meldung.json` enthält die zuletzt gesendete.
- Keine IP-Protokollierung auf dem Server, automatische Löschfristen.

> **Vor dem ersten Kundeneinsatz:** Checkliste in [docs/BETRIEB.md](docs/BETRIEB.md) abarbeiten (2FA, externer Wächter, externe Sicherung, Code-Signierung, AVV). Dort stehen auch Updates, Wiederherstellung und Notfälle.

## Server in der Cloud einrichten

Voraussetzung: Linux-VM (z.B. Hetzner, IONOS, netcup, STRATO – Rechenzentrum in Deutschland empfohlen) mit Docker, eine Domain mit A-Record auf die VM, Ports 80/443 offen. 1 vCPU / 2 GB RAM reichen für mehrere hundert Geräte.

```bash
git clone <dieses Repository> && cd Webseiten/geraetewaechter/deploy
cp .env.example .env        # GW_DOMAIN und GW_ADMIN_PASSWORD setzen
docker compose up -d --build
```

Danach `https://<deine-domain>` öffnen und anmelden. Ohne `GW_ADMIN_PASSWORD` erzeugt der Server beim ersten Start ein Passwort und schreibt es ins Log (`docker compose logs server`).

Passwort zurücksetzen: `docker compose exec server gw-server passwd admin`
Zwei-Faktor-Anmeldung abschalten (Handy verloren): `docker compose exec server gw-server totp-reset admin`

Nach der ersten Anmeldung unter **Einstellungen → Zwei-Faktor-Anmeldung** einrichten.

**Backup:** täglich automatisch in `/data/backups`; jederzeit konsistent per `docker compose exec server gw-server backup /data/export.db`. Externe Sicherung siehe [docs/BETRIEB.md](docs/BETRIEB.md).

### Konfiguration (Umgebungsvariablen)

| Variable | Standard | Bedeutung |
|---|---|---|
| `GW_PUBLIC_URL` | `http://localhost:8080` | Öffentliche Adresse (für Installationsbefehle und Links) |
| `GW_LISTEN` | `:8080` | Adresse/Port des Servers |
| `GW_DATA_DIR` | `./data` | Datenbankverzeichnis |
| `GW_DOWNLOADS_DIR` | `./downloads` | Agent-Programme für die Installation |
| `GW_ADMIN_USER` / `GW_ADMIN_PASSWORD` | `admin` / – | Erster Admin |
| `GW_METRIC_RETENTION_DAYS` | 30 | Aufbewahrung Messwerte |
| `GW_LOG_RETENTION_DAYS` | 14 | Aufbewahrung Logs |
| `GW_ALERT_RETENTION_DAYS` | 180 | Aufbewahrung behobener Alarme |
| `GW_EVALUATE_SECONDS` | 30 | Prüfintervall der Alarmregeln |
| `GW_BACKUP_KEEP` | 7 | Anzahl täglicher Datenbanksicherungen in `/data/backups` (0 = aus) |
| `GW_HEARTBEAT_URL` | – | Lebenszeichen alle 5 Minuten an einen externen Wächter (z.B. healthchecks.io), der alarmiert, wenn der Server ausfällt |
| `GW_COOKIE_SECURE` | `true` | nur für lokale Tests ohne HTTPS auf `false` |

## Kundengerät anbinden

1. In der Oberfläche **Kunden → Neuer Kunde** anlegen (Branche wählen, „Neue Geräte erst nach Freigabe annehmen“ bleibt an).
2. Auf der Kundenseite den passenden Befehl kopieren und auf dem Gerät mit Administratorrechten ausführen:
   - **Windows:** `gw-agent-windows_amd64.exe` über den Link herunterladen, dann in einer Eingabeaufforderung als Administrator:
     `gw-agent-windows_amd64.exe setup --server https://<domain> --token <SCHLÜSSEL>`
     (Alternativ zeigt die Kundenseite einen PowerShell-Zweizeiler ohne Browser.)
   - **Linux / Raspberry Pi / macOS:**
     `curl -fsSL https://<domain>/install.sh | sudo sh -s -- <SCHLÜSSEL>`

   `setup` kopiert das Programm an seinen festen Ort (`C:\Program Files\Geraetewaechter` bzw. `/usr/local/bin`), registriert das Gerät und richtet den Dienst ein. Das Linux/macOS-Skript prüft vorher die SHA-256-Prüfsumme.
3. Gerät in der Oberfläche **freigeben** und umbenennen (Funktion statt Personenname, z.B. „Tour-Laptop 3“, „Melkstand-PC“).
4. **Vorlage anwenden** auf der Kundenseite und Benachrichtigungskanal wählen.

Registrierungsschlüssel sind standardmäßig 7 Tage gültig („Neu erzeugen“ auf der Kundenseite).

### Agent-Konfiguration beim Kunden

| System | Konfiguration | Arbeitsordner (Status, Puffer, Kontrolldatei) |
|---|---|---|
| Windows | `C:\ProgramData\Geraetewaechter\agent.json` | `C:\ProgramData\Geraetewaechter\data` |
| Linux | `/etc/geraetewaechter/agent.json` | `/var/lib/geraetewaechter` |
| macOS | `/Library/Application Support/Geraetewaechter/agent.json` | `…/Geraetewaechter/data` |

Der Ordner ist nur für SYSTEM/Administratoren bzw. root lesbar. Beispiel mit allen Optionen: [deploy/agent.example.json](deploy/agent.example.json). Nach Änderungen: `gw-agent restart`.

- `app_logs`: Log-Dateien der Pflege-/Hofsoftware (Platzhalter `*` erlaubt). `mode`: `metadata` (Standard, kein Text) oder `redact` (bereinigter Text). `max_level`: 3 = nur Fehler, 4 = auch Warnungen.
- `privacy.deny_words`: Begriffe, die immer entfernt werden (z.B. Hofname, Ortsname, Namen häufiger Klienten).
- `privacy.deny_patterns`: eigene reguläre Ausdrücke zum Entfernen.
- `privacy.system_log_mode`: `redact` (Standard), `metadata` oder `off`.
- `ca_file`: nur dieser Zertifizierungsstelle vertrauen (Pinning), `proxy`: Proxy des Kundennetzes – siehe [docs/SICHERHEIT.md](docs/SICHERHEIT.md).
- `watch_processes` / `watch_services`: zusätzlich zu den zentral beim Kunden hinterlegten Namen.

Befehle: `gw-agent setup | preview | once | status | start | stop | restart | uninstall | paths | version`

## Entwicklung

```bash
make test     # Vet + Tests (u.a. Datenschutz-Filter)
make winres   # Herstellerangaben „Stockwerk IT“ in die Windows-.exe einbetten (vor make agents)
make sign SIGN="…"   # Windows-Agenten signieren, siehe docs/SICHERHEIT.md
make run      # Server + alle Agenten bauen, Server lokal ohne HTTPS starten (admin / testpasswort123)
```

Lokaler Agent-Test ohne Root:

```bash
export GW_AGENT_CONFIG=/tmp/gw/agent.json GW_AGENT_DATA=/tmp/gw/data
go run ./cmd/gw-agent enroll --server http://localhost:8080 --token <SCHLÜSSEL>
go run ./cmd/gw-agent once
```

Aufbau: `cmd/gw-server`, `cmd/gw-agent`, `internal/privacy` (Bereinigung, mit Tests), `internal/proto` (Datenformat), `internal/server` (API, Alarm-Engine, Benachrichtigung, Weboberfläche in `web/`), `internal/agent` (Erfassung, betriebssystemspezifisch in `os_*.go`).

## Grenzen

- Smartphones/Tablets mit Android oder iOS können den Agenten nicht ausführen (Plattform-Beschränkung). Für solche Tourengeräte bietet sich ein MDM an; Windows-Tablets und Laptops werden unterstützt.
- Windows: CPU-Temperatur wird nicht erfasst; Software-Logs müssen als Textdatei vorliegen (UTF-8 oder ANSI).
- Die Namenserkennung arbeitet mit Mustern (Anrede, Rolle, bekannte Konten). Freie Namen ohne solchen Kontext in Software-Logs können nicht sicher erkannt werden – deshalb ist für Software-Logs „nur Metadaten“ voreingestellt.
