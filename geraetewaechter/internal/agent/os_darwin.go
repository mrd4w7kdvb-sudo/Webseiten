//go:build darwin

package agent

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var pmsetRe = regexp.MustCompile(`(\d+)%;\s*([a-zA-Z ]+);`)

func battery() (batteryState, bool) {
	out, err := cmdOut(10*time.Second, "pmset", "-g", "batt")
	if err != nil {
		return batteryState{}, false
	}
	m := pmsetRe.FindStringSubmatch(string(out))
	if m == nil {
		return batteryState{}, false
	}
	p, _ := strconv.ParseFloat(m[1], 64)
	st := strings.TrimSpace(m[2])
	return batteryState{percent: p, charging: st == "charging" || st == "charged" || st == "finishing charge"}, true
}

func cpuTemperature() (float64, bool) { return 0, false }

// serviceRunning prüft einen launchd-Dienst anhand seines Labels.
func serviceRunning(label string) (bool, string) {
	out, err := cmdOut(10*time.Second, "launchctl", "list")
	if err != nil {
		return false, "unbekannt"
	}
	sc := bufio.NewScanner(bytes.NewReader(out))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) >= 3 && f[2] == label {
			if f[0] != "-" {
				return true, "läuft"
			}
			return false, "gestoppt (Status " + f[1] + ")"
		}
	}
	return false, "nicht gefunden"
}

// systemLogs liest Fehler und schwere Fehler aus dem Unified Log.
func systemLogs(st *state, maxLevel int) ([]rawLog, error) {
	start := st.LastLogTime
	if start.IsZero() || time.Since(start) > 24*time.Hour {
		start = time.Now().Add(-10 * time.Minute)
	}
	pred := `messageType == fault`
	if maxLevel >= 3 {
		pred = `messageType == error OR messageType == fault`
	}
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, "log", "show", "--style", "ndjson", "--no-pager",
		"--start", start.Local().Format("2006-01-02 15:04:05"), "--predicate", pred)
	out, err := cmd.Output()
	if err != nil && len(out) == 0 {
		return nil, err
	}
	var logs []rawLog
	sc := bufio.NewScanner(bytes.NewReader(out))
	sc.Buffer(make([]byte, 64*1024), 4*1024*1024)
	newest := st.LastLogTime
	for sc.Scan() {
		var e struct {
			Timestamp   string `json:"timestamp"`
			MessageType string `json:"messageType"`
			Subsystem   string `json:"subsystem"`
			ProcessPath string `json:"processImagePath"`
			Message     string `json:"eventMessage"`
		}
		if json.Unmarshal(sc.Bytes(), &e) != nil || e.Message == "" {
			continue
		}
		t, err := time.Parse("2006-01-02 15:04:05.000000-0700", e.Timestamp)
		if err != nil {
			t = time.Now()
		}
		if !t.After(st.LastLogTime) {
			continue
		}
		if t.After(newest) {
			newest = t
		}
		lvl := 3
		if strings.EqualFold(e.MessageType, "fault") {
			lvl = 2
		}
		src := e.Subsystem
		if src == "" {
			src = filepath.Base(e.ProcessPath)
		}
		logs = append(logs, rawLog{Time: t, Source: src, Level: lvl, Message: e.Message})
		if len(logs) >= 1000 {
			break
		}
	}
	if newest.IsZero() {
		newest = time.Now().Add(-time.Minute)
	}
	st.LastLogTime = newest
	return logs, nil
}

func localIdentities() (accounts, names []string) {
	home := map[string]bool{}
	if ents, err := os.ReadDir("/Users"); err == nil {
		for _, e := range ents {
			n := e.Name()
			if e.IsDir() && !strings.HasPrefix(n, ".") && n != "Shared" && n != "Guest" {
				accounts = append(accounts, n)
				home[n] = true
			}
		}
	}
	if out, err := cmdOut(10*time.Second, "dscl", ".", "-list", "/Users", "RealName"); err == nil {
		sc := bufio.NewScanner(bytes.NewReader(out))
		for sc.Scan() {
			f := strings.Fields(sc.Text())
			// nur echte Benutzer mit Benutzerordner, keine Systemkonten
			if len(f) < 2 || !home[f[0]] {
				continue
			}
			names = append(names, strings.Join(f[1:], " "))
		}
	}
	return accounts, names
}

func cmdOut(timeout time.Duration, name string, args ...string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(context.Background(), timeout)
	defer cancel()
	return exec.CommandContext(ctx, name, args...).Output()
}
