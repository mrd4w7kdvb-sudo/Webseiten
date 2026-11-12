// Package server enthält den zentralen Monitoring-Server.
package server

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	"geraetewaechter/internal/privacy"
)

// Config wird aus Umgebungsvariablen gelesen.
type Config struct {
	Listen           string
	DataDir          string
	PublicURL        string
	DownloadsDir     string
	AdminUser        string
	AdminPassword    string
	MetricRetention  int // Tage
	LogRetention     int // Tage
	AlertRetention   int // Tage
	CookieSecure     bool
	EvaluateInterval time.Duration
	SkipBootstrap    bool // kein automatischer Erst-Admin (CLI)
}

// ConfigFromEnv liest die Konfiguration.
func ConfigFromEnv() Config {
	c := Config{
		Listen:           env("GW_LISTEN", ":8080"),
		DataDir:          env("GW_DATA_DIR", "./data"),
		PublicURL:        strings.TrimRight(env("GW_PUBLIC_URL", "http://localhost:8080"), "/"),
		DownloadsDir:     env("GW_DOWNLOADS_DIR", "./downloads"),
		AdminUser:        env("GW_ADMIN_USER", "admin"),
		AdminPassword:    os.Getenv("GW_ADMIN_PASSWORD"),
		MetricRetention:  envInt("GW_METRIC_RETENTION_DAYS", 30),
		LogRetention:     envInt("GW_LOG_RETENTION_DAYS", 14),
		AlertRetention:   envInt("GW_ALERT_RETENTION_DAYS", 180),
		CookieSecure:     env("GW_COOKIE_SECURE", "true") != "false",
		EvaluateInterval: time.Duration(envInt("GW_EVALUATE_SECONDS", 30)) * time.Second,
	}
	return c
}

func env(k, def string) string {
	if v := strings.TrimSpace(os.Getenv(k)); v != "" {
		return v
	}
	return def
}

func envInt(k string, def int) int {
	if v, err := strconv.Atoi(os.Getenv(k)); err == nil && v > 0 {
		return v
	}
	return def
}

// Server ist die laufende Instanz.
type Server struct {
	cfg      Config
	db       *sql.DB
	san      *privacy.Sanitizer
	notifier *Notifier
	limiter  *loginLimiter
	mu       sync.Mutex // serialisiert die Alarmauswertung
}

// New öffnet die Datenbank und legt ggf. den ersten Admin an.
func New(cfg Config) (*Server, error) {
	if err := os.MkdirAll(cfg.DataDir, 0o700); err != nil {
		return nil, err
	}
	db, err := openDB(filepath.Join(cfg.DataDir, "geraetewaechter.db"))
	if err != nil {
		return nil, err
	}
	s := &Server{
		cfg:     cfg,
		db:      db,
		san:     privacy.MustNew(privacy.Options{}),
		limiter: newLoginLimiter(),
	}
	s.notifier = &Notifier{db: db, publicURL: cfg.PublicURL}
	if cfg.SkipBootstrap {
		return s, nil
	}
	if err := s.bootstrapAdmin(); err != nil {
		return nil, err
	}
	return s, nil
}

// DB gibt die Datenbank zurück (für CLI-Befehle).
func (s *Server) DB() *sql.DB { return s.db }

// Run startet HTTP-Server und Hintergrundaufgaben.
func (s *Server) Run(ctx context.Context) error {
	srv := &http.Server{
		Addr:              s.cfg.Listen,
		Handler:           s.routes(),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       60 * time.Second,
		WriteTimeout:      60 * time.Second,
		IdleTimeout:       120 * time.Second,
		// Kein Zugriffslog: IP-Adressen der Kunden werden nicht protokolliert.
		ErrorLog: log.New(io.Discard, "", 0),
	}
	go s.loop(ctx)
	errc := make(chan error, 1)
	go func() {
		log.Printf("Gerätewächter-Server lauscht auf %s (öffentliche URL %s)", s.cfg.Listen, s.cfg.PublicURL)
		errc <- srv.ListenAndServe()
	}()
	select {
	case <-ctx.Done():
		sh, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = srv.Shutdown(sh)
		return s.db.Close()
	case err := <-errc:
		if errors.Is(err, http.ErrServerClosed) {
			return nil
		}
		return err
	}
}

func (s *Server) loop(ctx context.Context) {
	eval := time.NewTicker(s.cfg.EvaluateInterval)
	clean := time.NewTicker(time.Hour)
	defer eval.Stop()
	defer clean.Stop()
	s.cleanup()
	for {
		select {
		case <-ctx.Done():
			return
		case <-eval.C:
			s.evaluate(time.Now())
		case <-clean.C:
			s.cleanup()
		}
	}
}

// cleanup setzt die Speicherfristen durch (Speicherbegrenzung, DSGVO Art. 5 e).
func (s *Server) cleanup() {
	now := time.Now()
	day := int64(24 * 3600)
	exec := func(q string, args ...any) {
		if _, err := s.db.Exec(q, args...); err != nil {
			log.Printf("Bereinigung: %v", err)
		}
	}
	exec(`DELETE FROM metrics WHERE ts < ?`, now.Unix()-int64(s.cfg.MetricRetention)*day)
	exec(`DELETE FROM logs WHERE received < ?`, now.Unix()-int64(s.cfg.LogRetention)*day)
	exec(`DELETE FROM alerts WHERE state='resolved' AND resolved_at < ?`, now.Unix()-int64(s.cfg.AlertRetention)*day)
	exec(`DELETE FROM sessions WHERE expires < ?`, now.Unix())
}

func (s *Server) routes() http.Handler {
	mux := http.NewServeMux()

	// Agenten-Schnittstelle
	mux.HandleFunc("POST /agent/v1/enroll", s.handleEnroll)
	mux.HandleFunc("POST /agent/v1/report", s.handleReport)

	// Öffentliche Downloads & Installationsskripte
	mux.HandleFunc("GET /downloads/{file}", s.handleDownload)
	mux.HandleFunc("GET /install.sh", s.handleInstallScript)
	mux.HandleFunc("GET /install.ps1", s.handleInstallScript)
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte("ok")) })

	// Anmeldung
	mux.HandleFunc("POST /api/login", s.handleLogin)
	mux.HandleFunc("POST /api/logout", s.handleLogout)

	// Admin-API (Sitzung erforderlich)
	a := func(pattern string, h func(http.ResponseWriter, *http.Request, *User)) {
		mux.Handle(pattern, s.requireUser(h))
	}
	a("GET /api/me", s.handleMe)
	a("POST /api/password", s.handlePassword)
	a("GET /api/overview", s.handleOverview)

	a("GET /api/tenants", s.handleTenantList)
	a("POST /api/tenants", s.handleTenantSave)
	a("GET /api/tenants/{id}", s.handleTenantGet)
	a("PUT /api/tenants/{id}", s.handleTenantSave)
	a("DELETE /api/tenants/{id}", s.handleTenantDelete)
	a("POST /api/tenants/{id}/rotate-token", s.handleTenantRotate)
	a("POST /api/tenants/{id}/apply-template", s.handleApplyTemplate)

	a("GET /api/agents", s.handleAgentList)
	a("GET /api/agents/{id}", s.handleAgentGet)
	a("PUT /api/agents/{id}", s.handleAgentUpdate)
	a("DELETE /api/agents/{id}", s.handleAgentDelete)
	a("GET /api/agents/{id}/series", s.handleAgentSeries)
	a("GET /api/agents/{id}/logs", s.handleAgentLogs)

	a("GET /api/metric-names", s.handleMetricNames)
	a("GET /api/rules", s.handleRuleList)
	a("POST /api/rules", s.handleRuleSave)
	a("PUT /api/rules/{id}", s.handleRuleSave)
	a("DELETE /api/rules/{id}", s.handleRuleDelete)
	a("GET /api/templates", s.handleTemplateList)

	a("GET /api/alerts", s.handleAlertList)
	a("POST /api/alerts/{id}/ack", s.handleAlertAck)

	a("GET /api/channels", s.handleChannelList)
	a("POST /api/channels", s.handleChannelSave)
	a("PUT /api/channels/{id}", s.handleChannelSave)
	a("DELETE /api/channels/{id}", s.handleChannelDelete)
	a("POST /api/channels/{id}/test", s.handleChannelTest)

	// Weboberfläche
	mux.Handle("GET /", s.webHandler())

	return securityHeaders(mux)
}

func securityHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := w.Header()
		h.Set("X-Content-Type-Options", "nosniff")
		h.Set("X-Frame-Options", "DENY")
		h.Set("Referrer-Policy", "no-referrer")
		h.Set("Content-Security-Policy", "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'")
		h.Set("Permissions-Policy", "geolocation=(), camera=(), microphone=()")
		next.ServeHTTP(w, r)
	})
}

// ---------- Hilfsfunktionen ----------

func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func httpErr(w http.ResponseWriter, code int, msg string) {
	writeJSON(w, code, map[string]string{"error": msg})
}

func readJSON(r *http.Request, v any, limit int64) error {
	dec := json.NewDecoder(io.LimitReader(r.Body, limit))
	return dec.Decode(v)
}

func pathInt(r *http.Request, name string) (int64, bool) {
	v, err := strconv.ParseInt(r.PathValue(name), 10, 64)
	return v, err == nil && v > 0
}

func queryInt(r *http.Request, name string, def int) int {
	if v, err := strconv.Atoi(r.URL.Query().Get(name)); err == nil {
		return v
	}
	return def
}

func jsonList(s string) []string {
	var out []string
	_ = json.Unmarshal([]byte(s), &out)
	if out == nil {
		out = []string{}
	}
	return out
}

func toJSON(v any) string {
	b, _ := json.Marshal(v)
	return string(b)
}

func cleanList(in []string, maxItems, maxLen int) []string {
	out := []string{}
	seen := map[string]bool{}
	for _, v := range in {
		v = strings.TrimSpace(v)
		if v == "" || len(v) > maxLen || seen[strings.ToLower(v)] {
			continue
		}
		seen[strings.ToLower(v)] = true
		out = append(out, v)
		if len(out) >= maxItems {
			break
		}
	}
	return out
}
