package server

import (
	"database/sql"
	"errors"
	"fmt"
	"log"
	"regexp"
	"strings"
	"time"
)

// parseLabelFilter liest Filter wie "mount=C:,name=MediFox.exe".
// Ein Wert "*" oder ein fehlender Filter passt auf alles.
func parseLabelFilter(f string) (map[string]string, error) {
	out := map[string]string{}
	f = strings.TrimSpace(f)
	if f == "" {
		return out, nil
	}
	for _, part := range strings.Split(f, ",") {
		k, v, ok := strings.Cut(strings.TrimSpace(part), "=")
		if !ok || !labelKeyRe.MatchString(strings.TrimSpace(k)) {
			return nil, errors.New("Label-Filter ungültig (Format: schluessel=wert,schluessel2=wert2)")
		}
		out[strings.TrimSpace(k)] = strings.TrimSpace(v)
	}
	return out, nil
}

func labelsMatch(encoded string, filter map[string]string) bool {
	if len(filter) == 0 {
		return true
	}
	have := map[string]string{}
	for _, p := range strings.Split(encoded, ",") {
		if k, v, ok := strings.Cut(p, "="); ok {
			have[k] = v
		}
	}
	for k, want := range filter {
		got, ok := have[k]
		if !ok {
			return false
		}
		if want != "*" && !strings.EqualFold(got, want) {
			return false
		}
	}
	return true
}

func compare(v float64, op string, t float64) bool {
	switch op {
	case ">":
		return v > t
	case ">=":
		return v >= t
	case "<":
		return v < t
	case "<=":
		return v <= t
	case "==":
		return v == t
	case "!=":
		return v != t
	}
	return false
}

type scopeAgent struct {
	id       string
	name     string
	tenant   string
	lastSeen int64
	interval int64
}

func (s *Server) agentsInScope(r *Rule) ([]scopeAgent, error) {
	q := `SELECT a.id, a.display_name, t.name, a.last_seen, t.interval_sec FROM agents a JOIN tenants t ON t.id=a.tenant_id WHERE a.disabled=0 AND a.approved=1`
	args := []any{}
	if r.AgentID != nil {
		q += ` AND a.id=?`
		args = append(args, *r.AgentID)
	} else if r.TenantID != nil {
		q += ` AND a.tenant_id=?`
		args = append(args, *r.TenantID)
	}
	rows, err := s.db.Query(q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []scopeAgent
	for rows.Next() {
		var a scopeAgent
		if err := rows.Scan(&a.id, &a.name, &a.tenant, &a.lastSeen, &a.interval); err != nil {
			return nil, err
		}
		out = append(out, a)
	}
	return out, rows.Err()
}

type condition struct {
	labels string
	value  float64
	msg    string
}

// evaluate prüft alle aktiven Regeln und löst Alarme aus bzw. hebt sie auf.
func (s *Server) evaluate(now time.Time) {
	s.mu.Lock()
	defer s.mu.Unlock()
	rules, err := s.loadRules("WHERE r.enabled=1")
	if err != nil {
		log.Printf("Alarmauswertung: %v", err)
		return
	}
	for i := range rules {
		if err := s.evaluateRule(&rules[i], now); err != nil {
			log.Printf("Regel %d (%s): %v", rules[i].ID, rules[i].Name, err)
		}
	}
}

func (s *Server) evaluateRule(r *Rule, now time.Time) error {
	agents, err := s.agentsInScope(r)
	if err != nil {
		return err
	}
	inScope := map[string]bool{}
	for _, a := range agents {
		inScope[a.id] = true
		conds, err := s.conditions(r, a, now)
		if err != nil {
			return err
		}
		if err := s.reconcile(r, a, conds, now); err != nil {
			return err
		}
	}
	// Alarme für Geräte außerhalb des Geltungsbereichs (gelöscht/deaktiviert) beenden
	open, err := s.openAlerts(r.ID, "")
	if err != nil {
		return err
	}
	for _, o := range open {
		if !inScope[o.agentID] {
			_, _ = s.db.Exec(`UPDATE alerts SET state='resolved', resolved_at=? WHERE id=?`, now.Unix(), o.id)
		}
	}
	return nil
}

func (s *Server) conditions(r *Rule, a scopeAgent, now time.Time) ([]condition, error) {
	ts := now.Unix()
	switch r.Kind {
	case "offline":
		if a.lastSeen == 0 {
			return nil, nil // noch nie gemeldet: kein Offline-Alarm
		}
		// Nach einem Server-Neustart erst ab Startzeitpunkt zählen, sonst
		// gälten nach einer Server-Wartung kurz alle Geräte als offline.
		silent := ts - max(a.lastSeen, s.started.Unix())
		if silent >= int64(r.DurationMin)*60 {
			return []condition{{value: float64(silent / 60), msg: fmt.Sprintf("Gerät meldet sich seit %d Minuten nicht", silent/60)}}, nil
		}
		return nil, nil

	case "metric":
		filter, _ := parseLabelFilter(r.LabelFilter)
		window := int64(r.DurationMin) * 60
		// Ohne Dauer: nur den jüngsten Wert betrachten, sofern er aktuell ist.
		freshness := max(3*a.interval, 180)
		if window == 0 {
			rows, err := s.db.Query(`SELECT labels, value FROM latest WHERE agent_id=? AND name=? AND ts>=?`, a.id, r.Metric, ts-freshness)
			if err != nil {
				return nil, err
			}
			defer rows.Close()
			var out []condition
			for rows.Next() {
				var lb string
				var v float64
				if err := rows.Scan(&lb, &v); err != nil {
					return nil, err
				}
				if labelsMatch(lb, filter) && compare(v, r.Op, r.Threshold) {
					out = append(out, condition{labels: lb, value: v, msg: metricMsg(r, lb, v, 0)})
				}
			}
			return out, rows.Err()
		}
		// Mit Dauer: alle Werte im Zeitfenster müssen die Bedingung erfüllen,
		// und die Werte müssen das Fenster weitgehend abdecken.
		rows, err := s.db.Query(`SELECT labels, COUNT(*), MIN(ts), MAX(ts),
			SUM(CASE `+sqlCompare(r.Op)+` THEN 1 ELSE 0 END), AVG(value)
			FROM metrics WHERE agent_id=? AND name=? AND ts>=? GROUP BY labels`, r.Threshold, a.id, r.Metric, ts-window)
		if err != nil {
			return nil, err
		}
		defer rows.Close()
		var out []condition
		for rows.Next() {
			var lb string
			var n, minTS, maxTS, hits int64
			var avg float64
			if err := rows.Scan(&lb, &n, &minTS, &maxTS, &hits, &avg); err != nil {
				return nil, err
			}
			if !labelsMatch(lb, filter) || n == 0 || hits != n {
				continue
			}
			covered := maxTS-minTS >= window-2*a.interval-30
			fresh := maxTS >= ts-freshness
			if covered && fresh {
				out = append(out, condition{labels: lb, value: avg, msg: metricMsg(r, lb, avg, r.DurationMin)})
			}
		}
		return out, rows.Err()

	case "log":
		window := int64(r.DurationMin) * 60
		rows, err := s.db.Query(`SELECT source, message, channel FROM logs WHERE agent_id=? AND ts>=? AND level<=? ORDER BY ts DESC LIMIT 5000`,
			a.id, ts-window, r.LogMaxLevel)
		if err != nil {
			return nil, err
		}
		defer rows.Close()
		srcRe, _ := regexp.Compile("(?i)" + r.LogSourceRe)
		msgRe, _ := regexp.Compile("(?i)" + r.LogMessageRe)
		n := 0
		lastSrc := ""
		for rows.Next() {
			var src, msg, ch string
			if err := rows.Scan(&src, &msg, &ch); err != nil {
				return nil, err
			}
			if r.LogSourceRe != "" && !srcRe.MatchString(src) && !srcRe.MatchString(ch) {
				continue
			}
			if r.LogMessageRe != "" && !msgRe.MatchString(msg) {
				continue
			}
			if lastSrc == "" {
				lastSrc = src
			}
			n++
		}
		if n >= r.LogCount {
			return []condition{{value: float64(n), msg: fmt.Sprintf("%d passende Log-Einträge in %d Minuten (zuletzt von %s)", n, r.DurationMin, lastSrc)}}, rows.Err()
		}
		return nil, rows.Err()
	}
	return nil, nil
}

func sqlCompare(op string) string {
	if !ops[op] {
		op = ">"
	}
	if op == "==" {
		op = "="
	}
	return "WHEN value " + op + " ?"
}

func metricMsg(r *Rule, labels string, v float64, dur int) string {
	l := ""
	if labels != "" {
		l = " [" + labels + "]"
	}
	d := ""
	if dur > 0 {
		d = fmt.Sprintf(" seit %d Min.", dur)
	}
	return fmt.Sprintf("%s%s = %s (Schwelle %s %s)%s", r.Metric, l, fmtNum(v), r.Op, fmtNum(r.Threshold), d)
}

func fmtNum(v float64) string {
	if v == float64(int64(v)) {
		return fmt.Sprintf("%d", int64(v))
	}
	return fmt.Sprintf("%.1f", v)
}

type openAlert struct {
	id           int64
	agentID      string
	labels       string
	lastNotified int64
}

func (s *Server) openAlerts(ruleID int64, agentID string) ([]openAlert, error) {
	q := `SELECT id, agent_id, labels, last_notified FROM alerts WHERE rule_id=? AND state='firing'`
	args := []any{ruleID}
	if agentID != "" {
		q += ` AND agent_id=?`
		args = append(args, agentID)
	}
	rows, err := s.db.Query(q, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var out []openAlert
	for rows.Next() {
		var o openAlert
		if err := rows.Scan(&o.id, &o.agentID, &o.labels, &o.lastNotified); err != nil {
			return nil, err
		}
		out = append(out, o)
	}
	return out, rows.Err()
}

func (s *Server) reconcile(r *Rule, a scopeAgent, conds []condition, now time.Time) error {
	open, err := s.openAlerts(r.ID, a.id)
	if err != nil {
		return err
	}
	byLabels := map[string]openAlert{}
	for _, o := range open {
		byLabels[o.labels] = o
	}
	active := map[string]bool{}
	ts := now.Unix()
	for _, c := range conds {
		active[c.labels] = true
		if o, ok := byLabels[c.labels]; ok {
			_, _ = s.db.Exec(`UPDATE alerts SET value=?, message=? WHERE id=?`, c.value, c.msg, o.id)
			if r.RepeatMin > 0 && ts-o.lastNotified >= int64(r.RepeatMin)*60 {
				s.notify(r, a, c.msg, c.labels, "erinnerung")
				_, _ = s.db.Exec(`UPDATE alerts SET last_notified=? WHERE id=?`, ts, o.id)
			}
			continue
		}
		if _, err := s.db.Exec(`INSERT INTO alerts(rule_id, agent_id, labels, state, value, message, started_at, last_notified)
			VALUES(?,?,?,'firing',?,?,?,?)`, r.ID, a.id, c.labels, c.value, c.msg, ts, ts); err != nil {
			return err
		}
		s.notify(r, a, c.msg, c.labels, "ausgelöst")
	}
	for lb, o := range byLabels {
		if active[lb] {
			continue
		}
		if _, err := s.db.Exec(`UPDATE alerts SET state='resolved', resolved_at=? WHERE id=?`, ts, o.id); err != nil {
			return err
		}
		s.notify(r, a, "Bedingung nicht mehr erfüllt", lb, "behoben")
	}
	return nil
}

func (s *Server) notify(r *Rule, a scopeAgent, msg, labels, event string) {
	if len(r.Channels) == 0 {
		return
	}
	n := Notification{
		Event: event, Severity: r.Severity, Rule: r.Name, Tenant: a.tenant, Device: a.name,
		DeviceID: a.id, Labels: labels, Message: msg, Time: time.Now(),
	}
	go s.notifier.Send(r.Channels, n)
}

var _ = sql.ErrNoRows

// ---------- Vorlagen ----------

type ruleTemplate struct {
	Title       string
	Description string
	Rules       []Rule
}

func mr(name, metric, filter, op string, th float64, dur int, sev string) Rule {
	return Rule{Name: name, Kind: "metric", Metric: metric, LabelFilter: filter, Op: op, Threshold: th, DurationMin: dur, Severity: sev, RepeatMin: 0}
}

func lr(name string, maxLevel int, srcRe, msgRe string, count, dur int, sev string) Rule {
	return Rule{Name: name, Kind: "log", LogMaxLevel: maxLevel, LogSourceRe: srcRe, LogMessageRe: msgRe, LogCount: count, DurationMin: dur, Severity: sev}
}

var baseRules = []Rule{
	{Name: "Gerät nicht erreichbar", Kind: "offline", DurationMin: 15, Severity: "kritisch", RepeatMin: 240},
	mr("Prozessor dauerhaft ausgelastet", "cpu.percent", "", ">", 90, 15, "warnung"),
	mr("Arbeitsspeicher knapp", "mem.percent", "", ">", 92, 10, "warnung"),
	mr("Festplatte fast voll", "disk.percent", "", ">", 90, 0, "warnung"),
	mr("Festplatte voll", "disk.percent", "", ">", 97, 0, "kritisch"),
	mr("Gerät wurde neu gestartet", "uptime.seconds", "", "<", 600, 0, "info"),
	lr("Gehäufte Systemfehler", 3, "", "", 10, 60, "warnung"),
	lr("Kritisches Systemereignis", 2, "", "", 1, 15, "kritisch"),
	lr("Festplatten-/Dateisystemfehler", 4, "", `(?i)(i/o error|disk|ntfs|ext4-fs error|smart|bad block|fehlerhafter block|volsnap)`, 1, 60, "kritisch"),
}

var ruleTemplates = map[string]ruleTemplate{
	"basis": {
		Title:       "Basis (alle Geräte)",
		Description: "Erreichbarkeit, Auslastung, Speicherplatz, Systemfehler",
		Rules:       baseRules,
	},
	"pflege": {
		Title:       "Mobiler Pflegedienst",
		Description: "Basis + Pflegesoftware, Akku mobiler Geräte, Synchronisation, Backup",
		Rules: append(append([]Rule{}, baseRules...),
			mr("Überwachte Software läuft nicht", "process.running", "name=*", "==", 0, 10, "kritisch"),
			mr("Überwachter Dienst gestoppt", "service.running", "name=*", "==", 0, 5, "kritisch"),
			mr("Akku fast leer (Tourengerät)", "battery.percent", "", "<", 15, 0, "warnung"),
			lr("Synchronisationsfehler Pflegesoftware", 4, "^app:", `(?i)(sync|synchron|abgleich|übertragung|upload|verbindung)`, 3, 60, "warnung"),
			lr("Backup fehlgeschlagen", 3, "", `(?i)(backup|sicherung|datensicherung)`, 1, 1440, "kritisch"),
			Rule{Name: "Tourengerät länger offline", Kind: "offline", DurationMin: 480, Severity: "warnung", RepeatMin: 1440},
		),
	},
	"landwirtschaft": {
		Title:       "Landwirtschaft / Hof",
		Description: "Basis + Hof-/Steuerungsrechner, Dienste, Temperatur im Stall, Speicher für Kameras",
		Rules: append(append([]Rule{}, baseRules...),
			Rule{Name: "Hofrechner/Steuerung nicht erreichbar", Kind: "offline", DurationMin: 5, Severity: "kritisch", RepeatMin: 60},
			mr("Herdenmanagement-/Steuerungssoftware läuft nicht", "process.running", "name=*", "==", 0, 5, "kritisch"),
			mr("Steuerungsdienst gestoppt", "service.running", "name=*", "==", 0, 3, "kritisch"),
			mr("Gerät zu heiß (Stall/Technikraum)", "temp.cpu", "", ">", 80, 10, "warnung"),
			mr("Speicher für Kamera-/Aufzeichnungsdaten knapp", "disk.percent", "", ">", 85, 30, "warnung"),
			lr("Netzwerk-/Verbindungsabbrüche", 4, "", `(?i)(network|netzwerk|link down|disconnected|getrennt|timeout|dns)`, 5, 60, "warnung"),
			lr("Fehler in Hof-Software", 3, "^app:", "", 3, 60, "warnung"),
		),
	},
}
