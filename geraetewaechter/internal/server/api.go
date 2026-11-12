package server

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"regexp"
	"strings"
	"time"
)

// ---------- Kunden (Mandanten) ----------

// Tenant ist ein Kunde (Pflegedienst, Hof, ...).
type Tenant struct {
	ID             int64    `json:"id"`
	Name           string   `json:"name"`
	Branch         string   `json:"branch"`
	Contact        string   `json:"contact"`
	Notes          string   `json:"notes"`
	EnrollToken    string   `json:"enroll_token,omitempty"`
	EnrollExpires  int64    `json:"enroll_expires"`
	RequireApprov  bool     `json:"require_approval"`
	IntervalSec    int      `json:"interval_sec"`
	WatchProcesses []string `json:"watch_processes"`
	WatchServices  []string `json:"watch_services"`
	CreatedAt      int64    `json:"created_at"`
	Agents         int      `json:"agents"`
	Online         int      `json:"online"`
	Firing         int      `json:"firing"`
}

// defaultTokenDays ist die Gültigkeit neuer Registrierungsschlüssel.
const defaultTokenDays = 7

var branches = map[string]bool{"pflege": true, "landwirtschaft": true, "sonstige": true}

func onlineCutoff(now time.Time) int64 { return now.Unix() - 180 }

func (s *Server) handleTenantList(w http.ResponseWriter, r *http.Request, _ *User) {
	now := time.Now()
	rows, err := s.db.Query(`SELECT t.id, t.name, t.branch, t.contact, t.interval_sec, t.created_at,
		(SELECT COUNT(*) FROM agents a WHERE a.tenant_id=t.id),
		(SELECT COUNT(*) FROM agents a WHERE a.tenant_id=t.id AND a.last_seen > MAX(?, ? - 3*t.interval_sec)),
		(SELECT COUNT(*) FROM alerts al JOIN agents a ON a.id=al.agent_id WHERE a.tenant_id=t.id AND al.state='firing')
		FROM tenants t ORDER BY t.name COLLATE NOCASE`, onlineCutoff(now), now.Unix())
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	defer rows.Close()
	out := []Tenant{}
	for rows.Next() {
		var t Tenant
		if err := rows.Scan(&t.ID, &t.Name, &t.Branch, &t.Contact, &t.IntervalSec, &t.CreatedAt, &t.Agents, &t.Online, &t.Firing); err != nil {
			httpErr(w, 500, err.Error())
			return
		}
		out = append(out, t)
	}
	writeJSON(w, 200, out)
}

func (s *Server) loadTenant(id int64) (*Tenant, error) {
	var t Tenant
	var procs, svcs string
	var appr int
	err := s.db.QueryRow(`SELECT id, name, branch, contact, notes, enroll_token, enroll_expires, require_approval, interval_sec, watch_processes, watch_services, created_at
		FROM tenants WHERE id=?`, id).Scan(&t.ID, &t.Name, &t.Branch, &t.Contact, &t.Notes, &t.EnrollToken, &t.EnrollExpires, &appr, &t.IntervalSec, &procs, &svcs, &t.CreatedAt)
	if err != nil {
		return nil, err
	}
	t.RequireApprov = appr != 0
	t.WatchProcesses, t.WatchServices = jsonList(procs), jsonList(svcs)
	return &t, nil
}

func (s *Server) handleTenantGet(w http.ResponseWriter, r *http.Request, _ *User) {
	id, ok := pathInt(r, "id")
	if !ok {
		httpErr(w, 400, "id")
		return
	}
	t, err := s.loadTenant(id)
	if err != nil {
		httpErr(w, 404, "Kunde nicht gefunden")
		return
	}
	writeJSON(w, 200, t)
}

func (s *Server) handleTenantSave(w http.ResponseWriter, r *http.Request, _ *User) {
	var t Tenant
	if err := readJSON(r, &t, 64<<10); err != nil {
		httpErr(w, 400, "ungültig")
		return
	}
	t.Name = short(t.Name, 120)
	if t.Name == "" {
		httpErr(w, 400, "Name fehlt")
		return
	}
	if !branches[t.Branch] {
		t.Branch = "sonstige"
	}
	if t.IntervalSec < 15 || t.IntervalSec > 3600 {
		t.IntervalSec = 60
	}
	procs := toJSON(cleanList(t.WatchProcesses, 50, 80))
	svcs := toJSON(cleanList(t.WatchServices, 50, 80))
	if id, ok := pathInt(r, "id"); ok {
		_, err := s.db.Exec(`UPDATE tenants SET name=?, branch=?, contact=?, notes=?, interval_sec=?, watch_processes=?, watch_services=?, require_approval=? WHERE id=?`,
			t.Name, t.Branch, short(t.Contact, 200), short(t.Notes, 2000), t.IntervalSec, procs, svcs, b2i(t.RequireApprov), id)
		if err != nil {
			httpErr(w, 500, err.Error())
			return
		}
		t.ID = id
	} else {
		res, err := s.db.Exec(`INSERT INTO tenants(name, branch, contact, notes, enroll_token, enroll_expires, require_approval, interval_sec, watch_processes, watch_services, created_at)
			VALUES(?,?,?,?,?,?,?,?,?,?,?)`, t.Name, t.Branch, short(t.Contact, 200), short(t.Notes, 2000), randomToken(24),
			time.Now().Add(defaultTokenDays*24*time.Hour).Unix(), b2i(t.RequireApprov), t.IntervalSec, procs, svcs, time.Now().Unix())
		if err != nil {
			httpErr(w, 500, err.Error())
			return
		}
		t.ID, _ = res.LastInsertId()
	}
	nt, _ := s.loadTenant(t.ID)
	writeJSON(w, 200, nt)
}

func (s *Server) handleTenantDelete(w http.ResponseWriter, r *http.Request, _ *User) {
	id, ok := pathInt(r, "id")
	if !ok {
		httpErr(w, 400, "id")
		return
	}
	if _, err := s.db.Exec(`DELETE FROM tenants WHERE id=?`, id); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func (s *Server) handleTenantRotate(w http.ResponseWriter, r *http.Request, _ *User) {
	id, ok := pathInt(r, "id")
	if !ok {
		httpErr(w, 400, "id")
		return
	}
	var req struct {
		Days int `json:"days"` // 0 = unbegrenzt gültig
	}
	req.Days = defaultTokenDays
	_ = readJSON(r, &req, 1024)
	var exp int64
	if req.Days > 0 {
		exp = time.Now().Add(time.Duration(min(req.Days, 365)) * 24 * time.Hour).Unix()
	}
	if _, err := s.db.Exec(`UPDATE tenants SET enroll_token=?, enroll_expires=? WHERE id=?`, randomToken(24), exp, id); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	t, _ := s.loadTenant(id)
	writeJSON(w, 200, t)
}

// ---------- Geräte ----------

// Agent ist ein überwachtes Gerät.
type Agent struct {
	ID              string  `json:"id"`
	TenantID        int64   `json:"tenant_id"`
	TenantName      string  `json:"tenant_name"`
	DisplayName     string  `json:"display_name"`
	OS              string  `json:"os"`
	Platform        string  `json:"platform"`
	PlatformVersion string  `json:"platform_version"`
	Kernel          string  `json:"kernel"`
	Arch            string  `json:"arch"`
	AgentVersion    string  `json:"agent_version"`
	Uptime          int64   `json:"uptime"`
	CreatedAt       int64   `json:"created_at"`
	LastSeen        int64   `json:"last_seen"`
	Disabled        bool    `json:"disabled"`
	Approved        bool    `json:"approved"`
	Online          bool    `json:"online"`
	CPU             float64 `json:"cpu"`
	Mem             float64 `json:"mem"`
	Disk            float64 `json:"disk"`
	Firing          int     `json:"firing"`
}

const agentSelect = `SELECT a.id, a.tenant_id, t.name, a.display_name, a.os, a.platform, a.platform_version, a.kernel, a.arch,
	a.agent_version, a.uptime, a.created_at, a.last_seen, a.disabled, a.approved, t.interval_sec,
	COALESCE((SELECT value FROM latest l WHERE l.agent_id=a.id AND l.name='cpu.percent' AND l.labels=''), -1),
	COALESCE((SELECT value FROM latest l WHERE l.agent_id=a.id AND l.name='mem.percent' AND l.labels=''), -1),
	COALESCE((SELECT MAX(value) FROM latest l WHERE l.agent_id=a.id AND l.name='disk.percent'), -1),
	(SELECT COUNT(*) FROM alerts al WHERE al.agent_id=a.id AND al.state='firing')
	FROM agents a JOIN tenants t ON t.id=a.tenant_id`

func scanAgents(rows *sql.Rows, now time.Time) ([]Agent, error) {
	out := []Agent{}
	for rows.Next() {
		var a Agent
		var dis, appr, interval int
		if err := rows.Scan(&a.ID, &a.TenantID, &a.TenantName, &a.DisplayName, &a.OS, &a.Platform, &a.PlatformVersion, &a.Kernel, &a.Arch,
			&a.AgentVersion, &a.Uptime, &a.CreatedAt, &a.LastSeen, &dis, &appr, &interval, &a.CPU, &a.Mem, &a.Disk, &a.Firing); err != nil {
			return nil, err
		}
		a.Disabled = dis != 0
		a.Approved = appr != 0
		a.Online = a.Approved && a.LastSeen > max(onlineCutoff(now), now.Unix()-3*int64(interval))
		out = append(out, a)
	}
	return out, rows.Err()
}

func (s *Server) handleAgentList(w http.ResponseWriter, r *http.Request, _ *User) {
	q := agentSelect
	args := []any{}
	if t := queryInt(r, "tenant", 0); t > 0 {
		q += ` WHERE a.tenant_id=?`
		args = append(args, t)
	}
	q += ` ORDER BY t.name COLLATE NOCASE, a.display_name COLLATE NOCASE`
	rows, err := s.db.Query(q, args...)
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	defer rows.Close()
	out, err := scanAgents(rows, time.Now())
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, out)
}

type latestRow struct {
	Name   string  `json:"name"`
	Labels string  `json:"labels"`
	Value  float64 `json:"value"`
	TS     int64   `json:"ts"`
}

type checkRow struct {
	Kind   string `json:"kind"`
	Name   string `json:"name"`
	OK     bool   `json:"ok"`
	Detail string `json:"detail"`
	TS     int64  `json:"ts"`
}

func (s *Server) handleAgentGet(w http.ResponseWriter, r *http.Request, _ *User) {
	id := r.PathValue("id")
	rows, err := s.db.Query(agentSelect+` WHERE a.id=?`, id)
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	list, err := scanAgents(rows, time.Now())
	rows.Close()
	if err != nil || len(list) == 0 {
		httpErr(w, 404, "Gerät nicht gefunden")
		return
	}
	latest := []latestRow{}
	lr, err := s.db.Query(`SELECT name, labels, value, ts FROM latest WHERE agent_id=? ORDER BY name, labels`, id)
	if err == nil {
		for lr.Next() {
			var l latestRow
			if lr.Scan(&l.Name, &l.Labels, &l.Value, &l.TS) == nil {
				latest = append(latest, l)
			}
		}
		lr.Close()
	}
	checks := []checkRow{}
	cr, err := s.db.Query(`SELECT kind, name, ok, detail, ts FROM checks WHERE agent_id=? ORDER BY kind, name`, id)
	if err == nil {
		for cr.Next() {
			var c checkRow
			var ok int
			if cr.Scan(&c.Kind, &c.Name, &ok, &c.Detail, &c.TS) == nil {
				c.OK = ok != 0
				checks = append(checks, c)
			}
		}
		cr.Close()
	}
	alerts, _ := s.queryAlerts(`WHERE al.agent_id=? AND al.state='firing'`, 100, id)
	writeJSON(w, 200, map[string]any{"agent": list[0], "latest": latest, "checks": checks, "alerts": alerts})
}

func (s *Server) handleAgentUpdate(w http.ResponseWriter, r *http.Request, _ *User) {
	var req struct {
		DisplayName string `json:"display_name"`
		Disabled    bool   `json:"disabled"`
		TenantID    int64  `json:"tenant_id"`
	}
	if err := readJSON(r, &req, 4096); err != nil {
		httpErr(w, 400, "ungültig")
		return
	}
	name := short(req.DisplayName, 80)
	if name == "" {
		httpErr(w, 400, "Name fehlt")
		return
	}
	dis := 0
	if req.Disabled {
		dis = 1
	}
	q := `UPDATE agents SET display_name=?, disabled=? WHERE id=?`
	args := []any{name, dis, r.PathValue("id")}
	if req.TenantID > 0 {
		q = `UPDATE agents SET display_name=?, disabled=?, tenant_id=? WHERE id=?`
		args = []any{name, dis, req.TenantID, r.PathValue("id")}
	}
	if _, err := s.db.Exec(q, args...); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func (s *Server) handleAgentApprove(w http.ResponseWriter, r *http.Request, _ *User) {
	if _, err := s.db.Exec(`UPDATE agents SET approved=1 WHERE id=?`, r.PathValue("id")); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func (s *Server) handleAgentDelete(w http.ResponseWriter, r *http.Request, _ *User) {
	if _, err := s.db.Exec(`DELETE FROM agents WHERE id=?`, r.PathValue("id")); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func (s *Server) handleAgentSeries(w http.ResponseWriter, r *http.Request, _ *User) {
	id := r.PathValue("id")
	name := r.URL.Query().Get("name")
	hours := queryInt(r, "hours", 24)
	if hours < 1 || hours > 24*90 {
		hours = 24
	}
	bucket := int64(hours) * 3600 / 240
	if bucket < 60 {
		bucket = 60
	}
	since := time.Now().Unix() - int64(hours)*3600
	rows, err := s.db.Query(`SELECT labels, (ts/?)*? AS b, AVG(value), MAX(value) FROM metrics
		WHERE agent_id=? AND name=? AND ts>=? GROUP BY labels, b ORDER BY labels, b`, bucket, bucket, id, name, since)
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	defer rows.Close()
	series := map[string][][3]float64{}
	for rows.Next() {
		var lb string
		var b int64
		var avg, mx float64
		if rows.Scan(&lb, &b, &avg, &mx) == nil {
			series[lb] = append(series[lb], [3]float64{float64(b), avg, mx})
		}
	}
	writeJSON(w, 200, map[string]any{"name": name, "bucket": bucket, "series": series})
}

type logRow struct {
	ID          int64  `json:"id"`
	TS          int64  `json:"ts"`
	Channel     string `json:"channel"`
	Source      string `json:"source"`
	Level       int    `json:"level"`
	EventID     string `json:"event_id"`
	Message     string `json:"message"`
	Fingerprint string `json:"fingerprint"`
}

func (s *Server) handleAgentLogs(w http.ResponseWriter, r *http.Request, _ *User) {
	hours := queryInt(r, "hours", 24)
	maxLevel := queryInt(r, "max_level", 4)
	limit := queryInt(r, "limit", 300)
	if limit < 1 || limit > 2000 {
		limit = 300
	}
	q := `SELECT id, ts, channel, source, level, event_id, message, fingerprint FROM logs WHERE agent_id=? AND ts>=? AND level<=?`
	args := []any{r.PathValue("id"), time.Now().Unix() - int64(hours)*3600, maxLevel}
	if ch := r.URL.Query().Get("channel"); ch != "" {
		if ch == "app" {
			q += ` AND channel LIKE 'app:%'`
		} else {
			q += ` AND channel=?`
			args = append(args, ch)
		}
	}
	if txt := strings.TrimSpace(r.URL.Query().Get("q")); txt != "" {
		q += ` AND (message LIKE ? OR source LIKE ?)`
		args = append(args, "%"+txt+"%", "%"+txt+"%")
	}
	q += ` ORDER BY ts DESC, id DESC LIMIT ?`
	args = append(args, limit)
	rows, err := s.db.Query(q, args...)
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	defer rows.Close()
	out := []logRow{}
	for rows.Next() {
		var l logRow
		if rows.Scan(&l.ID, &l.TS, &l.Channel, &l.Source, &l.Level, &l.EventID, &l.Message, &l.Fingerprint) == nil {
			out = append(out, l)
		}
	}
	writeJSON(w, 200, out)
}

func (s *Server) handleMetricNames(w http.ResponseWriter, r *http.Request, _ *User) {
	rows, err := s.db.Query(`SELECT DISTINCT name FROM latest ORDER BY name`)
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	defer rows.Close()
	out := []string{}
	for rows.Next() {
		var n string
		if rows.Scan(&n) == nil {
			out = append(out, n)
		}
	}
	writeJSON(w, 200, out)
}

// ---------- Alarmregeln ----------

// Rule ist eine frei definierbare Alarmregel.
type Rule struct {
	ID           int64   `json:"id"`
	Name         string  `json:"name"`
	Enabled      bool    `json:"enabled"`
	TenantID     *int64  `json:"tenant_id"`
	AgentID      *string `json:"agent_id"`
	Kind         string  `json:"kind"` // metric | offline | log
	Metric       string  `json:"metric"`
	LabelFilter  string  `json:"label_filter"`
	Op           string  `json:"op"`
	Threshold    float64 `json:"threshold"`
	DurationMin  int     `json:"duration_min"`
	LogMaxLevel  int     `json:"log_max_level"`
	LogSourceRe  string  `json:"log_source_re"`
	LogMessageRe string  `json:"log_message_re"`
	LogCount     int     `json:"log_count"`
	Severity     string  `json:"severity"`
	Channels     []int64 `json:"channels"`
	RepeatMin    int     `json:"repeat_min"`
	CreatedAt    int64   `json:"created_at"`
	TenantName   string  `json:"tenant_name,omitempty"`
	AgentName    string  `json:"agent_name,omitempty"`
}

var ops = map[string]bool{">": true, ">=": true, "<": true, "<=": true, "==": true, "!=": true}
var severities = map[string]bool{"info": true, "warnung": true, "kritisch": true}

func (r *Rule) validate() error {
	r.Name = short(r.Name, 120)
	if r.Name == "" {
		return errors.New("Name fehlt")
	}
	switch r.Kind {
	case "metric":
		if !metricNameRe.MatchString(r.Metric) {
			return errors.New("Messwert ungültig")
		}
		if !ops[r.Op] {
			return errors.New("Vergleichsoperator ungültig")
		}
		if _, err := parseLabelFilter(r.LabelFilter); err != nil {
			return err
		}
	case "offline":
		if r.DurationMin < 1 {
			r.DurationMin = 10
		}
	case "log":
		if r.LogCount < 1 {
			r.LogCount = 1
		}
		if r.DurationMin < 1 {
			r.DurationMin = 60
		}
		if r.LogMaxLevel < 0 || r.LogMaxLevel > 7 {
			r.LogMaxLevel = 3
		}
		for _, re := range []string{r.LogSourceRe, r.LogMessageRe} {
			if _, err := regexp.Compile(re); err != nil {
				return errors.New("regulärer Ausdruck ungültig: " + err.Error())
			}
		}
	default:
		return errors.New("Regeltyp ungültig")
	}
	if !severities[r.Severity] {
		r.Severity = "warnung"
	}
	if r.DurationMin < 0 || r.DurationMin > 7*24*60 {
		return errors.New("Dauer ungültig")
	}
	if r.RepeatMin < 0 {
		r.RepeatMin = 0
	}
	if r.Channels == nil {
		r.Channels = []int64{}
	}
	if r.AgentID != nil && *r.AgentID == "" {
		r.AgentID = nil
	}
	if r.TenantID != nil && *r.TenantID <= 0 {
		r.TenantID = nil
	}
	return nil
}

const ruleSelect = `SELECT r.id, r.name, r.enabled, r.tenant_id, r.agent_id, r.kind, r.metric, r.label_filter, r.op, r.threshold,
	r.duration_min, r.log_max_level, r.log_source_re, r.log_message_re, r.log_count, r.severity, r.channels, r.repeat_min, r.created_at,
	COALESCE(t.name,''), COALESCE(a.display_name,'')
	FROM rules r LEFT JOIN tenants t ON t.id=r.tenant_id LEFT JOIN agents a ON a.id=r.agent_id`

func (s *Server) loadRules(where string, args ...any) ([]Rule, error) {
	rows, err := s.db.Query(ruleSelect+" "+where+" ORDER BY r.id", args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []Rule{}
	for rows.Next() {
		var r Rule
		var en int
		var tid sql.NullInt64
		var aid sql.NullString
		var ch string
		if err := rows.Scan(&r.ID, &r.Name, &en, &tid, &aid, &r.Kind, &r.Metric, &r.LabelFilter, &r.Op, &r.Threshold,
			&r.DurationMin, &r.LogMaxLevel, &r.LogSourceRe, &r.LogMessageRe, &r.LogCount, &r.Severity, &ch, &r.RepeatMin, &r.CreatedAt,
			&r.TenantName, &r.AgentName); err != nil {
			return nil, err
		}
		r.Enabled = en != 0
		if tid.Valid {
			r.TenantID = &tid.Int64
		}
		if aid.Valid {
			r.AgentID = &aid.String
		}
		_ = json.Unmarshal([]byte(ch), &r.Channels)
		if r.Channels == nil {
			r.Channels = []int64{}
		}
		out = append(out, r)
	}
	return out, rows.Err()
}

func (s *Server) handleRuleList(w http.ResponseWriter, r *http.Request, _ *User) {
	where, args := "", []any{}
	if t := queryInt(r, "tenant", 0); t > 0 {
		where, args = "WHERE r.tenant_id=? OR r.tenant_id IS NULL", []any{t}
	}
	rules, err := s.loadRules(where, args...)
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, rules)
}

func (s *Server) insertRule(r *Rule) (int64, error) {
	res, err := s.db.Exec(`INSERT INTO rules(name, enabled, tenant_id, agent_id, kind, metric, label_filter, op, threshold, duration_min,
		log_max_level, log_source_re, log_message_re, log_count, severity, channels, repeat_min, created_at)
		VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
		r.Name, b2i(r.Enabled), r.TenantID, r.AgentID, r.Kind, r.Metric, r.LabelFilter, r.Op, r.Threshold, r.DurationMin,
		r.LogMaxLevel, r.LogSourceRe, r.LogMessageRe, r.LogCount, r.Severity, toJSON(r.Channels), r.RepeatMin, time.Now().Unix())
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

func (s *Server) handleRuleSave(w http.ResponseWriter, r *http.Request, _ *User) {
	var rule Rule
	if err := readJSON(r, &rule, 64<<10); err != nil {
		httpErr(w, 400, "ungültig")
		return
	}
	if err := rule.validate(); err != nil {
		httpErr(w, 400, err.Error())
		return
	}
	if id, ok := pathInt(r, "id"); ok {
		_, err := s.db.Exec(`UPDATE rules SET name=?, enabled=?, tenant_id=?, agent_id=?, kind=?, metric=?, label_filter=?, op=?, threshold=?,
			duration_min=?, log_max_level=?, log_source_re=?, log_message_re=?, log_count=?, severity=?, channels=?, repeat_min=? WHERE id=?`,
			rule.Name, b2i(rule.Enabled), rule.TenantID, rule.AgentID, rule.Kind, rule.Metric, rule.LabelFilter, rule.Op, rule.Threshold,
			rule.DurationMin, rule.LogMaxLevel, rule.LogSourceRe, rule.LogMessageRe, rule.LogCount, rule.Severity, toJSON(rule.Channels), rule.RepeatMin, id)
		if err != nil {
			httpErr(w, 500, err.Error())
			return
		}
		// Offene Alarme der geänderten Regel neu bewerten lassen
		_, _ = s.db.Exec(`UPDATE alerts SET state='resolved', resolved_at=? WHERE rule_id=? AND state='firing'`, time.Now().Unix(), id)
		rule.ID = id
	} else {
		id, err := s.insertRule(&rule)
		if err != nil {
			httpErr(w, 500, err.Error())
			return
		}
		rule.ID = id
	}
	writeJSON(w, 200, rule)
}

func (s *Server) handleRuleDelete(w http.ResponseWriter, r *http.Request, _ *User) {
	id, ok := pathInt(r, "id")
	if !ok {
		httpErr(w, 400, "id")
		return
	}
	if _, err := s.db.Exec(`DELETE FROM rules WHERE id=?`, id); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func (s *Server) handleTemplateList(w http.ResponseWriter, r *http.Request, _ *User) {
	out := map[string]any{}
	for k, t := range ruleTemplates {
		out[k] = map[string]any{"title": t.Title, "description": t.Description, "rules": t.Rules}
	}
	writeJSON(w, 200, out)
}

func (s *Server) handleApplyTemplate(w http.ResponseWriter, r *http.Request, _ *User) {
	id, ok := pathInt(r, "id")
	if !ok {
		httpErr(w, 400, "id")
		return
	}
	var req struct {
		Template string  `json:"template"`
		Channels []int64 `json:"channels"`
	}
	if err := readJSON(r, &req, 4096); err != nil {
		httpErr(w, 400, "ungültig")
		return
	}
	tpl, ok := ruleTemplates[req.Template]
	if !ok {
		httpErr(w, 404, "Vorlage unbekannt")
		return
	}
	n := 0
	for _, base := range tpl.Rules {
		rule := base
		tid := id
		rule.TenantID = &tid
		rule.Enabled = true
		rule.Channels = req.Channels
		if err := rule.validate(); err != nil {
			continue
		}
		if _, err := s.insertRule(&rule); err == nil {
			n++
		}
	}
	writeJSON(w, 200, map[string]int{"created": n})
}

func b2i(b bool) int {
	if b {
		return 1
	}
	return 0
}

// ---------- Alarme ----------

// Alert ist ein ausgelöster Alarm.
type Alert struct {
	ID         int64   `json:"id"`
	RuleID     int64   `json:"rule_id"`
	RuleName   string  `json:"rule_name"`
	Severity   string  `json:"severity"`
	AgentID    string  `json:"agent_id"`
	AgentName  string  `json:"agent_name"`
	TenantID   int64   `json:"tenant_id"`
	TenantName string  `json:"tenant_name"`
	Labels     string  `json:"labels"`
	State      string  `json:"state"`
	Value      float64 `json:"value"`
	Message    string  `json:"message"`
	StartedAt  int64   `json:"started_at"`
	ResolvedAt int64   `json:"resolved_at"`
	AckedBy    string  `json:"acked_by"`
	AckedAt    int64   `json:"acked_at"`
}

func (s *Server) queryAlerts(where string, limit int, args ...any) ([]Alert, error) {
	rows, err := s.db.Query(`SELECT al.id, al.rule_id, r.name, r.severity, al.agent_id, a.display_name, a.tenant_id, t.name, al.labels,
		al.state, al.value, al.message, al.started_at, al.resolved_at, al.acked_by, al.acked_at
		FROM alerts al JOIN rules r ON r.id=al.rule_id JOIN agents a ON a.id=al.agent_id JOIN tenants t ON t.id=a.tenant_id `+where+
		` ORDER BY al.state='firing' DESC, al.started_at DESC LIMIT ?`, append(args, limit)...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := []Alert{}
	for rows.Next() {
		var a Alert
		if err := rows.Scan(&a.ID, &a.RuleID, &a.RuleName, &a.Severity, &a.AgentID, &a.AgentName, &a.TenantID, &a.TenantName, &a.Labels,
			&a.State, &a.Value, &a.Message, &a.StartedAt, &a.ResolvedAt, &a.AckedBy, &a.AckedAt); err != nil {
			return nil, err
		}
		out = append(out, a)
	}
	return out, rows.Err()
}

func (s *Server) handleAlertList(w http.ResponseWriter, r *http.Request, _ *User) {
	where, args := "WHERE 1=1", []any{}
	switch r.URL.Query().Get("state") {
	case "firing":
		where += " AND al.state='firing'"
	case "resolved":
		where += " AND al.state='resolved'"
	}
	if t := queryInt(r, "tenant", 0); t > 0 {
		where += " AND a.tenant_id=?"
		args = append(args, t)
	}
	alerts, err := s.queryAlerts(where, min(max(queryInt(r, "limit", 300), 1), 2000), args...)
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, alerts)
}

func (s *Server) handleAlertAck(w http.ResponseWriter, r *http.Request, u *User) {
	id, ok := pathInt(r, "id")
	if !ok {
		httpErr(w, 400, "id")
		return
	}
	if _, err := s.db.Exec(`UPDATE alerts SET acked_by=?, acked_at=? WHERE id=?`, u.Username, time.Now().Unix(), id); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

// ---------- Übersicht ----------

func (s *Server) handleOverview(w http.ResponseWriter, r *http.Request, _ *User) {
	now := time.Now()
	var tenants, agents, online, pending, crit, warn, info int
	_ = s.db.QueryRow(`SELECT COUNT(*) FROM tenants`).Scan(&tenants)
	_ = s.db.QueryRow(`SELECT COUNT(*) FROM agents WHERE disabled=0 AND approved=1`).Scan(&agents)
	_ = s.db.QueryRow(`SELECT COUNT(*) FROM agents WHERE approved=0`).Scan(&pending)
	_ = s.db.QueryRow(`SELECT COUNT(*) FROM agents a JOIN tenants t ON t.id=a.tenant_id
		WHERE a.disabled=0 AND a.last_seen > MAX(?, ? - 3*t.interval_sec)`, onlineCutoff(now), now.Unix()).Scan(&online)
	_ = s.db.QueryRow(`SELECT
		COALESCE(SUM(r.severity='kritisch'),0), COALESCE(SUM(r.severity='warnung'),0), COALESCE(SUM(r.severity='info'),0)
		FROM alerts al JOIN rules r ON r.id=al.rule_id WHERE al.state='firing'`).Scan(&crit, &warn, &info)
	firing, _ := s.queryAlerts(`WHERE al.state='firing'`, 50)
	rows, err := s.db.Query(agentSelect + ` WHERE a.disabled=0 ORDER BY a.last_seen ASC`)
	offline := []Agent{}
	if err == nil {
		list, _ := scanAgents(rows, now)
		rows.Close()
		for _, a := range list {
			if !a.Online && a.Approved {
				offline = append(offline, a)
			}
		}
	}
	writeJSON(w, 200, map[string]any{
		"tenants": tenants, "agents": agents, "online": online, "pending": pending,
		"alerts": map[string]int{"kritisch": crit, "warnung": warn, "info": info},
		"firing": firing, "offline": offline,
	})
}
