# EmsQuartier – Buchungsportal einrichten (STRATO)

Das Portal ist eine PHP-Anwendung mit eingebauter Datenbank (SQLite). Es braucht **kein WordPress und keine MySQL-Datenbank**. Ein STRATO-Hosting-Paket mit PHP 8.1 oder neuer reicht.

Dauer: etwa 20 Minuten.

---

## 1. Adresse und PHP-Version (STRATO-Kundenlogin)

1. Melde dich unter strato.de im **Kunden-Login** an.
2. Lege unter **Domains → Domainverwaltung** eine (Sub-)Domain für das Portal an, z. B. `buchen.deine-domain.de`, und lass sie auf einen **neuen, leeren Ordner** zeigen, z. B. `/emsquartier`.
3. Aktiviere **SSL** für die Adresse. Das Portal muss mit `https://` laufen.
4. Stelle unter **Hosting → PHP-Version** mindestens **PHP 8.1** ein (8.3 empfohlen).

## 2. Dateien hochladen

1. Lade den **Inhalt** des Ordners `emsland-portal` per SFTP (z. B. mit FileZilla) oder mit dem STRATO-Dateimanager in den Ordner aus Schritt 2 hoch.
   Wichtig: Auch die versteckten Dateien `.htaccess` (im Hauptordner und in `data`, `inc`, `uploads`) müssen mit hochgeladen werden.
2. Die Ordner `data` und `uploads` müssen vom Webserver beschreibbar sein. Bei STRATO ist das normalerweise schon so. Falls beim ersten Aufruf ein Fehler kommt: Ordnerrechte auf `755` setzen.

## 3. Admin-Zugang einrichten

1. Rufe `https://deine-adresse/admin/` auf.
2. Die Seite fragt nach einem **Einrichtungscode**. Der Code wurde gerade automatisch in die Datei `data/einrichtungscode.txt` geschrieben. Öffne diese Datei per SFTP oder Dateimanager und kopiere den Code.
   (So kann niemand anderes, der die Adresse zufällig findet, den Admin-Zugang anlegen.)
3. Trage Code, Namen, E-Mail und ein Passwort mit mindestens 10 Zeichen ein.
4. Danach wird die Code-Datei automatisch gelöscht. Ab jetzt meldest du dich unter `/admin/` mit E-Mail und Passwort an.

## 4. Erste Schritte im Admin-Bereich

1. **Einstellungen**
   - Kontakt-E-Mail: am besten eine Adresse deiner Domain (z. B. `info@deine-domain.de`). Sie wird als Absender für E-Mails genutzt. Mit fremden Adressen (Gmail, GMX) kommen E-Mails oft nicht an.
   - Benachrichtigungen an: Hierhin gehen neue Anfragen.
   - Rechnungsangaben: Name/Firma, Anschrift, Steuernummer, Bankverbindung, Kleinunternehmer ja/nein.
2. **Seiten & Texte**: Impressum, Datenschutzerklärung und Buchungsbedingungen vollständig ausfüllen. Ohne diese Angaben darf das Portal nicht öffentlich betrieben werden.
3. **Unterkünfte**: Die sechs Beispiel-Unterkünfte mit Platzhalterbildern entweder an deine echten Unterkünfte anpassen oder löschen bzw. auf „offline“ stellen. Eigene Fotos lädst du in jeder Unterkunft unter „Fotos“ hoch.
4. **Vor Suchmaschinen verbergen** ist zu Beginn eingeschaltet. Schalte es unter **Einstellungen** aus, sobald das Portal öffentlich gefunden werden soll.

## 5. Alltag

| Was | Wo |
| --- | --- |
| Neue Buchungsanfrage bestätigen oder ablehnen | **Buchungen** → Anfrage öffnen → „Bestätigen & Gast informieren“ |
| Telefonische Buchung eintragen | **Übersicht** → „+ Buchung eintragen“ |
| Rechnung erstellen | Buchung öffnen → „Rechnung erstellen“ → „Drucken / als PDF speichern“ |
| Zeitraum sperren (Eigennutzung, Renovierung) | **Unterkünfte** → Unterkunft → „Kalender & Sperrzeiten“ |
| Kalender mit Airbnb/Booking abgleichen | Unterkunft → Export-Adresse dort eintragen; deren iCal-Adresse unter „iCal-Import“ eintragen und „synchronisieren“ |
| Firmen- und Gastgeber-Anfragen | **Firmen & Gastgeber** |
| Buchungen für Steuerberatung | **Buchungen** → „Als CSV exportieren“ |
| Weiteren Admin anlegen, Passwort ändern | **Einstellungen** |
| Firmenkonto freischalten | **Firmenkonten** |
| Monatsabrechnung für Firmen | **Sammelrechnungen** |
| Gastgeber einladen | **Gastgeber-Zugänge** |

Nur **bestätigte** Buchungen und Sperrzeiten blockieren den Kalender. Offene Anfragen blockieren nichts. Beim Bestätigen prüft das System, ob sich der Zeitraum mit einer anderen bestätigten Buchung überschneidet.

## 6. Business-Modul (Phase 2)

**Update einer bestehenden Installation:** Einfach alle Dateien erneut hochladen und dabei die Ordner `data/` und `uploads/` **nicht** überschreiben. Die Datenbank wird beim nächsten Aufruf automatisch erweitert; vorhandene Unterkünfte und Buchungen bleiben erhalten. Vorher sicherheitshalber `data/portal.sqlite` herunterladen.

### Firmenkonten
- Firmen beantragen ein Konto unter **Anmelden → Firmenkonto beantragen** (`/konto/registrieren.php`). Du bekommst eine E-Mail und siehst es im Admin unter **Firmenkonten** mit Status „Beantragt“.
- Prüfen und auf **Freigeschaltet** stellen. Ab dann werden bestätigte Buchungen dieser Firma per **Sammelrechnung** abgerechnet. Zahlungsziel und Preisobergrenze legst du dort ebenfalls fest.
- Firmen laden Kolleginnen und Kollegen selbst ein, legen Kostenstellen an und buchen für Mitarbeitende („Name des Gastes“). Gast und buchende Person bekommen beide die E-Mails.
- **Gesperrt** verhindert die Anmeldung.

### Wochenpendler-Serien
- Beim Buchen „beruflich“ → **Jede Woche wiederholen**: An- und Abreise der ersten Woche (z. B. Mo–Fr) plus letztes Abreisedatum. Es entsteht je Woche eine eigene Buchung; die Wochenenden bleiben frei.
- Sind einzelne Wochen belegt, zeigt das Formular sie an; der Gast kann die freien Wochen trotzdem anfragen.
- Im Admin bestätigst du die **ganze Serie** mit einem Klick (eine E-Mail mit allen Terminen).

### Sammelrechnungen
- **Sammelrechnungen** → Monat wählen → Vorschau → „Sammelrechnung erstellen“ (oder alle Firmen auf einmal).
- Aufenthalte über mehrere Monate werden **anteilig nach Nächten** berechnet; die letzte Teilrechnung gleicht Cent-Rundungen aus. Bereits abgerechnete Nächte erscheinen nicht noch einmal.
- „Per E-Mail ankündigen“ schickt der Firma den Link ins Kundenkonto, wo sie die Rechnung als PDF speichern kann. Zahlungseingang mit „Bezahlt“ markieren; überfällige Rechnungen erscheinen in der Übersicht.
- Eine Rechnung nur löschen, wenn sie noch nicht verschickt wurde. Sonst bitte mit deiner Steuerberatung eine Storno-/Gutschrift klären.

### Gastgeber-Zugänge
- **Gastgeber-Zugänge** → Name, E-Mail, Unterkünfte → „Anlegen & Einladung senden“. Der Gastgeber legt sein Passwort über den Link selbst fest. Kommt die E-Mail nicht an, steht der Link auch in der Bestätigungsmeldung.
- Gastgeber sehen nur ihre eigenen Unterkünfte und Buchungen. Sie können Texte, Preise, Ausstattung, Fotos und Sperrzeiten pflegen und Anfragen bestätigen oder ablehnen. Das Bestätigen kannst du unter **Einstellungen** abschalten.
- Bei jeder Änderung durch einen Gastgeber bekommst du eine E-Mail.

### Online-Bezahlung
Ist bewusst nicht eingebaut. Dafür braucht es einen Vertrag mit einem Zahlungsanbieter (z. B. Stripe oder PayPal). Danach lässt sich die Anbindung ergänzen.

## 7. Datensicherung

Alle Daten (Unterkünfte, Buchungen, Einstellungen) stehen in **einer Datei**: `data/portal.sqlite`. Hochgeladene Fotos liegen in `uploads/`.
Lade beide regelmäßig per SFTP herunter, z. B. einmal pro Woche. Zum Wiederherstellen einfach zurück hochladen.

## 8. Wichtige Hinweise

- **Preise**: Alle Preise sind Endpreise inklusive Mehrwertsteuer. Ab 28 Nächten gilt der Monatspreis anteilig (1 Monat = 30 Nächte), sofern „Wohnen auf Zeit“ aktiviert ist.
- **Rechnungen** sind eine Vorlage. Lass Steuersatz und Pflichtangaben von deiner Steuerberatung prüfen, besonders bei Aufenthalten über 6 Monate.
- **Entfernungen** zum Arbeitsort werden per Luftlinie berechnet, nicht als Fahrstrecke.
- Die Seite lädt **keine** Schriften, Karten oder Skripte von fremden Servern und setzt nur ein technisch notwendiges Sitzungs-Cookie (für Formulare und Admin-Login).
- Nach 5 falschen Anmeldeversuchen ist der Login für 15 Minuten gesperrt.

## Lokal ausprobieren (optional, für Technikinteressierte)

```
cd emsland-portal
php -S 127.0.0.1:8080
```

Dann `http://127.0.0.1:8080` im Browser öffnen. Die Datenbank mit Beispieldaten wird beim ersten Aufruf automatisch angelegt.
