# Betriebshandbuch Gerätewächter (Stockwerk IT)

## 1. Checkliste vor dem ersten Kundeneinsatz

| ✓ | Schritt | Wo |
|---|---|---|
| ☐ | Cloud-VM in Deutschland/EU, AVV mit dem Hoster abgeschlossen | Hoster |
| ☐ | Betriebssystem aktuell, automatische Sicherheitsupdates an (`unattended-upgrades`), SSH nur mit Schlüssel, Root-Login aus | VM |
| ☐ | Firewall: nur 22 (SSH, idealerweise nur von deiner IP), 80, 443 | VM / Hoster |
| ☐ | `deploy/.env`: Domain, langes Admin-Passwort, `GW_HEARTBEAT_URL` gesetzt | Server |
| ☐ | `docker compose up -d --build`, Anmeldung über https funktioniert | Server |
| ☐ | **Zwei-Faktor-Anmeldung** eingerichtet (Einstellungen) | Oberfläche |
| ☐ | Verwaltung auf Büro/VPN beschränkt (Block in `deploy/Caddyfile` einkommentiert) – empfohlen | Server |
| ☐ | Benachrichtigungskanal angelegt und **Test gesendet** | Oberfläche |
| ☐ | Externer Wächter (z.B. healthchecks.io, Uptime Kuma) meldet Ausfall des Servers | extern |
| ☐ | Externe Sicherung eingerichtet (Abschnitt 4) und eine Wiederherstellung einmal geprobt | Server |
| ☐ | Code-Signing-Zertifikat beschafft, Windows-Agenten signiert (`make sign`, docs/SICHERHEIT.md) | Build |
| ☐ | Pilotkunde: Agent auf einem Gerät installiert, `gw-agent preview` gemeinsam mit dem Kunden angesehen | Kunde |
| ☐ | AVV mit dem Kunden, DATENSCHUTZ.md und SICHERHEIT.md als Anlage | Vertrag |

## 2. Neuen Kunden anbinden

1. Kunde anlegen (Branche wählen, „Neue Geräte erst nach Freigabe annehmen“ an).
2. Vorlage anwenden, Benachrichtigungskanal wählen.
3. Bei Pflege-/Hofsoftware: Prozess- und Dienstnamen beim Kunden eintragen. Log-Dateien der Software trägt der Kunde bzw. du **lokal** in `agent.json` ein (Standard: nur Metadaten).
4. Installationsbefehl auf dem Gerät ausführen, Gerät in der Oberfläche **freigeben** und nach Funktion benennen.
5. Mit dem Kunden `gw-agent preview` ansehen und die Kontrolldatei `letzte-meldung.json` zeigen.

## 3. Updates

**Server:** `git pull && cd deploy && docker compose up -d --build`. Die Datenbank wird beim Start automatisch auf neue Versionen umgestellt; vorher eine Sicherung anlegen (Abschnitt 4).

**Agenten:** Es gibt bewusst **keine automatische Fernaktualisierung** – ein Server, der Programme auf Kundengeräten austauschen kann, wäre ein Einfallstor. Neue Version auf dem Gerät mit Administratorrechten einspielen:

- Windows: neue `gw-agent-windows_amd64.exe` herunterladen, dann
  `gw-agent-windows_amd64.exe setup --server https://<domain> --token -`
- Linux/macOS: `curl -fsSL https://<domain>/install.sh | sudo sh -s -- -`

Ist das Gerät bereits registriert, wird der Schlüssel (`-`) nicht benötigt; Registrierung und Einstellungen bleiben erhalten. Die Version jedes Geräts steht in der Geräteansicht.

## 4. Sicherung und Wiederherstellung

- Der Server legt täglich eine Sicherung in `/data/backups` an (`GW_BACKUP_KEEP`, Standard 7 Tage).
- Zusätzlich **außerhalb** des Servers sichern, z.B. per Cronjob auf der VM:
  ```bash
  docker compose -f /pfad/deploy/docker-compose.yml exec -T server gw-server backup /data/export.db
  docker cp "$(docker compose -f /pfad/deploy/docker-compose.yml ps -q server)":/data/export.db /root/gw-$(date +%F).db
  # danach verschlüsselt auf ein anderes System kopieren (z.B. restic, borg)
  ```
- Wiederherstellung: Server stoppen, Datei als `/data/geraetewaechter.db` in das Volume `gw-data` kopieren (Dateien `-wal`/`-shm` vorher löschen), Server starten.
- Mit der Datenbank werden auch die Geräteschlüssel (als Hash) gesichert – Agenten müssen nach einer Wiederherstellung nicht neu registriert werden.

## 5. Notfälle

| Situation | Maßnahme |
|---|---|
| Admin-Passwort vergessen | `docker compose exec server gw-server passwd admin` |
| Handy mit Authenticator verloren | `docker compose exec server gw-server totp-reset admin`, danach neu einrichten |
| Gerät gestohlen / Kunde beendet Vertrag | Gerät in der Oberfläche deaktivieren oder entfernen; beim Kunden `gw-agent uninstall` |
| Registrierungsschlüssel versehentlich weitergegeben | Kundenseite → „Neu erzeugen“; unbekannte Geräte in „wartet auf Freigabe“ entfernen |
| Verdacht auf Server-Einbruch | Server vom Netz, Sicherung prüfen, neu aufsetzen, Admin-Passwort und 2FA neu, Registrierungsschlüssel aller Kunden neu erzeugen, Geräte bei Bedarf neu registrieren. Kundengeräte selbst sind nicht gefährdet, da der Agent keine Befehle annimmt. Meldepflichten (Art. 33 DSGVO, 72 h) prüfen. |
| Viele Geräte gleichzeitig offline | Erst eigenen Server/Netz prüfen; nach einer Server-Wartung entstehen dank Schonfrist keine Fehlalarme |
| Virenscanner meldet den Agenten | Version bei VirusTotal prüfen, Datei über das Microsoft-Meldeportal als Fehlalarm einreichen, signierte Version verwenden |

## 6. Was automatisch geprüft wird

Jede Änderung am Code durchläuft auf GitHub (Workflow „Gerätewächter – Tests auf echten Systemen“):

- Unit- und Integrationstests (Datenschutz-Filter, Anmeldung, CSRF, Freigabe, Ablauf, Alarme, Backup) mit Race-Detector
- Schwachstellenprüfung aller Abhängigkeiten (govulncheck)
- **Windows:** Herstellerangaben in der .exe, Defender-Scan, Installation als Dienst, Lesen des Ereignisprotokolls, Bereinigung personenbezogener Daten, Ordnerrechte, Kontrolle dass keine Hilfsprogramme gestartet werden, Deinstallation
- **Linux:** Installation per `install.sh`, systemd-Sandbox (Bewertung ≤ 4,0), Journal, Bereinigung, Dateirechte, Deinstallation
- **macOS:** Installation mit launchd, Meldung, Dateirechte, Deinstallation
- **Docker:** Image-Bau, Start mit allen Härtungsoptionen, Downloads, Backup

Vor jeder Auslieferung einer neuen Version sollte dieser Workflow grün sein.
