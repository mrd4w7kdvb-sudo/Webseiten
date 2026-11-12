package privacy

import (
	"strings"
	"testing"
)

func TestTextRemovesPersonalData(t *testing.T) {
	s := MustNew(Options{
		Literals:  map[string]string{"anna.schmidt": "[USER]", "PC-MUELLER": "[HOST]"},
		DenyWords: []string{"Sonnenhof"},
	})
	cases := []struct {
		in        string
		forbidden []string
	}{
		{"Mail an pflege@beispiel-dienst.de fehlgeschlagen", []string{"pflege@", "beispiel-dienst"}},
		{"Tour gespeichert für Frau Müller und Herrn Hans Meier", []string{"Müller", "Hans", "Meier"}},
		{"Patientin Erika Mustermann geb. 01.02.1941 angelegt", []string{"Erika", "Mustermann", "1941"}},
		{"Datei C:\\Users\\anna.schmidt\\Documents\\Pflegeplan_Krause.docx gesperrt", []string{"anna.schmidt", "Krause"}},
		{"open /home/bauer/hof/kuehe.csv failed", []string{"bauer", "kuehe"}},
		{"Versichertennummer A123456789 ungültig", []string{"A123456789"}},
		{"SV 12010141M123 abgelehnt", []string{"12010141M123"}},
		{"IBAN DE89 3704 0044 0532 0130 00 abgelehnt", []string{"3704", "0532"}},
		{"Rückruf unter 0171/1234567 oder +49 30 1234567", []string{"1234567"}},
		{"connect to 192.168.1.20 from fe80::1c2b:3cff:fe4d:5e6f", []string{"192.168", "fe80"}},
		{"session opened for user anna.schmidt(uid=1000) by (uid=0)", []string{"anna.schmidt"}},
		{"Anmeldung von maxm fehlgeschlagen", []string{"maxm"}},
		{"user=maxm password=geheim123", []string{"maxm", "geheim123"}},
		{"Host PC-MUELLER nicht erreichbar", []string{"MUELLER"}},
		{"Lieferung an Sonnenhof bestätigt", []string{"Sonnenhof"}},
		{"Melkroboter Position 52.520008, 13.404954", []string{"52.520008", "13.404954"}},
		{"Adresse Hauptstraße 12 in 12345 Musterdorf", []string{"Hauptstraße", "Musterdorf"}},
		{"GET https://api.example.de/patients/4711?name=Krause", []string{"4711", "Krause", "patients"}},
		{"Benutzer DOMAIN\\amueller konnte nicht angemeldet werden", []string{"amueller"}},
		{"\\\\NAS01\\Pflege\\Klienten\\Becker gesperrt", []string{"Becker"}},
	}
	for _, c := range cases {
		out := s.Text(c.in)
		for _, f := range c.forbidden {
			if strings.Contains(out, f) {
				t.Errorf("Text(%q) = %q enthält noch %q", c.in, out, f)
			}
		}
	}
}

func TestTextKeepsTechnicalInfo(t *testing.T) {
	s := MustNew(Options{})
	cases := map[string][]string{
		"Der Dienst Druckwarteschlange wurde unerwartet beendet. Fehlercode 7034": {"Druckwarteschlange", "7034"},
		"disk /dev/sda1 I/O error, sector 2048":                                   {"/dev/sda1", "I/O error"},
		"Out of memory: Killed process 1234 (java)":                               {"Out of memory", "java"},
		"Backup job finished at 12:30:45 with 3 warnings":                         {"12:30:45", "3 warnings"},
	}
	for in, want := range cases {
		out := s.Text(in)
		for _, w := range want {
			if !strings.Contains(out, w) {
				t.Errorf("Text(%q) = %q, erwartet %q", in, out, w)
			}
		}
	}
}

func TestPlaceholdersNotReplacedAgain(t *testing.T) {
	s := MustNew(Options{Literals: map[string]string{"user": "[NAME]", "claude": "[USER]", "Name": "[NAME]"}})
	out := s.Text("/opt/claude-code und /home/claude/x")
	if strings.Contains(out, "[[") || strings.Contains(out, "claude") {
		t.Fatalf("Platzhalter beschädigt oder Konto nicht ersetzt: %q", out)
	}
}

func TestTruncateAndControl(t *testing.T) {
	s := MustNew(Options{MaxLen: 10})
	out := s.Text("abc\x00def\nghijklmnop")
	if len([]rune(out)) > 10 || strings.ContainsRune(out, 0) {
		t.Fatalf("unerwartet: %q", out)
	}
}

func TestFingerprintStable(t *testing.T) {
	a := Fingerprint("salz", "app", "Timeout nach 3000 ms bei Auftrag 17")
	b := Fingerprint("salz", "app", "Timeout nach 5000 ms bei Auftrag 99")
	c := Fingerprint("anders", "app", "Timeout nach 3000 ms bei Auftrag 17")
	if a != b {
		t.Fatal("gleiche Fehlerart sollte gleichen Fingerabdruck ergeben")
	}
	if a == c {
		t.Fatal("unterschiedliches Salz sollte anderen Fingerabdruck ergeben")
	}
}

func TestParseModeDefaultsSafe(t *testing.T) {
	if ParseMode("") != ModeMetadata || ParseMode("quatsch") != ModeMetadata {
		t.Fatal("unbekannter Modus muss metadata sein")
	}
	if ParseMode("Redact") != ModeRedact {
		t.Fatal("redact nicht erkannt")
	}
}
