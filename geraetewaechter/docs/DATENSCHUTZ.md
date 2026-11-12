# Datenschutzkonzept Gerätewächter

Dieses Dokument beschreibt die technischen und organisatorischen Maßnahmen, mit denen der Gerätewächter verhindert, dass personenbezogene Daten von Kundengeräten abfließen. Es kann als Grundlage für die Dokumentation gegenüber Kunden (Pflegedienste, landwirtschaftliche Betriebe) und deren Datenschutzbeauftragten dienen.

> **Hinweis:** Dies ist eine technische Beschreibung, keine Rechtsberatung. Auch bei reinen Systemdaten ist ein Auftragsverarbeitungsvertrag (AVV, Art. 28 DSGVO) mit jedem Kunden zu empfehlen, da Gerätekennungen und Systemlogs in Einzelfällen einen Personenbezug haben können. Bei Pflegediensten zusätzlich § 203 StGB (Berufsgeheimnis) beachten – die unten beschriebene Gestaltung ist darauf ausgelegt, dass Gesundheitsdaten nicht übertragen werden.

## 1. Grundsätze

| Grundsatz (Art. 5 DSGVO) | Umsetzung |
|---|---|
| Datenminimierung | Feste Positivliste übertragbarer Felder; Bereinigung vor dem Versand; Software-Logs standardmäßig ohne Text |
| Zweckbindung | Ausschließlich technischer Betrieb/Störungsmeldung der Geräte |
| Speicherbegrenzung | Automatisches Löschen: Messwerte 30 Tage, Logs 14 Tage, behobene Alarme 180 Tage (einstellbar) |
| Integrität/Vertraulichkeit | TLS ≥ 1.2, gehashte Gerätschlüssel, Zugriffsschutz, keine Fernsteuerung |
| Transparenz | Vorschau- und Kontrolldatei auf jedem Gerät |

## 2. Was übertragen wird – und was nicht

**Übertragen werden ausschließlich:**

- Betriebssystem, Version, Kernel, Architektur, Laufzeit
- Kennzahlen: CPU-, Arbeitsspeicher-, Auslagerungs-, Festplattenbelegung (nur feste Laufwerke), Netzwerkdurchsatz als Summe, Netzwerkfehler, Anzahl Prozesse, Akkustand, CPU-Temperatur
- Laufzustand (ja/nein) der namentlich eingetragenen Programme und Dienste
- Log-Einträge mit: Zeit, Quelle (z.B. Dienstname), Stufe, Ereignis-ID, bereinigtem Text *oder* nur einem Fingerabdruck

**Technisch nicht erhoben** (es gibt dafür weder Code noch Felder im Datenformat):

- Benutzernamen, angemeldete Benutzer, Klarnamen
- Dateien, Dateiinhalte, Dateilisten, Dokumentnamen
- Prozess-Kommandozeilen, Liste aller Programme, Fenstertitel, Bildschirminhalte, Tastatureingaben
- Browserverlauf, E-Mails, Netzwerkverbindungen und Gegenstellen, WLAN-Namen
- Standort / GPS
- Windows-Sicherheitsprotokoll (enthält Anmeldenamen), Benutzer-Journale unter Linux
- Rechnername (nur bei ausdrücklicher lokaler Einstellung `send_hostname`)
- Wechseldatenträger (Laufwerksnamen könnten persönlich sein)

## 3. Bereinigung von Log-Texten (auf dem Gerät)

Jeder Text durchläuft vor dem Versand eine Filterkette (`internal/privacy`). Ersetzt werden:

| Kategorie | Beispiel vorher | nachher |
|---|---|---|
| Namen nach Anrede/Rolle | „Tour für Frau Müller“, „Patient Hans Meier“ | „Frau [NAME]“, „Patient [NAME]“ |
| Lokale Benutzerkonten und deren Klarnamen | „anna.schmidt“, „Anna Schmidt“ | [USER], [NAME] |
| Rechnername und seine Bestandteile | „PC-MUELLER“ | [HOST] |
| Domänenkonten | „PFLEGE\\amueller“ | [KONTO] |
| E-Mail-Adressen | anna@beispiel.de | [EMAIL] |
| Telefonnummern | 0171/1234567, +49 30 … | [TEL] |
| IBAN | DE89 3704 … | [IBAN] |
| Krankenversichertennummer | A123456789 | [KVNR] |
| Sozialversicherungsnummer | 12010141M123 | [SVNR] |
| Lange Nummern (Kunden-, Karten-, Steuer-ID) | ≥ 9 Ziffern | [NUMMER] |
| Datumsangaben (Geburtsdaten) | 01.02.1941 | [DATUM] |
| Adressen, PLZ + Ort | Hauptstraße 12, 12345 Musterdorf | [ADRESSE], [PLZ ORT] |
| GPS-Koordinaten | 52.5200, 13.4049 | [GPS] |
| IP- und MAC-Adressen | 192.168.1.20 | [IP], [MAC] |
| Benutzerpfade | C:\\Users\\anna\\…, /home/bauer/… | …\\[USER]\\… |
| Dokument-/Bilddateinamen | Pflegeplan_Krause.docx | [DATEI].docx |
| Ordner wie Patienten/Klienten/Kunden | …\\Klienten\\Becker | …\\Klienten\\[ENTFERNT] |
| Netzwerkpfade | \\\\NAS\\Pflege\\… | [NETZWERKPFAD] |
| Passwörter, Tokens | password=geheim | password=[ENTFERNT] |
| URL-Pfade und Parameter | https://x.de/patients/4711?name=… | https://x.de/[…] |
| Schlüssel-Wert-Paare mit Personenbezug | user=…, name=…, geburtsdatum=… | …=[ENTFERNT] |
| Kundenspezifische Begriffe | z.B. Hofname | [ENTFERNT] |

Zusätzlich: Steuerzeichen werden entfernt, Texte auf 400 Zeichen gekürzt. Die Filterung ist durch automatisierte Tests abgesichert (`go test ./internal/privacy`).

**Grenze der Mustererkennung:** Ein Name ohne erkennbaren Kontext (z.B. „Meier gespeichert“) kann nicht sicher erkannt werden. Deshalb gilt:

- **Software-Logs** (Pflege- oder Hofsoftware) werden standardmäßig im Modus **„metadata“** übertragen: kein Text, nur Zeit, Quelle, Stufe und ein Fingerabdruck. Der Fingerabdruck ist ein gekürzter SHA-256-Hash über den normalisierten Text mit einem gerätespezifischen geheimen Salz, das das Gerät nie verlässt – er erlaubt das Erkennen gleichartiger Fehler, aber keinen Rückschluss auf den Inhalt.
- Der Modus „redact“ (bereinigter Text) sollte für Software-Logs nur nach Prüfung der Log-Inhalte (`gw-agent preview`) aktiviert werden.
- Über `deny_words`/`deny_patterns` kann je Kunde nachgeschärft werden.

## 4. Zweite Verteidigungslinie auf dem Server

- Unbekannte Felder werden verworfen, Längen und Mengen begrenzt.
- Alle Texte und Bezeichnungen werden auf dem Server erneut durch denselben Filter geleitet.
- Der Server protokolliert keine Zugriffe und speichert keine IP-Adressen (auch der vorgeschaltete Caddy schreibt kein Zugriffslog). Für den Schutz gegen Passwort-Raten wird nur ein Hash der IP kurzzeitig im Arbeitsspeicher gehalten.

## 5. Keine Fernsteuerung

Der Server kann dem Agenten nur mitteilen: Meldeintervall und Namen von Programmen/Diensten, deren Laufzustand geprüft werden soll. Er kann **keine** Befehle ausführen lassen, **keine** Dateien anfordern, **keine** weiteren Log-Dateien hinzufügen und **keine** Datenschutzeinstellungen ändern. Ein kompromittierter Server kann also keine zusätzlichen Daten vom Kundengerät abziehen.

## 6. Transparenz für den Kunden

- `gw-agent preview` erzeugt eine vollständige Meldung und zeigt sie an, **ohne** sie zu senden.
- Nach jedem Versand liegt die Meldung lesbar unter `…/data/letzte-meldung.json`.
- Die Konfiguration (`agent.json`) ist eine lesbare Textdatei, alle Datenschutzoptionen sind dort sichtbar.
- Der Agent ist Open Source im Sinne von „einsehbar“: Der Quellcode kann dem Kunden/DSB zur Prüfung bereitgestellt werden.

## 7. Sicherheit

- Übertragung ausschließlich per HTTPS (TLS ≥ 1.2, Let's-Encrypt-Zertifikat); unverschlüsseltes HTTP wird vom Agenten abgelehnt (Ausnahme: localhost für Tests).
- Jedes Gerät hat einen eigenen 256-Bit-Schlüssel; der Server speichert nur dessen SHA-256-Hash. Geräte können einzeln deaktiviert oder gelöscht werden.
- Registrierungsschlüssel je Kunde, jederzeit erneuerbar.
- Agent-Konfiguration nur für SYSTEM/Administratoren bzw. root lesbar.
- Installationsskripte prüfen die SHA-256-Prüfsumme des Agenten.
- Weboberfläche: bcrypt-Passwörter, HttpOnly/Secure/SameSite-Strict-Cookies, CSRF-Schutz, strikte Content-Security-Policy, keine externen Ressourcen, Schutz gegen Passwort-Raten.

## 8. Empfehlungen für den Betrieb

1. Server bei einem Anbieter mit Rechenzentrum in Deutschland/EU betreiben, AVV mit dem Hoster abschließen.
2. AVV mit jedem Kunden abschließen; dieses Dokument als Anlage „TOM“ verwenden.
3. Geräte in der Oberfläche nach Funktion benennen, nicht nach Mitarbeitenden.
4. Vor Aktivierung von „redact“ für Software-Logs die Vorschau gemeinsam mit dem Kunden prüfen.
5. Benachrichtigungen über EU-Dienste (eigener SMTP-Server, selbst gehostetes ntfy) versenden.
6. Regelmäßige Backups der Server-Datenbank, verschlüsselt ablegen.
