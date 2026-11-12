# Haus Rosenstock bei STRATO mit WordPress einrichten

Du brauchst zwei Dateien aus diesem Ordner:

- `haus-rosenstock-theme.zip`: die Webseite (Design, Texte, Bilder)
- `haus-rosenstock-privat.zip`: Plugin, das die Seite für Suchmaschinen unsichtbar macht

Dauer: etwa 15 Minuten.

---

## 1. Unauffällige Adresse anlegen (STRATO-Kundenlogin)

Damit niemand die Seite zufällig findet, sollte sie unter einer **schwer erratbaren Subdomain** laufen, die du nirgends verlinkst. Zum Beispiel:

`gaeste-7k3m.deine-domain.de`

1. Melde dich unter strato.de im **Kunden-Login** an.
2. Öffne **Domains → Domainverwaltung**, wähle deine Domain und lege eine **Subdomain** an.
3. Aktiviere für die Subdomain **SSL** (bei STRATO meist unter „SSL-Verwaltung“). Die Adresse muss mit `https://` funktionieren.

> Eine eigene Domain wie `haus-rosenstock.de` geht auch. Aber Domainnamen sind öffentlich (z. B. in Zertifikats-Verzeichnissen), und wer den Namen errät, findet die Seite. Eine zufällige Subdomain ist schwerer zu finden.

## 2. WordPress installieren

1. Im STRATO-Kundenlogin: **WordPress → WordPress installieren** (je nach Paket auch „AppWizard“ oder „Managed WordPress“).
2. Wähle als Adresse die Subdomain aus Schritt 1.
3. Sprache: **Deutsch**. Seitentitel: **Haus Rosenstock**.
4. Wenn STRATO fragt, ob Suchmaschinen die Seite indexieren dürfen: **Nein**.
5. Melde dich danach im WordPress-Dashboard an (`https://deine-subdomain/wp-admin`).

## 3. Plugin „Unsichtbar für Suchmaschinen“ installieren

1. **Plugins → Neues Plugin hinzufügen → Plugin hochladen**
2. `haus-rosenstock-privat.zip` auswählen → **Jetzt installieren** → **Aktivieren**

Ab dann gilt für jede Seite:

- „noindex, nofollow“, sowohl im Seitencode als auch im HTTP-Header
- keine Sitemap und keine RSS-Feeds
- Benutzernamen werden nicht verraten
- Die WordPress-Einstellung „Suchmaschinen davon abhalten“ ist dauerhaft eingeschaltet.

## 4. Theme installieren

1. **Design → Themes → Theme hinzufügen → Theme hochladen**
2. `haus-rosenstock-theme.zip` auswählen → **Jetzt installieren** → **Aktivieren**

Beim Aktivieren passiert automatisch Folgendes:

- Die Seite **„Startseite“** wird mit dem kompletten Inhalt angelegt und als Startseite eingestellt.
- Die Seiten **„Impressum“** und **„Datenschutzerklärung“** werden mit einem Platzhaltertext angelegt.

Schon vorhandene Seiten mit diesen Namen werden nicht überschrieben.

Rufe danach deine Adresse auf. Die Webseite sollte jetzt erscheinen.

## 5. Inhalte anpassen

**Texte, Preise und Telefonnummer:** **Seiten → Startseite → Bearbeiten**. Einfach in den Text klicken und ändern, dann **Aktualisieren**.

**Fotos austauschen:** Ein Bild in der Startseite anklicken → in der Werkzeugleiste **Ersetzen** → eigenes Foto hochladen. Die mitgelieferten Bilder sind gezeichnete Platzhalter.

**Menü und Fußzeile:** **Design → Editor → Muster → Kopfzeile / Fußzeile**.

**Impressum und Datenschutz:** **Seiten → Impressum** bzw. **Datenschutzerklärung**. Bitte vollständig ausfüllen. Bei Vermietung gegen Geld brauchst du beides, auch wenn die Seite versteckt ist.

## 6. Prüfen, ob die Seite unsichtbar ist

1. Öffne `https://deine-subdomain/robots.txt`. Dort sollte stehen:
   ```
   User-agent: *
   Disallow:
   ```
   Das ist Absicht: Suchmaschinen dürfen die Seite abrufen, sehen dabei aber das „noindex“ und nehmen sie deshalb nicht auf. Ein reines Sperren per robots.txt kann dazu führen, dass die Adresse trotzdem in Google auftaucht.
2. Rechtsklick auf die Seite → **Seitenquelltext anzeigen** → nach `noindex` suchen. Es muss gefunden werden.
3. `https://deine-subdomain/wp-sitemap.xml` muss „nicht gefunden“ melden.

## Wichtig im Alltag

- **Verlinke die Adresse nirgends öffentlich**: nicht auf Social Media, nicht in Online-Inseraten, nicht auf anderen Webseiten. Schick sie Gästen direkt per E-Mail oder Messenger.
- Schalte das Plugin nicht ab. Ohne das Plugin gilt nur die WordPress-Einstellung unter **Einstellungen → Lesen**.
- Halte WordPress, Theme und Plugins aktuell (Dashboard → Aktualisierungen).

## Optional: zusätzlich mit Passwort schützen

Wenn die Seite wirklich **nur** für eingeladene Gäste sein soll, kannst du bei STRATO einen **Verzeichnisschutz** einrichten: Kunden-Login → **Hosting → Verzeichnisschutz**. Dann fragt der Browser vor dem Öffnen nach Benutzername und Passwort, die du den Gästen mitteilst. Den Ordner `wp-admin` kannst du dabei ungeschützt lassen, WordPress hat dort seinen eigenen Login.
