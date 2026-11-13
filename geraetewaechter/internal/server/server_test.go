package server

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"net/http/cookiejar"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"geraetewaechter/internal/proto"
)

const testPW = "testpasswort123"

type testEnv struct {
	t   *testing.T
	s   *Server
	ts  *httptest.Server
	cli *http.Client // angemeldeter Admin
}

func newEnv(t *testing.T) *testEnv {
	t.Helper()
	cfg := Config{DataDir: t.TempDir(), DownloadsDir: t.TempDir(), PublicURL: "https://monitor.test", AdminUser: "admin",
		AdminPassword: testPW, MetricRetention: 30, LogRetention: 14, AlertRetention: 180, EvaluateInterval: time.Hour}
	s, err := New(cfg)
	if err != nil {
		t.Fatal(err)
	}
	ts := httptest.NewServer(s.routes())
	t.Cleanup(func() { ts.Close(); s.db.Close() })
	jar, _ := cookiejar.New(nil)
	e := &testEnv{t: t, s: s, ts: ts, cli: &http.Client{Jar: jar}}
	if code, _ := e.do(e.cli, "POST", "/api/login", map[string]string{"username": "admin", "password": testPW}, true); code != 200 {
		t.Fatalf("Anmeldung: %d", code)
	}
	return e
}

func (e *testEnv) do(c *http.Client, method, path string, body any, csrf bool) (int, map[string]any) {
	e.t.Helper()
	var rd io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		rd = bytes.NewReader(b)
	}
	req, _ := http.NewRequest(method, e.ts.URL+path, rd)
	req.Header.Set("Content-Type", "application/json")
	if csrf {
		req.Header.Set("X-GW-Request", "1")
	}
	resp, err := c.Do(req)
	if err != nil {
		e.t.Fatal(err)
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	var out map[string]any
	_ = json.Unmarshal(raw, &out)
	return resp.StatusCode, out
}

func (e *testEnv) report(agentID, key string, rep proto.Report, queued bool) int {
	e.t.Helper()
	b, _ := json.Marshal(rep)
	req, _ := http.NewRequest("POST", e.ts.URL+"/agent/v1/report", bytes.NewReader(b))
	req.Header.Set("Authorization", "Bearer "+agentID+"."+key)
	if queued {
		req.Header.Set("X-GW-Queued", "1")
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		e.t.Fatal(err)
	}
	resp.Body.Close()
	return resp.StatusCode
}

func (e *testEnv) tenant(approval bool) (int64, string) {
	code, t := e.do(e.cli, "POST", "/api/tenants", map[string]any{"name": "Pflegedienst Test", "branch": "pflege", "require_approval": approval}, true)
	if code != 200 {
		e.t.Fatalf("Kunde anlegen: %d", code)
	}
	return int64(t["id"].(float64)), t["enroll_token"].(string)
}

func (e *testEnv) enroll(token string) (int, proto.EnrollResponse) {
	b, _ := json.Marshal(proto.EnrollRequest{Token: token, OS: "windows", Arch: "amd64"})
	resp, err := http.Post(e.ts.URL+"/agent/v1/enroll", "application/json", bytes.NewReader(b))
	if err != nil {
		e.t.Fatal(err)
	}
	defer resp.Body.Close()
	var er proto.EnrollResponse
	_ = json.NewDecoder(resp.Body).Decode(&er)
	return resp.StatusCode, er
}

func TestAdminAPIRequiresLoginAndCSRF(t *testing.T) {
	e := newEnv(t)
	anon := &http.Client{}
	for _, p := range []string{"/api/tenants", "/api/agents", "/api/rules", "/api/channels", "/api/overview"} {
		if code, _ := e.do(anon, "GET", p, nil, false); code != 401 {
			t.Errorf("%s ohne Anmeldung: %d, erwartet 401", p, code)
		}
	}
	// Ohne CSRF-Header darf keine Änderung möglich sein
	if code, _ := e.do(e.cli, "POST", "/api/tenants", map[string]any{"name": "X"}, false); code != 403 {
		t.Errorf("POST ohne CSRF-Header: %d, erwartet 403", code)
	}
}

func TestLoginCookieAndBruteForce(t *testing.T) {
	e := newEnv(t)
	b, _ := json.Marshal(map[string]string{"username": "admin", "password": testPW})
	resp, _ := http.Post(e.ts.URL+"/api/login", "application/json", bytes.NewReader(b))
	resp.Body.Close()
	sc := resp.Header.Get("Set-Cookie")
	for _, want := range []string{"HttpOnly", "SameSite=Strict"} {
		if !strings.Contains(sc, want) {
			t.Errorf("Sitzungscookie ohne %s: %s", want, sc)
		}
	}
	anon := &http.Client{}
	got429 := false
	for i := 0; i < 12; i++ {
		code, _ := e.do(anon, "POST", "/api/login", map[string]string{"username": "admin", "password": "falsch"}, false)
		if code == 429 {
			got429 = true
			break
		}
	}
	if !got429 {
		t.Fatal("keine Sperre nach wiederholten Fehlversuchen")
	}
}

func TestForwardedForOnlyFromLocalProxy(t *testing.T) {
	r := httptest.NewRequest("POST", "/api/login", nil)
	r.RemoteAddr = "198.51.100.7:4444"
	r.Header.Set("X-Forwarded-For", "1.2.3.4")
	a := clientKey(r)
	r.Header.Set("X-Forwarded-For", "5.6.7.8")
	if clientKey(r) != a {
		t.Fatal("gefälschter X-Forwarded-For von außen darf die Sperre nicht umgehen")
	}
	r.RemoteAddr = "172.18.0.3:4444" // Caddy im Docker-Netz
	if clientKey(r) == a {
		t.Fatal("X-Forwarded-For vom eigenen Proxy sollte ausgewertet werden")
	}
}

func TestEnrollReportAndServerSideRedaction(t *testing.T) {
	e := newEnv(t)
	_, tok := e.tenant(false)
	if code, _ := e.enroll("falsch"); code != 403 {
		t.Fatalf("falscher Schlüssel: %d", code)
	}
	code, cred := e.enroll(tok)
	if code != 200 || cred.AgentID == "" {
		t.Fatalf("Registrierung: %d", code)
	}
	if c := e.report(cred.AgentID, "falscherschluessel", proto.Report{}, false); c != 401 {
		t.Fatalf("falscher Geräteschlüssel: %d", c)
	}
	rep := proto.Report{Time: time.Now(), Metrics: []proto.Metric{{Name: "cpu.percent", Value: 12}},
		Logs: []proto.LogEntry{{Time: time.Now(), Source: "app", Channel: "app:Pflege", Level: 3,
			Message: "Tour für Frau Müller, Tel 0171/1234567, anna@beispiel.de"}}}
	if c := e.report(cred.AgentID, cred.AgentKey, rep, false); c != 200 {
		t.Fatalf("Meldung: %d", c)
	}
	var msg string
	if err := e.s.db.QueryRow(`SELECT message FROM logs WHERE agent_id=?`, cred.AgentID).Scan(&msg); err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{"Müller", "1234567", "anna@"} {
		if strings.Contains(msg, bad) {
			t.Errorf("Server hat %q nicht entfernt: %q", bad, msg)
		}
	}
	// Schlüssel nur als Hash gespeichert
	var hash string
	_ = e.s.db.QueryRow(`SELECT key_hash FROM agents WHERE id=?`, cred.AgentID).Scan(&hash)
	if hash == cred.AgentKey || hash != sha256hex(cred.AgentKey) {
		t.Fatal("Geräteschlüssel muss gehasht gespeichert werden")
	}
	// deaktiviertes Gerät wird abgewiesen
	e.do(e.cli, "PUT", "/api/agents/"+cred.AgentID, map[string]any{"display_name": "X", "disabled": true}, true)
	if c := e.report(cred.AgentID, cred.AgentKey, rep, false); c != 403 {
		t.Fatalf("deaktiviertes Gerät: %d, erwartet 403", c)
	}
}

func TestApprovalAndTokenExpiry(t *testing.T) {
	e := newEnv(t)
	id, tok := e.tenant(true)
	_, cred := e.enroll(tok)
	rep := proto.Report{Time: time.Now(), Metrics: []proto.Metric{{Name: "cpu.percent", Value: 5}}}
	if c := e.report(cred.AgentID, cred.AgentKey, rep, false); c != http.StatusLocked {
		t.Fatalf("nicht freigegebenes Gerät: %d, erwartet 423", c)
	}
	var n int
	_ = e.s.db.QueryRow(`SELECT COUNT(*) FROM metrics WHERE agent_id=?`, cred.AgentID).Scan(&n)
	if n != 0 {
		t.Fatal("Daten nicht freigegebener Geräte dürfen nicht gespeichert werden")
	}
	e.do(e.cli, "POST", "/api/agents/"+cred.AgentID+"/approve", nil, true)
	if c := e.report(cred.AgentID, cred.AgentKey, rep, false); c != 200 {
		t.Fatalf("nach Freigabe: %d", c)
	}
	_, _ = e.s.db.Exec(`UPDATE tenants SET enroll_expires=1 WHERE id=?`, id)
	if code, _ := e.enroll(tok); code != 403 {
		t.Fatalf("abgelaufener Schlüssel: %d, erwartet 403", code)
	}
}

func TestQueuedReportKeepsTimeAndDoesNotOverwriteLatest(t *testing.T) {
	e := newEnv(t)
	_, tok := e.tenant(false)
	_, cred := e.enroll(tok)
	now := time.Now()
	e.report(cred.AgentID, cred.AgentKey, proto.Report{Time: now, Metrics: []proto.Metric{{Name: "cpu.percent", Value: 50}}}, false)
	old := now.Add(-2 * time.Hour)
	e.report(cred.AgentID, cred.AgentKey, proto.Report{Time: old, Metrics: []proto.Metric{{Name: "cpu.percent", Value: 99}}}, true)
	var v float64
	_ = e.s.db.QueryRow(`SELECT value FROM latest WHERE agent_id=? AND name='cpu.percent'`, cred.AgentID).Scan(&v)
	if v != 50 {
		t.Fatalf("aktueller Wert durch nachgereichte Meldung überschrieben: %v", v)
	}
	var ts int64
	_ = e.s.db.QueryRow(`SELECT ts FROM metrics WHERE agent_id=? AND value=99`, cred.AgentID).Scan(&ts)
	if ts != old.Unix() {
		t.Fatalf("nachgereichte Meldung mit falscher Zeit gespeichert: %d statt %d", ts, old.Unix())
	}
}

func TestAlertFiresResolvesAndOfflineGrace(t *testing.T) {
	e := newEnv(t)
	_, tok := e.tenant(false)
	_, cred := e.enroll(tok)
	e.do(e.cli, "POST", "/api/rules", map[string]any{"name": "Platte", "kind": "metric", "metric": "disk.percent", "label_filter": "mount=*",
		"op": ">", "threshold": 90, "severity": "kritisch", "enabled": true}, true)
	e.do(e.cli, "POST", "/api/rules", map[string]any{"name": "Offline", "kind": "offline", "duration_min": 10, "severity": "kritisch", "enabled": true}, true)

	send := func(v float64) {
		e.report(cred.AgentID, cred.AgentKey, proto.Report{Time: time.Now(), Metrics: []proto.Metric{
			{Name: "disk.percent", Value: v, Labels: map[string]string{"mount": "C:"}}}}, false)
	}
	firing := func(name string) int {
		var n int
		_ = e.s.db.QueryRow(`SELECT COUNT(*) FROM alerts al JOIN rules r ON r.id=al.rule_id WHERE al.state='firing' AND r.name=?`, name).Scan(&n)
		return n
	}
	send(95)
	e.s.evaluate(time.Now())
	if firing("Platte") != 1 {
		t.Fatal("Alarm nicht ausgelöst")
	}
	send(40)
	e.s.evaluate(time.Now())
	if firing("Platte") != 0 {
		t.Fatal("Alarm nicht aufgehoben")
	}
	// Gerät war lange still, Server ist aber gerade erst gestartet: kein Fehlalarm
	_, _ = e.s.db.Exec(`UPDATE agents SET last_seen=? WHERE id=?`, time.Now().Add(-2*time.Hour).Unix(), cred.AgentID)
	e.s.evaluate(time.Now())
	if firing("Offline") != 0 {
		t.Fatal("Offline-Fehlalarm direkt nach Serverstart")
	}
	e.s.evaluate(time.Now().Add(11 * time.Minute))
	if firing("Offline") != 1 {
		t.Fatal("Offline-Alarm nach Schonfrist nicht ausgelöst")
	}
}

func TestSecurityHeadersAndDownloadPaths(t *testing.T) {
	e := newEnv(t)
	resp, err := http.Get(e.ts.URL + "/")
	if err != nil {
		t.Fatal(err)
	}
	resp.Body.Close()
	for _, h := range []string{"Content-Security-Policy", "X-Frame-Options", "X-Content-Type-Options", "Referrer-Policy"} {
		if resp.Header.Get(h) == "" {
			t.Errorf("Sicherheits-Header %s fehlt", h)
		}
	}
	for _, p := range []string{"/downloads/..%2fgeraetewaechter.db", "/downloads/geraetewaechter.db", "/downloads/%2e%2e"} {
		r, err := http.Get(e.ts.URL + p)
		if err != nil {
			t.Fatal(err)
		}
		r.Body.Close()
		if r.StatusCode == 200 {
			t.Errorf("%s darf nicht ausgeliefert werden", p)
		}
	}
}

func TestBackup(t *testing.T) {
	e := newEnv(t)
	e.tenant(false)
	target := t.TempDir() + "/sicherung.db"
	if err := Backup(e.s.db, target); err != nil {
		t.Fatal(err)
	}
	db, err := openDB(target)
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()
	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM tenants`).Scan(&n)
	if n != 1 {
		t.Fatalf("Sicherung unvollständig: %d Kunden", n)
	}
}
