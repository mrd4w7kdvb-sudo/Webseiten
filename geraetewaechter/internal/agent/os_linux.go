//go:build linux

package agent

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

func battery() (batteryState, bool) {
	dirs, _ := filepath.Glob("/sys/class/power_supply/*")
	for _, d := range dirs {
		t, _ := os.ReadFile(filepath.Join(d, "type"))
		if strings.TrimSpace(string(t)) != "Battery" {
			continue
		}
		c, err := os.ReadFile(filepath.Join(d, "capacity"))
		if err != nil {
			continue
		}
		p, err := strconv.ParseFloat(strings.TrimSpace(string(c)), 64)
		if err != nil {
			continue
		}
		st, _ := os.ReadFile(filepath.Join(d, "status"))
		s := strings.TrimSpace(string(st))
		return batteryState{percent: p, charging: s == "Charging" || s == "Full"}, true
	}
	return batteryState{}, false
}

func cpuTemperature() (float64, bool) {
	zones, _ := filepath.Glob("/sys/class/thermal/thermal_zone*")
	best, found := 0.0, false
	for _, z := range zones {
		t, _ := os.ReadFile(filepath.Join(z, "type"))
		typ := strings.ToLower(strings.TrimSpace(string(t)))
		if !(strings.Contains(typ, "cpu") || strings.Contains(typ, "x86_pkg") || strings.Contains(typ, "soc") || strings.Contains(typ, "acpitz")) {
			continue
		}
		v, err := os.ReadFile(filepath.Join(z, "temp"))
		if err != nil {
			continue
		}
		milli, err := strconv.ParseFloat(strings.TrimSpace(string(v)), 64)
		if err != nil || milli <= 0 {
			continue
		}
		if c := milli / 1000; c > best {
			best, found = c, true
		}
	}
	return best, found
}

func serviceRunning(name string) (bool, string) {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	out, _ := exec.CommandContext(ctx, "systemctl", "is-active", "--", name).Output()
	s := strings.TrimSpace(string(out))
	if s == "" {
		s = "unbekannt"
	}
	return s == "active", s
}

// systemLogs liest das systemd-Journal (nur Systemdienste und Kernel, keine
// Benutzer-Journale) und fordert dabei nur die benötigten Felder an.
func systemLogs(st *state, maxLevel int) ([]rawLog, error) {
	if _, err := exec.LookPath("journalctl"); err != nil {
		return nil, nil
	}
	args := []string{"--system", "--no-pager", "-o", "json", "-p", "0.." + strconv.Itoa(maxLevel), "-n", "1000"}
	if st.JournalCursor != "" {
		args = append(args, "--after-cursor="+st.JournalCursor)
	} else {
		args = append(args, "--since=-10min")
	}
	fields := "--output-fields=MESSAGE,PRIORITY,SYSLOG_IDENTIFIER,_SYSTEMD_UNIT,_COMM"
	out, err := runJournal(append(args, fields))
	if err != nil {
		// ältere journalctl-Versionen kennen --output-fields nicht
		out, err = runJournal(args)
		if err != nil {
			return nil, err
		}
	}
	var logs []rawLog
	sc := bufio.NewScanner(bytes.NewReader(out))
	sc.Buffer(make([]byte, 64*1024), 4*1024*1024)
	for sc.Scan() {
		var e map[string]json.RawMessage
		if json.Unmarshal(sc.Bytes(), &e) != nil {
			continue
		}
		if c := jsonString(e["__CURSOR"]); c != "" {
			st.JournalCursor = c
		}
		us, _ := strconv.ParseInt(jsonString(e["__REALTIME_TIMESTAMP"]), 10, 64)
		lvl, err := strconv.Atoi(jsonString(e["PRIORITY"]))
		if err != nil {
			lvl = 6
		}
		src := jsonString(e["_SYSTEMD_UNIT"])
		if src == "" {
			src = jsonString(e["SYSLOG_IDENTIFIER"])
		}
		if src == "" {
			src = jsonString(e["_COMM"])
		}
		if src == "" {
			src = "kernel"
		}
		logs = append(logs, rawLog{Time: time.UnixMicro(us), Source: src, Level: lvl, Message: jsonString(e["MESSAGE"])})
	}
	return logs, nil
}

func runJournal(args []string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	return exec.CommandContext(ctx, "journalctl", args...).Output()
}

// jsonString liest Journal-Felder, die als Text oder Byte-Liste kodiert sein können.
func jsonString(r json.RawMessage) string {
	if len(r) == 0 {
		return ""
	}
	var s string
	if json.Unmarshal(r, &s) == nil {
		return s
	}
	var b []byte
	var ints []int
	if json.Unmarshal(r, &ints) == nil {
		for _, i := range ints {
			b = append(b, byte(i))
		}
		return string(b)
	}
	return ""
}

// localIdentities liefert lokale Benutzerkonten und deren Klarnamen, die in
// Logs zuverlässig ersetzt werden sollen.
func localIdentities() (accounts, names []string) {
	f, err := os.Open("/etc/passwd")
	if err == nil {
		defer f.Close()
		sc := bufio.NewScanner(f)
		for sc.Scan() {
			p := strings.Split(sc.Text(), ":")
			if len(p) < 5 {
				continue
			}
			uid, _ := strconv.Atoi(p[2])
			if uid < 1000 || uid >= 60000 || len(p) < 7 {
				continue
			}
			// nur echte Anmeldekonten (keine Dienst-/Build-Konten ohne Shell)
			if sh := p[6]; strings.HasSuffix(sh, "nologin") || strings.HasSuffix(sh, "/false") {
				continue
			}
			accounts = append(accounts, p[0])
			if full := strings.TrimSpace(strings.Split(p[4], ",")[0]); full != "" {
				names = append(names, full)
			}
		}
	}
	if ents, err := os.ReadDir("/home"); err == nil {
		for _, e := range ents {
			if e.IsDir() {
				accounts = append(accounts, e.Name())
			}
		}
	}
	return accounts, names
}
