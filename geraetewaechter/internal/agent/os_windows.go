//go:build windows

package agent

import (
	"context"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

type systemPowerStatus struct {
	ACLineStatus        byte
	BatteryFlag         byte
	BatteryLifePercent  byte
	SystemStatusFlag    byte
	BatteryLifeTime     uint32
	BatteryFullLifeTime uint32
}

var procGetSystemPowerStatus = windows.NewLazySystemDLL("kernel32.dll").NewProc("GetSystemPowerStatus")

func battery() (batteryState, bool) {
	var s systemPowerStatus
	r, _, _ := procGetSystemPowerStatus.Call(uintptr(unsafe.Pointer(&s)))
	if r == 0 || s.BatteryFlag == 128 || s.BatteryFlag == 255 || s.BatteryLifePercent > 100 {
		return batteryState{}, false
	}
	return batteryState{percent: float64(s.BatteryLifePercent), charging: s.ACLineStatus == 1}, true
}

func cpuTemperature() (float64, bool) { return 0, false }

func serviceRunning(name string) (bool, string) {
	m, err := mgr.Connect()
	if err != nil {
		return false, "unbekannt"
	}
	defer m.Disconnect()
	s, err := m.OpenService(name)
	if err != nil {
		return false, "nicht gefunden"
	}
	defer s.Close()
	st, err := s.Query()
	if err != nil {
		return false, "unbekannt"
	}
	switch st.State {
	case svc.Running:
		return true, "läuft"
	case svc.Stopped:
		return false, "gestoppt"
	case svc.StartPending:
		return false, "startet"
	case svc.StopPending:
		return false, "wird gestoppt"
	case svc.Paused:
		return false, "pausiert"
	}
	return false, "Status " + strconv.Itoa(int(st.State))
}

// Ereignisprotokolle "System" und "Application". Das Sicherheitsprotokoll
// ("Security") wird bewusst NICHT gelesen, da es Anmeldenamen enthält.
const winEventScript = `
$ErrorActionPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$start = [DateTime]::Parse('%START%', $null, [Globalization.DateTimeStyles]::RoundtripKind).ToLocalTime()
$ev = Get-WinEvent -MaxEvents 500 -FilterHashtable @{ LogName = 'System','Application'; Level = %LEVELS%; StartTime = $start } |
  Sort-Object TimeCreated |
  ForEach-Object { [pscustomobject]@{ t = $_.TimeCreated.ToUniversalTime().ToString('o'); p = $_.ProviderName; i = $_.Id; l = $_.Level; c = $_.LogName; m = $_.Message } }
ConvertTo-Json -InputObject @($ev) -Compress -Depth 2
`

func systemLogs(st *state, maxLevel int) ([]rawLog, error) {
	start := st.LastLogTime
	if start.IsZero() || time.Since(start) > 24*time.Hour {
		start = time.Now().Add(-10 * time.Minute)
	}
	levels := "1,2,3"
	if maxLevel <= 3 {
		levels = "1,2"
	}
	if maxLevel <= 2 {
		levels = "1"
	}
	script := strings.NewReplacer("%START%", start.Add(time.Millisecond).UTC().Format(time.RFC3339Nano), "%LEVELS%", levels).Replace(winEventScript)
	ctx, cancel := context.WithTimeout(context.Background(), 90*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", script)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	out, err := cmd.Output()
	if err != nil && len(out) == 0 {
		return nil, err
	}
	var evs []struct {
		T string `json:"t"`
		P string `json:"p"`
		I int    `json:"i"`
		L int    `json:"l"`
		C string `json:"c"`
		M string `json:"m"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(strings.TrimPrefix(string(out), "\ufeff"))), &evs); err != nil {
		return nil, nil
	}
	newest := st.LastLogTime
	var logs []rawLog
	for _, e := range evs {
		t, err := time.Parse(time.RFC3339Nano, e.T)
		if err != nil {
			continue
		}
		if !t.After(st.LastLogTime) {
			continue
		}
		if t.After(newest) {
			newest = t
		}
		lvl := 6
		switch e.L {
		case 1:
			lvl = 2
		case 2:
			lvl = 3
		case 3:
			lvl = 4
		}
		logs = append(logs, rawLog{Time: t, Source: e.C + "/" + e.P, Level: lvl, EventID: strconv.Itoa(e.I), Message: e.M})
	}
	if newest.IsZero() {
		newest = time.Now().Add(-time.Minute)
	}
	st.LastLogTime = newest
	return logs, nil
}

func localIdentities() (accounts, names []string) {
	users := filepath.Join(os.Getenv("SystemDrive")+`\`, "Users")
	if ents, err := os.ReadDir(users); err == nil {
		for _, e := range ents {
			if e.IsDir() {
				accounts = append(accounts, e.Name())
			}
		}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, "powershell.exe", "-NoProfile", "-NonInteractive", "-Command",
		`[Console]::OutputEncoding=[Text.Encoding]::UTF8; Get-LocalUser | Select-Object Name,FullName | ConvertTo-Json -Compress`)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true}
	if out, err := cmd.Output(); err == nil {
		var list []struct{ Name, FullName string }
		raw := strings.TrimSpace(strings.TrimPrefix(string(out), "\ufeff"))
		if strings.HasPrefix(raw, "{") {
			raw = "[" + raw + "]"
		}
		if json.Unmarshal([]byte(raw), &list) == nil {
			for _, u := range list {
				accounts = append(accounts, u.Name)
				if u.FullName != "" {
					names = append(names, u.FullName)
				}
			}
		}
	}
	return accounts, names
}
