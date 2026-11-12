// Package proto definiert das Datenformat zwischen Agent und Server.
//
// Das Format ist bewusst schmal gehalten: Es gibt KEINE Felder für
// Benutzernamen, Prozess-Kommandozeilen, Fenstertitel, Dateinamen oder
// Dateiinhalte. Was hier nicht definiert ist, kann der Agent nicht senden
// und der Server nicht annehmen (unbekannte Felder werden verworfen).
package proto

import "time"

// EnrollRequest wird einmalig bei der Registrierung eines Geräts gesendet.
type EnrollRequest struct {
	Token        string `json:"token"`
	OS           string `json:"os"`
	Arch         string `json:"arch"`
	AgentVersion string `json:"agent_version"`
	// DisplayName ist optional. Standardmäßig sendet der Agent KEINEN
	// Hostnamen (kann Personennamen enthalten, z.B. "Laptop-Mueller").
	DisplayName string `json:"display_name,omitempty"`
}

// EnrollResponse enthält die Zugangsdaten des Agenten.
type EnrollResponse struct {
	AgentID  string `json:"agent_id"`
	AgentKey string `json:"agent_key"`
}

// Report ist eine periodische Meldung eines Agenten.
type Report struct {
	AgentVersion string        `json:"agent_version"`
	Time         time.Time     `json:"time"`
	System       SystemInfo    `json:"system"`
	Metrics      []Metric      `json:"metrics"`
	Logs         []LogEntry    `json:"logs"`
	Checks       []CheckResult `json:"checks"`
}

// SystemInfo beschreibt das Betriebssystem (keine Benutzer-/Hostdaten).
type SystemInfo struct {
	OS              string `json:"os"`
	Platform        string `json:"platform"`
	PlatformVersion string `json:"platform_version"`
	KernelVersion   string `json:"kernel_version"`
	Arch            string `json:"arch"`
	UptimeSeconds   uint64 `json:"uptime_seconds"`
}

// Metric ist ein numerischer Messwert. Labels sind z.B. {"mount":"C:"}.
type Metric struct {
	Name   string            `json:"name"`
	Value  float64           `json:"value"`
	Labels map[string]string `json:"labels,omitempty"`
}

// LogEntry ist ein bereinigter Log-Eintrag.
//
// Level folgt syslog: 0=Notfall 1=Alarm 2=Kritisch 3=Fehler 4=Warnung
// 5=Hinweis 6=Info 7=Debug.
type LogEntry struct {
	Time        time.Time `json:"time"`
	Source      string    `json:"source"`
	Channel     string    `json:"channel"` // system | app:<name>
	Level       int       `json:"level"`
	EventID     string    `json:"event_id,omitempty"`
	Message     string    `json:"message,omitempty"`
	Fingerprint string    `json:"fingerprint"`
}

// CheckResult ist das Ergebnis einer Prüfung (Prozess/Dienst läuft?).
type CheckResult struct {
	Kind   string `json:"kind"` // process | service
	Name   string `json:"name"`
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`
}

// ReportResponse liefert dem Agenten zentrale Vorgaben zurück.
//
// Bewusst NICHT enthalten: Befehle, Skripte, Dateipfade. Der Server kann
// den Agenten nicht anweisen, Dateien zu lesen oder Programme auszuführen.
// Er kann nur Namen von Prozessen/Diensten vorgeben, deren Laufzustand
// geprüft werden soll.
type ReportResponse struct {
	IntervalSeconds int      `json:"interval_seconds,omitempty"`
	WatchProcesses  []string `json:"watch_processes,omitempty"`
	WatchServices   []string `json:"watch_services,omitempty"`
}

// Grenzwerte, die Agent und Server gleichermaßen durchsetzen.
const (
	MaxMessageLen     = 400
	MaxLogsPerReport  = 500
	MaxMetricsPerRep  = 500
	MaxChecksPerRep   = 200
	MaxLabelValueLen  = 64
	MaxNameLen        = 80
	MaxReportBodySize = 4 << 20
)
