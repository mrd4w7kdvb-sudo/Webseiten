package server

import (
	"compress/gzip"
	"database/sql"
	"embed"
	"encoding/json"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	"geraetewaechter/internal/proto"
)

var (
	metricNameRe = regexp.MustCompile(`^[a-z0-9_.]{1,80}$`)
	labelKeyRe   = regexp.MustCompile(`^[a-z0-9_]{1,32}$`)
	eventIDRe    = regexp.MustCompile(`^[A-Za-z0-9_.\-]{1,32}$`)
	downloadRe   = regexp.MustCompile(`^(gw-agent-[a-z0-9_]+(\.exe)?|SHA256SUMS)$`)
)

func (s *Server) handleEnroll(w http.ResponseWriter, r *http.Request) {
	key := clientKey(r)
	if !s.limiter.allow("enroll" + key) {
		httpErr(w, http.StatusTooManyRequests, "zu viele Versuche")
		return
	}
	var req proto.EnrollRequest
	if err := readJSON(r, &req, 8192); err != nil || req.Token == "" {
		httpErr(w, http.StatusBadRequest, "ungültig")
		return
	}
	var tenantID, expires int64
	var needApproval int
	if err := s.db.QueryRow(`SELECT id, enroll_expires, require_approval FROM tenants WHERE enroll_token=?`, strings.TrimSpace(req.Token)).
		Scan(&tenantID, &expires, &needApproval); err != nil {
		s.limiter.fail("enroll" + key)
		httpErr(w, http.StatusForbidden, "Registrierungsschlüssel ungültig")
		return
	}
	if expires > 0 && time.Now().Unix() > expires {
		s.limiter.fail("enroll" + key)
		httpErr(w, http.StatusForbidden, "Registrierungsschlüssel abgelaufen – bitte in der Verwaltung neu erzeugen")
		return
	}
	id := strings.ToLower(randomToken(12))
	id = strings.NewReplacer("-", "x", "_", "y").Replace(id)
	agentKey := randomToken(32)
	osName := short(req.OS, 20)
	name := s.san.Label(req.DisplayName)
	if name == "" {
		name = osName + "-" + id[:6]
	}
	_, err := s.db.Exec(`INSERT INTO agents(id, tenant_id, display_name, key_hash, os, arch, agent_version, created_at, approved)
		VALUES(?,?,?,?,?,?,?,?,?)`, id, tenantID, name, sha256hex(agentKey), osName, short(req.Arch, 20), short(req.AgentVersion, 20), time.Now().Unix(), 1-needApproval)
	if err != nil {
		httpErr(w, http.StatusInternalServerError, "Fehler")
		return
	}
	writeJSON(w, http.StatusOK, proto.EnrollResponse{AgentID: id, AgentKey: agentKey})
}

type agentAuth struct {
	id       string
	tenantID int64
}

func (s *Server) authAgent(r *http.Request) (*agentAuth, int) {
	h := r.Header.Get("Authorization")
	if !strings.HasPrefix(h, "Bearer ") {
		return nil, http.StatusUnauthorized
	}
	id, key, ok := strings.Cut(strings.TrimPrefix(h, "Bearer "), ".")
	if !ok || id == "" || key == "" {
		return nil, http.StatusUnauthorized
	}
	var hash string
	var tenant int64
	var disabled, approved int
	if err := s.db.QueryRow(`SELECT key_hash, tenant_id, disabled, approved FROM agents WHERE id=?`, id).Scan(&hash, &tenant, &disabled, &approved); err != nil {
		return nil, http.StatusUnauthorized
	}
	if !constEq(hash, sha256hex(key)) {
		return nil, http.StatusUnauthorized
	}
	if disabled != 0 {
		return nil, http.StatusForbidden
	}
	if approved == 0 {
		// Gerät noch nicht freigegeben: Lebenszeichen merken, Daten verwerfen.
		_, _ = s.db.Exec(`UPDATE agents SET last_seen=? WHERE id=?`, time.Now().Unix(), id)
		return nil, http.StatusLocked
	}
	return &agentAuth{id: id, tenantID: tenant}, 0
}

func (s *Server) handleReport(w http.ResponseWriter, r *http.Request) {
	ag, code := s.authAgent(r)
	if ag == nil {
		msg := "nicht berechtigt"
		if code == http.StatusLocked {
			msg = "Gerät wartet auf Freigabe in der Verwaltung"
		}
		httpErr(w, code, msg)
		return
	}
	var body io.Reader = io.LimitReader(r.Body, proto.MaxReportBodySize)
	if r.Header.Get("Content-Encoding") == "gzip" {
		gz, err := gzip.NewReader(body)
		if err != nil {
			httpErr(w, http.StatusBadRequest, "gzip")
			return
		}
		defer gz.Close()
		body = io.LimitReader(gz, 4*proto.MaxReportBodySize)
	}
	var rep proto.Report
	if err := json.NewDecoder(body).Decode(&rep); err != nil {
		httpErr(w, http.StatusBadRequest, "ungültiges Format")
		return
	}
	if err := s.store(ag, &rep, time.Now()); err != nil {
		httpErr(w, http.StatusInternalServerError, "Speicherfehler")
		return
	}
	var interval int
	var procs, svcs string
	_ = s.db.QueryRow(`SELECT interval_sec, watch_processes, watch_services FROM tenants WHERE id=?`, ag.tenantID).Scan(&interval, &procs, &svcs)
	writeJSON(w, http.StatusOK, proto.ReportResponse{IntervalSeconds: interval, WatchProcesses: jsonList(procs), WatchServices: jsonList(svcs)})
}

// store prüft und bereinigt die Meldung erneut (zweite Verteidigungslinie)
// und speichert sie.
func (s *Server) store(ag *agentAuth, rep *proto.Report, now time.Time) error {
	ts := now.Unix()
	tx, err := s.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback() //nolint:errcheck

	sys := rep.System
	if _, err := tx.Exec(`UPDATE agents SET os=?, platform=?, platform_version=?, kernel=?, arch=?, agent_version=?, uptime=?, last_seen=? WHERE id=?`,
		short(sys.OS, 20), short(s.san.Label(sys.Platform), 40), short(s.san.Label(sys.PlatformVersion), 40),
		short(s.san.Label(sys.KernelVersion), 60), short(sys.Arch, 20), short(rep.AgentVersion, 20),
		sys.UptimeSeconds, ts, ag.id); err != nil {
		return err
	}

	insM, err := tx.Prepare(`INSERT INTO metrics(agent_id, ts, name, labels, value) VALUES(?,?,?,?,?)`)
	if err != nil {
		return err
	}
	defer insM.Close()
	upL, err := tx.Prepare(`INSERT INTO latest(agent_id,name,labels,value,ts) VALUES(?,?,?,?,?)
		ON CONFLICT(agent_id,name,labels) DO UPDATE SET value=excluded.value, ts=excluded.ts`)
	if err != nil {
		return err
	}
	defer upL.Close()
	putMetric := func(name string, labels map[string]string, v float64) error {
		lb := s.encodeLabels(labels)
		if _, err := insM.Exec(ag.id, ts, name, lb, v); err != nil {
			return err
		}
		_, err := upL.Exec(ag.id, name, lb, v, ts)
		return err
	}

	for i, m := range rep.Metrics {
		if i >= proto.MaxMetricsPerRep {
			break
		}
		if !metricNameRe.MatchString(m.Name) || isNaN(m.Value) {
			continue
		}
		if err := putMetric(m.Name, m.Labels, m.Value); err != nil {
			return err
		}
	}

	// Prüfungen: aktueller Zustand + als Metrik für Alarmregeln
	if _, err := tx.Exec(`DELETE FROM checks WHERE agent_id=?`, ag.id); err != nil {
		return err
	}
	for i, c := range rep.Checks {
		if i >= proto.MaxChecksPerRep {
			break
		}
		if c.Kind != "process" && c.Kind != "service" {
			continue
		}
		name := s.san.Label(c.Name)
		if name == "" {
			continue
		}
		ok := 0.0
		if c.OK {
			ok = 1
		}
		if _, err := tx.Exec(`INSERT OR REPLACE INTO checks(agent_id,kind,name,ok,detail,ts) VALUES(?,?,?,?,?,?)`,
			ag.id, c.Kind, name, ok, short(s.san.Label(c.Detail), 64), ts); err != nil {
			return err
		}
		if err := putMetric(c.Kind+".running", map[string]string{"name": name}, ok); err != nil {
			return err
		}
	}

	insLog, err := tx.Prepare(`INSERT INTO logs(agent_id, ts, received, channel, source, level, event_id, message, fingerprint) VALUES(?,?,?,?,?,?,?,?,?)`)
	if err != nil {
		return err
	}
	defer insLog.Close()
	for i, l := range rep.Logs {
		if i >= proto.MaxLogsPerReport {
			break
		}
		lt := l.Time.Unix()
		if l.Time.IsZero() || lt > ts+300 || lt < ts-30*86400 {
			lt = ts
		}
		lvl := l.Level
		if lvl < 0 || lvl > 7 {
			lvl = 6
		}
		ev := ""
		if eventIDRe.MatchString(l.EventID) {
			ev = l.EventID
		}
		ch := "system"
		if strings.HasPrefix(l.Channel, "app:") {
			ch = "app:" + short(s.san.Label(strings.TrimPrefix(l.Channel, "app:")), 40)
		}
		fp := ""
		if len(l.Fingerprint) <= 32 {
			fp = l.Fingerprint
		}
		if _, err := insLog.Exec(ag.id, lt, ts, ch, short(s.san.Label(l.Source), 64), lvl, ev, s.san.Text(l.Message), fp); err != nil {
			return err
		}
	}
	if err := putMetric("log.received", nil, float64(min(len(rep.Logs), proto.MaxLogsPerReport))); err != nil {
		return err
	}
	return tx.Commit()
}

func (s *Server) encodeLabels(labels map[string]string) string {
	if len(labels) == 0 {
		return ""
	}
	keys := make([]string, 0, len(labels))
	for k := range labels {
		if labelKeyRe.MatchString(k) {
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)
	if len(keys) > 4 {
		keys = keys[:4]
	}
	parts := make([]string, 0, len(keys))
	for _, k := range keys {
		v := strings.NewReplacer(",", " ", "=", " ").Replace(s.san.Label(labels[k]))
		parts = append(parts, k+"="+v)
	}
	return strings.Join(parts, ",")
}

func short(s string, n int) string {
	s = strings.TrimSpace(s)
	r := []rune(s)
	if len(r) > n {
		return string(r[:n])
	}
	return s
}

func isNaN(f float64) bool { return f != f || f > 1e300 || f < -1e300 }

// ---------- Downloads & Installationsskripte ----------

//go:embed scripts/*
var scriptFS embed.FS

func (s *Server) handleDownload(w http.ResponseWriter, r *http.Request) {
	f := r.PathValue("file")
	if !downloadRe.MatchString(f) {
		http.NotFound(w, r)
		return
	}
	p := filepath.Join(s.cfg.DownloadsDir, f)
	if _, err := os.Stat(p); err != nil {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Disposition", `attachment; filename="`+f+`"`)
	http.ServeFile(w, r, p)
}

func (s *Server) handleInstallScript(w http.ResponseWriter, r *http.Request) {
	name := strings.TrimPrefix(r.URL.Path, "/")
	b, err := scriptFS.ReadFile("scripts/" + name)
	if err != nil {
		http.NotFound(w, r)
		return
	}
	out := strings.ReplaceAll(string(b), "__GW_SERVER__", s.cfg.PublicURL)
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	_, _ = io.WriteString(w, out)
}

var _ = sql.ErrNoRows
