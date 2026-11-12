# Sicherheitskonzept Gerätewächter (Stockwerk IT)

Dieses Dokument beschreibt, wie die Verbindung zwischen dem Gerätewächter-Agenten auf Kundengeräten und dem Monitoring-Server von Stockwerk IT abgesichert ist. Es kann an Kunden, deren IT-Betreuer und Datenschutzbeauftragte weitergegeben werden. Zum Umgang mit personenbezogenen Daten siehe [DATENSCHUTZ.md](DATENSCHUTZ.md).

## 1. Übersicht

| Thema | Umsetzung |
|---|---|
| Verbindungsrichtung | Nur ausgehend vom Gerät zum Server (HTTPS, Port 443). Beim Kunden wird kein Port geöffnet, der Agent ist von außen nicht ansprechbar. |
| Verschlüsselung | TLS 1.2/1.3 mit Let's-Encrypt-Zertifikat; unverschlüsseltes HTTP wird vom Agenten abgelehnt. Die Zertifikatsprüfung kann nicht abgeschaltet werden. |
| Server-Identität | Prüfung gegen die Zertifizierungsstellen des Betriebssystems; optional Festlegung auf eine eigene Zertifizierungsstelle (`ca_file`). Umleitungen auf andere Server werden nicht befolgt. |
| Geräte-Identität | Jedes Gerät hat einen eigenen 256-Bit-Zufallsschlüssel. Der Server speichert nur dessen SHA-256-Hash. |
| Registrierung | Registrierungsschlüssel je Kunde, standardmäßig 7 Tage gültig; optional muss jedes neue Gerät in der Verwaltung freigegeben werden. |
| Fernsteuerung | **Keine.** Der Server kann keine Befehle ausführen, keine Dateien anfordern, keine Log-Dateien hinzufügen und keine Datenschutzeinstellungen ändern. |
| Rechte auf dem Gerät | Windows: Dienst als SYSTEM, startet keine anderen Programme (kein PowerShell, kein cmd). Linux: systemd-Sandbox (nur Lesezugriff aufs System, keine Rechteerweiterung, eingeschränkte Systemaufrufe). |
| Schutz der Zugangsdaten | Konfiguration nur für SYSTEM/Administratoren bzw. root lesbar. |
| Verwaltung | bcrypt-Passwörter, Zwei-Faktor-Anmeldung (TOTP), Sitzungscookies mit HttpOnly/Secure/SameSite-Strict, CSRF-Schutz, Schutz gegen Passwort-Raten, optional nur aus dem eigenen Netz erreichbar. |

## 2. Was passiert, wenn …

**… jemand den Datenverkehr mitliest?** Er sieht nur verschlüsselte Daten (TLS). Ohne gültiges Zertifikat für die Server-Domain kann er sich nicht als Server ausgeben.

**… der Registrierungsschlüssel in falsche Hände gerät?** Damit lassen sich nur neue Geräte anmelden, keine Daten lesen. Nach Ablauf (Standard 7 Tage) ist der Schlüssel wertlos; mit aktivierter Freigabe erscheinen fremde Geräte nur als „wartet auf Freigabe“ und ihre Daten werden verworfen.

**… der Schlüssel eines Geräts gestohlen wird?** Damit kann man nur Messwerte im Namen dieses Geräts senden. Das Gerät wird in der Verwaltung deaktiviert oder gelöscht und neu registriert.

**… der Server kompromittiert wird?** Der Angreifer könnte die gespeicherten Messwerte und bereinigten Logs lesen, aber die Kundengeräte **nicht** steuern, da der Agent keine Befehle annimmt. Ein Restrisiko betrifft nur *neue* Installationen, da Programm und Prüfsumme vom Server geladen werden. Deshalb sollen die Windows-Programme signiert werden (Abschnitt 4), sodass eine Manipulation an der Signatur erkannt wird.

## 3. Virenscanner und Windows-Warnungen

Der Agent ist so gebaut, dass er keine typischen Verhaltensmuster von Schadsoftware zeigt:

- Er startet unter Windows keine Hilfsprogramme. Ereignisprotokoll, Benutzerkonten und Ordnerrechte werden direkt über die Windows-Schnittstellen gelesen bzw. gesetzt.
- Die Installation lädt keine Skripte aus dem Internet zur direkten Ausführung. Es wird nur das Programm heruntergeladen und mit `gw-agent.exe setup` eingerichtet.
- Das Programm enthält Herstellerangaben (Stockwerk IT, Produkt, Version), sobald es mit `make winres` gebaut wird.

Ohne **digitale Signatur** zeigt Windows SmartScreen beim Start einer im Browser heruntergeladenen Datei trotzdem „Unbekannter Herausgeber“, und einzelne Virenscanner können unsignierte Programme fälschlich melden. Abhilfe schafft nur eine Signatur (nächster Abschnitt).

## 4. Code-Signierung (für Stockwerk IT)

1. **Zertifikat beschaffen:** Authenticode-Code-Signing-Zertifikat auf „Stockwerk IT“ (OV genügt). Seit 2023 liegt der Schlüssel zwingend auf einem Hardware-Token oder in einem Cloud-HSM. Anbieter z.B. Certum, Sectigo, SSL.com, GlobalSign (ca. 200–400 €/Jahr), oder Microsofts Signierdienst „Trusted Signing“ (monatliche Gebühr; Voraussetzungen zu Firmensitz und -alter vorab prüfen).
2. **Signieren im Build:** `make agents && make sign SIGN="<Signierbefehl>"`, z.B. mit `jsign` (Linux/macOS, unterstützt Token und Cloud-HSMs) oder `signtool` (Windows). Immer mit Zeitstempel (`--tsaurl`/`/tr`), damit die Signatur auch nach Ablauf des Zertifikats gültig bleibt.
3. **Reputation:** Auch signierte Programme brauchen anfangs einige Installationen, bis SmartScreen nicht mehr warnt. Jede neue Version vor der Verteilung bei VirusTotal prüfen und Fehlalarme über das Microsoft-Meldeportal für Dateien (Security Intelligence, „Submit a file“) als „incorrectly detected“ einreichen.
4. **macOS (optional):** Apple-Entwickler-ID (99 $/Jahr), Programm mit `codesign` signieren und von Apple beglaubigen lassen (`notarytool`).

## 5. Empfehlungen für den Betrieb

- Zwei-Faktor-Anmeldung für alle Verwaltungskonten aktivieren (Einstellungen).
- Bei Kunden „Neue Geräte erst nach Freigabe annehmen“ eingeschaltet lassen, Registrierungsschlüssel nur kurz gültig erzeugen.
- Verwaltungsoberfläche per Caddy auf Büro-/VPN-Netz beschränken (Vorlage in `deploy/Caddyfile`).
- Server-Betriebssystem und Docker-Images regelmäßig aktualisieren, SSH nur mit Schlüssel, Firewall nur 22/80/443.
- Den Server-Port 8080 nie direkt veröffentlichen, nur über Caddy (der die Client-Adresse für den Schutz gegen Passwort-Raten setzt).
- Datenbank regelmäßig verschlüsselt sichern.

## 6. Agent-Optionen mit Sicherheitsbezug

| Option in `agent.json` | Wirkung |
|---|---|
| `ca_file` | PEM-Datei; der Agent vertraut dann **nur** diesen Zertifizierungsstellen (z.B. ISRG Root X1 für Let's Encrypt oder eine eigene CA). |
| `proxy` | HTTP(S)-Proxy des Kundennetzes, z.B. `http://proxy.firma.local:3128`. Ohne Angabe gelten die Umgebungsvariablen `HTTPS_PROXY`/`NO_PROXY`. |
| `allow_http` | Nur für Tests im eigenen Netz; in Produktion nie setzen. |
