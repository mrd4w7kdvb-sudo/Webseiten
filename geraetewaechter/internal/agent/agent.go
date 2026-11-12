package agent

import (
	"bytes"
	"compress/gzip"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strings"
	"time"

	"geraetewaechter/internal/privacy"
	"geraetewaechter/internal/proto"
)

// Agent ist eine laufende Sensor-Instanz.
type Agent struct {
	cfg    *Config
	san    *privacy.Sanitizer
	col    *collector
	st     *state
	client *http.Client
}

// genericHostWords sind Bestandteile von Rechnernamen ohne Personenbezug.
var genericHostWords = map[string]bool{
	"pc": true, "laptop": true, "desktop": true, "notebook": true, "win": true, "windows": true, "macbook": true,
	"pro": true, "air": true, "imac": true, "mac": true, "local": true, "localhost": true, "raspberrypi": true, "server": true,
	"client": true, "buero": true, "büro": true, "office": true, "tablet": true, "surface": true, "home": true, "lan": true,
	"fritz": true, "box": true, "mini": true, "ubuntu": true, "debian": true, "linux": true,
}

// BuildSanitizer erstellt den Bereinigungsfilter für dieses Gerät. Lokale
// Kontonamen, Klarnamen und der Rechnername werden immer ersetzt.
func BuildSanitizer(cfg *Config) (*privacy.Sanitizer, error) {
	lit := map[string]string{}
	accounts, names := localIdentities()
	for _, a := range accounts {
		lit[a] = "[USER]"
	}
	for _, n := range names {
		lit[n] = "[NAME]"
		for _, part := range strings.FieldsFunc(n, func(r rune) bool { return r == ' ' || r == ',' || r == '-' || r == '.' }) {
			lit[part] = "[NAME]"
		}
	}
	if h, err := os.Hostname(); err == nil && h != "" {
		h = strings.Split(h, ".")[0]
		lit[h] = "[HOST]"
		for _, part := range strings.FieldsFunc(h, func(r rune) bool { return r == '-' || r == '_' || r == '.' || r == ' ' }) {
			if !genericHostWords[strings.ToLower(part)] {
				lit[part] = "[HOST]"
			}
		}
	}
	return privacy.New(privacy.Options{
		Literals:      lit,
		DenyWords:     cfg.Privacy.DenyWords,
		ExtraPatterns: cfg.Privacy.DenyPatterns,
	})
}

// New erstellt einen Agenten aus der lokalen Konfiguration.
func New(cfg *Config) (*Agent, error) {
	san, err := BuildSanitizer(cfg)
	if err != nil {
		return nil, fmt.Errorf("Datenschutz-Filter: %w", err)
	}
	return &Agent{cfg: cfg, san: san, col: newCollector(san), st: loadState(), client: httpClient(cfg)}, nil
}

func httpClient(cfg *Config) *http.Client {
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12, InsecureSkipVerify: cfg.InsecureSkipVerify} //nolint:gosec // nur bei ausdrücklicher Konfiguration
	return &http.Client{Timeout: 30 * time.Second, Transport: tr}
}

func checkServerURL(raw string, allowHTTP bool) (string, error) {
	u, err := url.Parse(strings.TrimRight(strings.TrimSpace(raw), "/"))
	if err != nil || u.Host == "" {
		return "", errors.New("Server-Adresse ungültig")
	}
	if u.Scheme != "https" {
		h := u.Hostname()
		local := h == "localhost" || h == "127.0.0.1" || h == "::1"
		if u.Scheme != "http" || !(allowHTTP || local) {
			return "", errors.New("Server muss per https:// erreichbar sein (Daten werden nur verschlüsselt übertragen)")
		}
	}
	return u.String(), nil
}

// Enroll registriert das Gerät beim Server und speichert die Zugangsdaten.
func Enroll(server, token, displayName string, force, allowHTTP bool) error {
	cfg, err := LoadConfig()
	if err != nil {
		if !errors.Is(err, os.ErrNotExist) {
			return err
		}
		cfg = DefaultConfig()
	}
	if cfg.AgentID != "" && !force {
		fmt.Println("Gerät ist bereits registriert (ID " + cfg.AgentID + "). Mit --force neu registrieren.")
		return nil
	}
	srv, err := checkServerURL(server, allowHTTP || cfg.AllowHTTP)
	if err != nil {
		return err
	}
	cfg.AllowHTTP = cfg.AllowHTTP || allowHTTP
	name := ""
	if cfg.Privacy.SendHostname || displayName != "" {
		name = displayName
		if name == "" {
			name, _ = os.Hostname()
		}
	}
	body, _ := json.Marshal(proto.EnrollRequest{Token: token, OS: runtime.GOOS, Arch: runtime.GOARCH, AgentVersion: Version, DisplayName: name})
	resp, err := httpClient(cfg).Post(srv+"/agent/v1/enroll", "application/json", bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("Server nicht erreichbar: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("Registrierung abgelehnt (HTTP %d): %s", resp.StatusCode, strings.TrimSpace(string(msg)))
	}
	var er proto.EnrollResponse
	if err := json.NewDecoder(resp.Body).Decode(&er); err != nil || er.AgentID == "" {
		return errors.New("ungültige Antwort des Servers")
	}
	cfg.Server, cfg.AgentID, cfg.AgentKey = srv, er.AgentID, er.AgentKey
	if cfg.FingerprintSalt == "" {
		cfg.FingerprintSalt = randHex(16)
	}
	if err := cfg.Save(); err != nil {
		return err
	}
	fmt.Println("Gerät registriert. ID:", er.AgentID)
	return nil
}

func union(a, b []string) []string {
	m := map[string]bool{}
	var out []string
	for _, l := range [][]string{a, b} {
		for _, v := range l {
			v = strings.TrimSpace(v)
			if v != "" && !m[strings.ToLower(v)] {
				m[strings.ToLower(v)] = true
				out = append(out, v)
			}
		}
	}
	sort.Strings(out)
	return out
}

// Collect erstellt eine Meldung (ohne sie zu senden).
func (a *Agent) Collect() *proto.Report {
	si := a.col.systemInfo()
	rep := &proto.Report{AgentVersion: Version, Time: time.Now().UTC(), System: si}
	rep.Metrics = a.col.metrics(si)
	rep.Checks = a.col.checks(union(a.cfg.WatchProcesses, a.st.ServerProcs), union(a.cfg.WatchServices, a.st.ServerServices))

	mode := privacy.ParseMode(a.cfg.Privacy.SystemLogMode)
	if strings.ToLower(a.cfg.Privacy.SystemLogMode) != "off" {
		logs, err := systemLogs(a.st, a.cfg.Privacy.SystemLogMaxLevel)
		if err != nil {
			log.Printf("Systemprotokoll: %v", err)
		}
		for _, l := range logs {
			if l.Level > a.cfg.Privacy.SystemLogMaxLevel {
				continue
			}
			rep.Logs = append(rep.Logs, toEntry(l, "system", mode, a.san, a.cfg.FingerprintSalt))
		}
	}
	for _, al := range appLogs(a.cfg, a.st) {
		rep.Logs = append(rep.Logs, toEntry(al.log, "app:"+al.app.Name, privacy.ParseMode(al.app.Mode), a.san, a.cfg.FingerprintSalt))
	}
	if len(rep.Logs) > proto.MaxLogsPerReport {
		// die schwerwiegendsten behalten
		sort.SliceStable(rep.Logs, func(i, j int) bool { return rep.Logs[i].Level < rep.Logs[j].Level })
		rep.Logs = rep.Logs[:proto.MaxLogsPerReport]
	}
	rep.Metrics = append(rep.Metrics, proto.Metric{Name: "agent.queue", Value: float64(queueLen())})
	return rep
}

func encode(rep *proto.Report) ([]byte, error) {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	if err := json.NewEncoder(gz).Encode(rep); err != nil {
		return nil, err
	}
	if err := gz.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

var errAuth = errors.New("Agent beim Server nicht (mehr) berechtigt")

func (a *Agent) post(body []byte) (*proto.ReportResponse, error) {
	req, err := http.NewRequest(http.MethodPost, a.cfg.Server+"/agent/v1/report", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Content-Encoding", "gzip")
	req.Header.Set("Authorization", "Bearer "+a.cfg.AgentID+"."+a.cfg.AgentKey)
	req.Header.Set("User-Agent", "gw-agent/"+Version)
	resp, err := a.client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	switch {
	case resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden:
		return nil, errAuth
	case resp.StatusCode == http.StatusBadRequest:
		return nil, errBadReport
	case resp.StatusCode != http.StatusOK:
		return nil, fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	var rr proto.ReportResponse
	_ = json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&rr)
	return &rr, nil
}

var errBadReport = errors.New("Meldung vom Server abgelehnt")

// Once erstellt und sendet eine Meldung; bei Netzproblemen wird gepuffert.
func (a *Agent) Once() error {
	rep := a.Collect()
	body, err := encode(rep)
	if err != nil {
		return err
	}
	if a.cfg.Privacy.AuditFile {
		writeAudit(rep)
	}
	// Erst ältere gepufferte Meldungen nachsenden
	a.flushQueue()
	rr, err := a.post(body)
	if err != nil {
		if !errors.Is(err, errAuth) && !errors.Is(err, errBadReport) {
			enqueue(body)
		}
		_ = a.st.save()
		return err
	}
	a.applyResponse(rr)
	return a.st.save()
}

func (a *Agent) applyResponse(rr *proto.ReportResponse) {
	if rr == nil {
		return
	}
	a.st.ServerProcs = limitList(rr.WatchProcesses)
	a.st.ServerServices = limitList(rr.WatchServices)
	if rr.IntervalSeconds >= 15 && rr.IntervalSeconds <= 3600 {
		a.st.ServerInterval = rr.IntervalSeconds
	}
}

func limitList(in []string) []string {
	var out []string
	for _, v := range in {
		if len(v) <= proto.MaxNameLen && len(out) < 50 {
			out = append(out, v)
		}
	}
	return out
}

func (a *Agent) interval() time.Duration {
	s := a.cfg.IntervalSeconds
	if a.st.ServerInterval > 0 {
		s = a.st.ServerInterval
	}
	return time.Duration(s) * time.Second
}

// Run sendet periodisch Meldungen, bis ctx beendet wird.
func (a *Agent) Run(ctx context.Context) {
	log.Printf("Gerätewächter-Agent %s gestartet (Server %s)", Version, a.cfg.Server)
	for {
		if err := a.Once(); err != nil {
			log.Printf("Meldung: %v", err)
		}
		select {
		case <-ctx.Done():
			_ = a.st.save()
			return
		case <-time.After(a.interval()):
		}
	}
}

// ---------- Offline-Puffer ----------

const maxQueue = 500

func queueDir() string { return filepath.Join(DataDir(), "queue") }

func queueLen() int {
	e, _ := os.ReadDir(queueDir())
	return len(e)
}

func enqueue(body []byte) {
	if err := os.MkdirAll(queueDir(), 0o700); err != nil {
		return
	}
	ents, _ := os.ReadDir(queueDir())
	for i := 0; len(ents)-i >= maxQueue; i++ { // älteste verwerfen
		_ = os.Remove(filepath.Join(queueDir(), ents[i].Name()))
	}
	name := fmt.Sprintf("%020d.json.gz", time.Now().UnixNano())
	_ = os.WriteFile(filepath.Join(queueDir(), name), body, 0o600)
}

func (a *Agent) flushQueue() {
	ents, err := os.ReadDir(queueDir())
	if err != nil {
		return
	}
	for i, e := range ents {
		if i >= 50 { // pro Durchlauf begrenzen
			return
		}
		p := filepath.Join(queueDir(), e.Name())
		b, err := os.ReadFile(p)
		if err != nil {
			_ = os.Remove(p)
			continue
		}
		if _, err := a.post(b); err != nil {
			if errors.Is(err, errBadReport) {
				_ = os.Remove(p)
				continue
			}
			return
		}
		_ = os.Remove(p)
	}
}

// writeAudit speichert die zuletzt gesendete Meldung lesbar auf dem Gerät,
// damit der Kunde (bzw. dessen Datenschutzbeauftragter) jederzeit prüfen
// kann, welche Daten übertragen werden.
func writeAudit(rep *proto.Report) {
	b, err := json.MarshalIndent(rep, "", "  ")
	if err != nil {
		return
	}
	_ = os.MkdirAll(DataDir(), 0o700)
	_ = os.WriteFile(filepath.Join(DataDir(), "letzte-meldung.json"), b, 0o600)
}

// Preview erstellt eine Meldung und gibt sie aus, ohne sie zu senden.
func Preview(w io.Writer) error {
	cfg, err := LoadConfig()
	if err != nil {
		if !errors.Is(err, os.ErrNotExist) {
			return err
		}
		cfg = DefaultConfig()
		cfg.FingerprintSalt = "vorschau"
	}
	a, err := New(cfg)
	if err != nil {
		return err
	}
	a.st = loadState()
	// Vorschau soll den Status nicht verändern: Kopie verwenden
	stCopy := *a.st
	stCopy.FileOffsets = map[string]int64{}
	for k, v := range a.st.FileOffsets {
		stCopy.FileOffsets[k] = v
	}
	// Vorschau zeigt die letzten 64 KB jeder konfigurierten Anwendungs-Logdatei
	stCopy.AppInitialized = map[string]bool{}
	for _, al := range cfg.AppLogs {
		stCopy.AppInitialized[al.Name] = true
		files, _ := filepath.Glob(al.Path)
		for _, f := range files {
			if info, err := os.Stat(f); err == nil {
				stCopy.FileOffsets[f] = max(0, info.Size()-64<<10)
			}
		}
	}
	a.st = &stCopy
	time.Sleep(time.Second) // CPU-Messung braucht ein Intervall
	rep := a.Collect()
	enc := json.NewEncoder(w)
	enc.SetIndent("", "  ")
	enc.SetEscapeHTML(false)
	return enc.Encode(rep)
}
