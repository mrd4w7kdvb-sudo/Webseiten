// Package agent enthält die lokale Sensor-Software, die auf den Geräten der
// Kunden läuft und Systemzustand sowie bereinigte Logs an den Server meldet.
package agent

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
)

// Version des Agenten (wird beim Bauen per -ldflags gesetzt).
var Version = "1.0.0"

// AppLog beschreibt eine Log-Datei einer Anwendung (z.B. Pflege- oder
// Hofsoftware). Nur hier lokal eingetragene Dateien werden gelesen – der
// Server kann keine weiteren Dateien anfordern.
type AppLog struct {
	Name string `json:"name"`
	// Path darf Platzhalter enthalten, z.B. C:\Programme\Hersteller\Logs\*.log
	Path string `json:"path"`
	// Mode: "metadata" (Standard, kein Text) oder "redact" (bereinigter Text).
	Mode string `json:"mode"`
	// MaxLevel: nur Zeilen bis zu diesem Schweregrad (4 = Warnung, 3 = Fehler).
	MaxLevel int `json:"max_level"`
}

// Privacy enthält die Datenschutzeinstellungen. Sie werden AUSSCHLIESSLICH
// lokal festgelegt und können vom Server nicht geändert werden.
type Privacy struct {
	// SendHostname: Rechnername bei Registrierung übertragen (Standard: nein).
	SendHostname bool `json:"send_hostname"`
	// SystemLogMode: "redact" (Standard), "metadata" oder "off".
	SystemLogMode string `json:"system_log_mode"`
	// SystemLogMaxLevel: 4 = Warnungen und schwerer (Standard), 3 = nur Fehler.
	SystemLogMaxLevel int `json:"system_log_max_level"`
	// DenyWords werden immer entfernt (z.B. Namen von Klienten/Höfen).
	DenyWords []string `json:"deny_words"`
	// DenyPatterns: zusätzliche reguläre Ausdrücke, die entfernt werden.
	DenyPatterns []string `json:"deny_patterns"`
	// AuditFile: letzte gesendete Meldung lokal zur Kontrolle speichern.
	AuditFile bool `json:"audit_file"`
}

// Config ist die lokale Agent-Konfiguration.
type Config struct {
	Server          string `json:"server"`
	AgentID         string `json:"agent_id"`
	AgentKey        string `json:"agent_key"`
	FingerprintSalt string `json:"fingerprint_salt"`
	IntervalSeconds int    `json:"interval_seconds"`
	// CAFile: optionale PEM-Datei mit eigener Zertifizierungsstelle. Ist sie
	// gesetzt, wird AUSSCHLIESSLICH diesen Zertifikaten vertraut (Pinning).
	// Eine Möglichkeit, die Zertifikatsprüfung abzuschalten, gibt es bewusst nicht.
	CAFile string `json:"ca_file,omitempty"`
	// Proxy: optionaler HTTP(S)-Proxy, z.B. http://proxy.firma.local:3128
	Proxy          string   `json:"proxy,omitempty"`
	AllowHTTP      bool     `json:"allow_http"`
	Privacy        Privacy  `json:"privacy"`
	WatchProcesses []string `json:"watch_processes"`
	WatchServices  []string `json:"watch_services"`
	AppLogs        []AppLog `json:"app_logs"`
}

// DefaultConfig liefert sichere Voreinstellungen.
func DefaultConfig() *Config {
	return &Config{
		IntervalSeconds: 60,
		Privacy: Privacy{
			SendHostname:      false,
			SystemLogMode:     "redact",
			SystemLogMaxLevel: 4,
			DenyWords:         []string{},
			DenyPatterns:      []string{},
			AuditFile:         true,
		},
		WatchProcesses: []string{},
		WatchServices:  []string{},
		AppLogs:        []AppLog{},
	}
}

// ConfigPath liefert den Pfad der Konfigurationsdatei.
func ConfigPath() string {
	if p := os.Getenv("GW_AGENT_CONFIG"); p != "" {
		return p
	}
	return filepath.Join(baseConfigDir(), "agent.json")
}

// DataDir liefert das Arbeitsverzeichnis (Status, Warteschlange, Kontrolldatei).
func DataDir() string {
	if p := os.Getenv("GW_AGENT_DATA"); p != "" {
		return p
	}
	return baseDataDir()
}

// LoadConfig liest die Konfiguration und ergänzt fehlende Werte.
func LoadConfig() (*Config, error) {
	c := DefaultConfig()
	b, err := os.ReadFile(ConfigPath())
	if err != nil {
		return nil, err
	}
	if err := json.Unmarshal(b, c); err != nil {
		return nil, errors.New("Konfiguration fehlerhaft: " + err.Error())
	}
	if c.IntervalSeconds < 15 {
		c.IntervalSeconds = 60
	}
	if c.Privacy.SystemLogMaxLevel <= 0 || c.Privacy.SystemLogMaxLevel > 6 {
		c.Privacy.SystemLogMaxLevel = 4
	}
	m := strings.ToLower(c.Privacy.SystemLogMode)
	if m != "redact" && m != "metadata" && m != "off" {
		c.Privacy.SystemLogMode = "metadata"
	}
	for i := range c.AppLogs {
		if c.AppLogs[i].MaxLevel <= 0 || c.AppLogs[i].MaxLevel > 6 {
			c.AppLogs[i].MaxLevel = 4
		}
	}
	if c.FingerprintSalt == "" {
		c.FingerprintSalt = randHex(16)
		_ = c.Save()
	}
	return c, nil
}

// Save schreibt die Konfiguration mit restriktiven Rechten.
func (c *Config) Save() error {
	p := ConfigPath()
	if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
		return err
	}
	b, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	tmp := p + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	if err := os.Rename(tmp, p); err != nil {
		return err
	}
	return restrictPermissions(filepath.Dir(p))
}

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}
