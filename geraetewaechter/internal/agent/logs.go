package agent

import (
	"bufio"
	"bytes"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"geraetewaechter/internal/privacy"
	"geraetewaechter/internal/proto"
)

type rawLog struct {
	Time    time.Time
	Source  string
	Level   int
	EventID string
	Message string
}

// state wird zwischen den Läufen gespeichert, damit Logs nicht doppelt
// gemeldet werden.
type state struct {
	JournalCursor  string           `json:"journal_cursor,omitempty"`
	LastLogTime    time.Time        `json:"last_log_time"`
	FileOffsets    map[string]int64 `json:"file_offsets"`
	AppInitialized map[string]bool  `json:"app_initialized"`
	ServerProcs    []string         `json:"server_watch_processes"`
	ServerServices []string         `json:"server_watch_services"`
	ServerInterval int              `json:"server_interval"`
}

func statePath() string { return filepath.Join(DataDir(), "state.json") }

func loadState() *state {
	st := &state{}
	if b, err := os.ReadFile(statePath()); err == nil {
		_ = json.Unmarshal(b, st)
	}
	if st.FileOffsets == nil {
		st.FileOffsets = map[string]int64{}
	}
	if st.AppInitialized == nil {
		st.AppInitialized = map[string]bool{}
	}
	return st
}

func (st *state) save() error {
	if err := os.MkdirAll(DataDir(), 0o700); err != nil {
		return err
	}
	b, _ := json.MarshalIndent(st, "", "  ")
	tmp := statePath() + ".tmp"
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, statePath())
}

// toEntry wendet den Datenschutzmodus an. Der Originaltext verlässt diese
// Funktion nur bereinigt (redact) oder gar nicht (metadata).
func toEntry(r rawLog, channel string, mode privacy.Mode, san *privacy.Sanitizer, salt string) proto.LogEntry {
	src := san.Label(r.Source)
	e := proto.LogEntry{
		Time:        r.Time,
		Source:      src,
		Channel:     channel,
		Level:       r.Level,
		EventID:     r.EventID,
		Fingerprint: privacy.Fingerprint(salt, src, r.Message),
	}
	if mode == privacy.ModeRedact {
		e.Message = san.Text(r.Message)
	}
	return e
}

var (
	lvlFatal = regexp.MustCompile(`(?i)\b(fatal|critical|crit|kritisch|emerg|emergency|alert|panic|schwerwiegend)\b`)
	lvlError = regexp.MustCompile(`(?i)\b(error|err|fehler|exception|failed|failure|fehlgeschlagen)\b`)
	lvlWarn  = regexp.MustCompile(`(?i)\b(warn|warning|warnung)\b`)
)

func detectLevel(line string) int {
	head := line
	if len(head) > 200 {
		head = head[:200]
	}
	switch {
	case lvlFatal.MatchString(head):
		return 2
	case lvlError.MatchString(head):
		return 3
	case lvlWarn.MatchString(head):
		return 4
	}
	return 6
}

const maxReadPerFile = 512 << 10

// appLogs liest neue Zeilen aus den lokal konfigurierten Anwendungs-Logs.
func appLogs(cfg *Config, st *state) []rawAppLog {
	var out []rawAppLog
	seen := map[string]bool{}
	for _, al := range cfg.AppLogs {
		if al.Path == "" || al.Name == "" {
			continue
		}
		files, _ := filepath.Glob(al.Path)
		firstRun := !st.AppInitialized[al.Name]
		for _, f := range files {
			seen[f] = true
			info, err := os.Stat(f)
			if err != nil || info.IsDir() {
				continue
			}
			off, known := st.FileOffsets[f]
			if !known {
				if firstRun {
					// Beim ersten Start keine Altlasten senden
					st.FileOffsets[f] = info.Size()
					continue
				}
				off = 0
			}
			if info.Size() < off { // Datei wurde rotiert/geleert
				off = 0
			}
			if info.Size() == off {
				continue
			}
			lines, newOff := readNewLines(f, off)
			st.FileOffsets[f] = newOff
			for _, ln := range lines {
				lvl := detectLevel(ln)
				if lvl > al.MaxLevel {
					continue
				}
				out = append(out, rawAppLog{app: al, log: rawLog{Time: time.Now(), Source: al.Name, Level: lvl, Message: ln}})
				if len(out) >= proto.MaxLogsPerReport {
					break
				}
			}
		}
		st.AppInitialized[al.Name] = true
	}
	// Offsets nicht mehr vorhandener Dateien vergessen
	for f := range st.FileOffsets {
		if !seen[f] {
			delete(st.FileOffsets, f)
		}
	}
	return out
}

type rawAppLog struct {
	app AppLog
	log rawLog
}

func readNewLines(path string, off int64) ([]string, int64) {
	f, err := os.Open(path)
	if err != nil {
		return nil, off
	}
	defer f.Close()
	if _, err := f.Seek(off, io.SeekStart); err != nil {
		return nil, off
	}
	buf, _ := io.ReadAll(io.LimitReader(f, maxReadPerFile))
	// nur bis zum letzten Zeilenumbruch verarbeiten
	end := bytes.LastIndexByte(buf, '\n')
	if end < 0 {
		if len(buf) == maxReadPerFile { // überlange Zeile überspringen
			return nil, off + int64(len(buf))
		}
		return nil, off
	}
	var lines []string
	sc := bufio.NewScanner(bytes.NewReader(buf[:end+1]))
	sc.Buffer(make([]byte, 64*1024), maxReadPerFile)
	for sc.Scan() {
		if l := strings.TrimSpace(sc.Text()); l != "" {
			lines = append(lines, l)
		}
	}
	return lines, off + int64(end+1)
}
