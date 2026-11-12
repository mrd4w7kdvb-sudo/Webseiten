// Package privacy entfernt personenbezogene Daten aus Log-Texten, bevor sie
// das Gerät des Kunden verlassen (Agent) und – als zweite Sicherung – bevor
// sie auf dem Server gespeichert werden.
//
// Grundprinzip ist Datenminimierung (Art. 5 Abs. 1 lit. c DSGVO):
//  1. Es werden nur fest definierte Felder übertragen (siehe package proto).
//  2. Freitext wird über eine Kette von Mustern bereinigt (E-Mail, Telefon,
//     IBAN, Versichertennummern, Namen nach Anreden, Benutzerpfade, ...).
//  3. Bekannte lokale Benutzerkonten und der Rechnername werden als feste
//     Begriffe ersetzt.
//  4. Im Modus "metadata" wird gar kein Text übertragen, nur Quelle, Level
//     und ein nicht umkehrbarer Fingerabdruck.
package privacy

import (
	"crypto/sha256"
	"encoding/hex"
	"regexp"
	"sort"
	"strings"
	"unicode"
	"unicode/utf8"

	"geraetewaechter/internal/proto"
)

// Mode legt fest, wie viel Text eines Log-Eintrags übertragen wird.
type Mode string

const (
	// ModeRedact überträgt den bereinigten Text.
	ModeRedact Mode = "redact"
	// ModeMetadata überträgt keinen Text, nur Quelle/Level/Fingerabdruck.
	ModeMetadata Mode = "metadata"
)

// ParseMode liefert den Modus; Unbekanntes wird sicherheitshalber zu metadata.
func ParseMode(s string) Mode {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "redact":
		return ModeRedact
	default:
		return ModeMetadata
	}
}

type rule struct {
	re   *regexp.Regexp
	repl string
	fn   func(string) string
}

// Reihenfolge ist relevant: spezifische Muster vor allgemeinen.
var baseRules = []rule{
	// Zugangsdaten in URLs / Key-Value
	{re: regexp.MustCompile(`(?i)\b(password|passwort|kennwort|pwd|pass|secret|token|apikey|api_key|auth|authorization|bearer)\s*[=:]\s*("[^"]*"|'[^']*'|\S+)`), repl: `$1=[ENTFERNT]`},
	{re: regexp.MustCompile(`(?i)\bbearer\s+[A-Za-z0-9._~+/\-]+=*`), repl: `Bearer [ENTFERNT]`},
	// E-Mail-Adressen
	{re: regexp.MustCompile(`[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}`), repl: `[EMAIL]`},
	// URLs: nur Schema und Host behalten, Pfad/Query (können IDs/Namen tragen) entfernen
	{re: regexp.MustCompile(`(?i)\b(https?|ftp|smb|ldap)://([^/\s"'<>@]+@)?([^/\s"'<>:]+)(:\d+)?[^\s"'<>]*`), fn: func(m string) string {
		sub := urlRe.FindStringSubmatch(m)
		if sub == nil {
			return "[URL]"
		}
		return strings.ToLower(sub[1]) + "://" + sub[3] + "/[…]"
	}},
	// Benutzerverzeichnisse
	{re: regexp.MustCompile(`(?i)(/home/|/Users/|/var/mail/|/var/spool/mail/|/run/user/|/media/|/run/media/)[^/\s"':;,)]+`), repl: `${1}[USER]`},
	{re: regexp.MustCompile(`(?i)(\\(?:Users|Benutzer|Documents and Settings|Dokumente und Einstellungen)\\)[^\\\s"':;,)]+`), repl: `${1}[USER]`},
	// UNC-Pfade (\\server\freigabe\...)
	{re: regexp.MustCompile(`\\\\[^\\\s"']+\\[^\s"']*`), repl: `[NETZWERKPFAD]`},
	// Dokument-/Bilddateinamen (können Patienten-/Kundennamen enthalten)
	{re: regexp.MustCompile(`(?i)[^\\/\s"'<>|:*?]+\.(pdf|docx?|xlsx?|xlsm|odt|ods|rtf|txt|csv|jpe?g|png|heic|tiff?|bmp|msg|eml|vcf|zip|7z)\b`), repl: `[DATEI].$1`},
	// Verzeichnisse mit sprechenden Namen
	{re: regexp.MustCompile(`(?i)([\\/](?:patienten|patient|klienten|klient|bewohner|kunden|kunde|mitarbeiter|personal|versicherte|tiere|halter|betriebe)[\\/])[^\\/\s"']+`), repl: `${1}[ENTFERNT]`},
	// IBAN
	{re: regexp.MustCompile(`\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){3,7}(?:\s?[A-Z0-9]{1,4})?\b`), repl: `[IBAN]`},
	// Krankenversichertennummer (eGK): Buchstabe + 9 Ziffern
	{re: regexp.MustCompile(`\b[A-Z]\d{9}\b`), repl: `[KVNR]`},
	// Sozialversicherungs-/Rentenversicherungsnummer: 8 Ziffern, Buchstabe, 3 Ziffern
	{re: regexp.MustCompile(`\b\d{2}\s?\d{6}\s?[A-Z]\s?\d{3}\b`), repl: `[SVNR]`},
	// Steuer-ID (11 Ziffern) und sonstige lange Ziffernfolgen (Kartennr., Kundennr.)
	{re: regexp.MustCompile(`\b(?:\d[ \-]?){12,19}\d\b`), repl: `[NUMMER]`},
	{re: regexp.MustCompile(`\b\d{9,}\b`), repl: `[NUMMER]`},
	// GPS-Koordinaten (Hofstelle, Feld, Wohnadresse von Pflegekunden)
	{re: regexp.MustCompile(`-?\b\d{1,3}\.\d{4,}\s*[,; ]\s*-?\d{1,3}\.\d{4,}\b`), repl: `[GPS]`},
	{re: regexp.MustCompile(`(?i)\b(lat|latitude|lon|lng|longitude|breite|länge)\s*[=:]\s*-?\d+(\.\d+)?`), repl: `$1=[GPS]`},
	// MAC-Adressen
	{re: regexp.MustCompile(`(?i)\b(?:[0-9a-f]{2}[:\-]){5}[0-9a-f]{2}\b`), repl: `[MAC]`},
	// IPv4 / IPv6
	{re: regexp.MustCompile(`\b(?:25[0-5]|2[0-4]\d|1?\d?\d)(?:\.(?:25[0-5]|2[0-4]\d|1?\d?\d)){3}\b`), repl: `[IP]`},
	{re: regexp.MustCompile(`(?i)\b(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}\b`), repl: `[IP]`},
	{re: regexp.MustCompile(`(?i)\b(?:[0-9a-f]{1,4}:){1,6}:(?:[0-9a-f]{1,4}:?){0,6}[0-9a-f]{0,4}\b`), repl: `[IP]`},
	// Telefonnummern
	{re: regexp.MustCompile(`(?:\+|\b00)\d{1,3}[\s/\-]?\(?\d{1,5}\)?(?:[\s/\-]?\d{2,}){1,4}`), repl: `[TEL]`},
	{re: regexp.MustCompile(`\b0\d{2,5}\s?[/\-]\s?\d{3,}(?:[\s\-]?\d{2,})*\b`), repl: `[TEL]`},
	// Datumsangaben im deutschen Format (z.B. Geburtsdaten)
	{re: regexp.MustCompile(`\b\d{1,2}\.\d{1,2}\.(?:19|20)\d{2}\b`), repl: `[DATUM]`},
	// Kontoangaben in der Form DOMÄNE\benutzer
	{re: regexp.MustCompile(`(^|[\s'"(=])[A-Za-z0-9\-]{2,15}\\[A-Za-z0-9._\-]+`), repl: `${1}[KONTO]`},
	// Schlüssel/Wert-Paare mit Personenbezug
	{re: regexp.MustCompile(`(?i)\b(user|username|user_name|benutzer|benutzername|anmeldename|login|logon|account|accountname|kontoname|owner|ruser|luser|sender|recipient|empfänger|absender|name|vorname|nachname|firstname|lastname|fullname|display_?name|patient|patientin|klient|klientin|bewohner|bewohnerin|kunde|kundin|versicherter|halter|betriebsinhaber|adresse|address|street|strasse|straße|plz|ort|telefon|phone|mobil|geburtsdatum|birthdate|dob)\s*[=:]\s*("[^"]*"|'[^']*'|[^\s,;]+)`), repl: `$1=[ENTFERNT]`},
	{re: regexp.MustCompile(`(?i)\b(for user|by user|user|benutzer|benutzers|account|konto|for|by)\s+['"]?([A-Za-z][A-Za-z0-9._\-]*)['"]?\s*\(uid=\d+\)`), repl: `$1 [USER](uid)`},
	{re: regexp.MustCompile(`(?i)\b(for user|by user|benutzer|benutzers|anmeldung von|login of|logon of)\s+['"]?[^\s'"(]+['"]?`), repl: `$1 [USER]`},
	// Personennamen nach Anrede/Rolle ("Frau Müller", "Patient Hans Meier")
	{re: regexp.MustCompile(`\b(Herrn?|Frau|Hr\.|Fr\.|Dr\.|Prof\.|Patient(?:in)?|Klient(?:in)?|Bewohner(?:in)?|Kund(?:e|in)|Pflegebedürftige[rn]?|Versicherte[rn]?|Mitarbeiter(?:in)?|Pflegekraft|Landwirt(?:in)?|Mr\.?|Mrs\.?|Ms\.?)\s+(?:[A-ZÄÖÜ][\p{L}'\-]*\.?\s*,?\s*){1,3}`), repl: `$1 [NAME] `},
	// Straßenadressen ("Hauptstraße 12", "Am Feldweg 3a")
	{re: regexp.MustCompile(`\b[\p{L}\-]+(?:straße|strasse|str\.|weg|gasse|allee|platz|ring|damm|chaussee)\s+\d+[a-zA-Z]?\b`), repl: `[ADRESSE]`},
	// Deutsche Postleitzahl mit Ort
	{re: regexp.MustCompile(`\b\d{5}\s+[A-ZÄÖÜ][\p{L}\-]+(?:\s[A-ZÄÖÜ][\p{L}\-]+)?`), repl: `[PLZ ORT]`},
}

var urlRe = regexp.MustCompile(`(?i)^(https?|ftp|smb|ldap)://([^/\s"'<>@]+@)?([^/\s"'<>:]+)`)

// Systemkonten, deren Nennung unkritisch ist und nicht ersetzt wird.
var harmlessAccounts = map[string]bool{
	"root": true, "system": true, "admin": true, "administrator": true,
	"guest": true, "gast": true, "public": true, "öffentlich": true,
	"default": true, "default user": true, "all users": true, "shared": true,
	"daemon": true, "nobody": true, "localservice": true, "networkservice": true,
	"lost+found": true, "desktop.ini": true, "defaultapppool": true,
}

// placeholderWords sind Bestandteile der Ersatztexte und werden nie als Literal ersetzt.
var placeholderWords = map[string]bool{
	"user": true, "name": true, "host": true, "entfernt": true, "email": true, "tel": true, "iban": true,
	"kvnr": true, "svnr": true, "nummer": true, "gps": true, "mac": true, "datum": true, "konto": true,
	"adresse": true, "plz": true, "ort": true, "datei": true, "url": true, "netzwerkpfad": true,
}

// Options steuert einen Sanitizer.
type Options struct {
	// Literals sind Begriffe, die immer ersetzt werden (z.B. lokale
	// Benutzerkonten, Rechnername). Schlüssel = Begriff, Wert = Ersatz.
	Literals map[string]string
	// DenyWords werden immer durch [ENTFERNT] ersetzt (vom Kunden pflegbar).
	DenyWords []string
	// ExtraPatterns sind zusätzliche reguläre Ausdrücke, die zu [ENTFERNT] werden.
	ExtraPatterns []string
	// MaxLen begrenzt die Länge (0 = proto.MaxMessageLen).
	MaxLen int
}

// Sanitizer bereinigt Texte.
type Sanitizer struct {
	literal *regexp.Regexp
	litRepl map[string]string
	extra   []*regexp.Regexp
	maxLen  int
}

// New erzeugt einen Sanitizer. Ungültige Zusatzmuster führen zu einem Fehler.
func New(o Options) (*Sanitizer, error) {
	s := &Sanitizer{litRepl: map[string]string{}, maxLen: o.MaxLen}
	if s.maxLen <= 0 {
		s.maxLen = proto.MaxMessageLen
	}
	terms := []string{}
	add := func(term, repl string) {
		t := strings.TrimSpace(term)
		if utf8.RuneCountInString(t) < 3 || harmlessAccounts[strings.ToLower(t)] || placeholderWords[strings.ToLower(t)] {
			return
		}
		k := strings.ToLower(t)
		if _, ok := s.litRepl[k]; ok {
			return
		}
		s.litRepl[k] = repl
		terms = append(terms, regexp.QuoteMeta(t))
	}
	for t, r := range o.Literals {
		add(t, r)
	}
	for _, w := range o.DenyWords {
		add(w, "[ENTFERNT]")
	}
	if len(terms) > 0 {
		// längere Begriffe zuerst, damit "anna.mueller" vor "anna" greift
		sort.Slice(terms, func(i, j int) bool { return len(terms[i]) > len(terms[j]) })
		// "[" und "]" zählen nicht als Wortgrenze, damit Platzhalter wie [USER] unangetastet bleiben
		s.literal = regexp.MustCompile(`(?i)(^|[^\p{L}\p{N}_\[])(` + strings.Join(terms, "|") + `)($|[^\p{L}\p{N}_\]])`)
	}
	for _, p := range o.ExtraPatterns {
		if strings.TrimSpace(p) == "" {
			continue
		}
		re, err := regexp.Compile(p)
		if err != nil {
			return nil, err
		}
		s.extra = append(s.extra, re)
	}
	return s, nil
}

// MustNew ist New ohne Fehlerrückgabe (für feste Konfigurationen).
func MustNew(o Options) *Sanitizer {
	s, err := New(o)
	if err != nil {
		panic(err)
	}
	return s
}

// Text bereinigt einen Freitext vollständig.
func (s *Sanitizer) Text(in string) string {
	out := cleanControl(in)
	// Literale zuerst (bevor andere Muster die Wortgrenzen verändern)
	out = s.replaceLiterals(out)
	for _, re := range s.extra {
		out = re.ReplaceAllString(out, "[ENTFERNT]")
	}
	for _, r := range baseRules {
		if r.fn != nil {
			out = r.re.ReplaceAllStringFunc(out, r.fn)
		} else {
			out = r.re.ReplaceAllString(out, r.repl)
		}
	}
	// zweiter Durchlauf für Literale (z.B. in zuvor zerlegten Pfaden)
	out = s.replaceLiterals(out)
	out = strings.Join(strings.Fields(out), " ")
	return truncate(out, s.maxLen)
}

// Label bereinigt kurze Werte wie Quellen- oder Mount-Bezeichnungen.
func (s *Sanitizer) Label(in string) string {
	out := s.Text(in)
	return truncate(out, proto.MaxLabelValueLen)
}

func (s *Sanitizer) replaceLiterals(in string) string {
	if s.literal == nil {
		return in
	}
	// Mehrfach anwenden, da sich Treffer die Trennzeichen teilen können.
	for i := 0; i < 3; i++ {
		next := s.literal.ReplaceAllStringFunc(in, func(m string) string {
			sub := s.literal.FindStringSubmatch(m)
			repl := s.litRepl[strings.ToLower(sub[2])]
			if repl == "" {
				repl = "[ENTFERNT]"
			}
			return sub[1] + repl + sub[3]
		})
		if next == in {
			break
		}
		in = next
	}
	return in
}

var (
	digitsRe = regexp.MustCompile(`\d+`)
	hexRe    = regexp.MustCompile(`(?i)\b[0-9a-f]{8,}\b`)
)

// Fingerprint bildet einen nicht umkehrbaren Kurz-Hash über die normalisierte
// Nachricht (Zahlen/IDs vereinheitlicht). Gleiche Fehler ergeben gleiche
// Fingerabdrücke, ohne dass der Text übertragen werden muss. Das Salz ist
// gerätespezifisch und verlässt das Gerät nicht.
func Fingerprint(salt, source, msg string) string {
	n := strings.ToLower(msg)
	n = hexRe.ReplaceAllString(n, "h")
	n = digitsRe.ReplaceAllString(n, "0")
	n = strings.Join(strings.Fields(n), " ")
	sum := sha256.Sum256([]byte(salt + "\x00" + source + "\x00" + n))
	return hex.EncodeToString(sum[:8])
}

func cleanControl(s string) string {
	if !utf8.ValidString(s) {
		s = strings.ToValidUTF8(s, "?")
	}
	return strings.Map(func(r rune) rune {
		if r == '\n' || r == '\r' || r == '\t' {
			return ' '
		}
		if unicode.IsControl(r) {
			return -1
		}
		return r
	}, s)
}

func truncate(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	r := []rune(s)
	return string(r[:n-1]) + "…"
}
