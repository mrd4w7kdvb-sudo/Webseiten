//go:build windows

package agent

import (
	"encoding/xml"
	"errors"
	"os"
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

// Der Windows-Agent startet keine Hilfsprogramme (kein PowerShell, kein
// cmd, kein icacls). Alle Daten werden direkt über die dokumentierten
// Windows-Schnittstellen gelesen. Das vermeidet Fehlalarme von
// Virenscannern/EDR, die versteckte PowerShell-Aufrufe zu Recht als
// verdächtig einstufen.

var (
	kernel32                 = windows.NewLazySystemDLL("kernel32.dll")
	procGetSystemPowerStatus = kernel32.NewProc("GetSystemPowerStatus")

	wevtapi                      = windows.NewLazySystemDLL("wevtapi.dll")
	procEvtQuery                 = wevtapi.NewProc("EvtQuery")
	procEvtNext                  = wevtapi.NewProc("EvtNext")
	procEvtRender                = wevtapi.NewProc("EvtRender")
	procEvtClose                 = wevtapi.NewProc("EvtClose")
	procEvtOpenPublisherMetadata = wevtapi.NewProc("EvtOpenPublisherMetadata")
	procEvtFormatMessage         = wevtapi.NewProc("EvtFormatMessage")

	netapi32        = windows.NewLazySystemDLL("netapi32.dll")
	procNetUserEnum = netapi32.NewProc("NetUserEnum")
)

type systemPowerStatus struct {
	ACLineStatus        byte
	BatteryFlag         byte
	BatteryLifePercent  byte
	SystemStatusFlag    byte
	BatteryLifeTime     uint32
	BatteryFullLifeTime uint32
}

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

// ---------- Ereignisprotokoll (Windows Event Log API) ----------

const (
	evtQueryChannelPath         = 0x1
	evtQueryForwardDirection    = 0x100
	evtQueryTolerateQueryErrors = 0x1000
	evtRenderEventXML           = 1
	evtFormatMessageEvent       = 1
	errNoMoreItems              = syscall.Errno(259)
	errInsufficientBuffer       = syscall.Errno(122)
	maxEventsPerRun             = 500
)

type evtHandle uintptr

func evtClose(h evtHandle) {
	if h != 0 {
		_, _, _ = procEvtClose.Call(uintptr(h))
	}
}

func evtQuery(channel, query string) (evtHandle, error) {
	c, err := windows.UTF16PtrFromString(channel)
	if err != nil {
		return 0, err
	}
	q, err := windows.UTF16PtrFromString(query)
	if err != nil {
		return 0, err
	}
	r, _, e := procEvtQuery.Call(0, uintptr(unsafe.Pointer(c)), uintptr(unsafe.Pointer(q)),
		evtQueryChannelPath|evtQueryForwardDirection|evtQueryTolerateQueryErrors)
	if r == 0 {
		return 0, e
	}
	return evtHandle(r), nil
}

func evtNext(rs evtHandle, buf []evtHandle) (int, error) {
	var n uint32
	r, _, e := procEvtNext.Call(uintptr(rs), uintptr(len(buf)), uintptr(unsafe.Pointer(&buf[0])), 2000, 0, uintptr(unsafe.Pointer(&n)))
	if r == 0 {
		if errors.Is(e, errNoMoreItems) {
			return 0, nil
		}
		return 0, e
	}
	return int(n), nil
}

func evtRenderXML(ev evtHandle) (string, error) {
	var used, props uint32
	buf := make([]uint16, 4096)
	for i := 0; i < 2; i++ {
		r, _, e := procEvtRender.Call(0, uintptr(ev), evtRenderEventXML, uintptr(len(buf)*2),
			uintptr(unsafe.Pointer(&buf[0])), uintptr(unsafe.Pointer(&used)), uintptr(unsafe.Pointer(&props)))
		if r != 0 {
			return windows.UTF16ToString(buf), nil
		}
		if !errors.Is(e, errInsufficientBuffer) || used == 0 {
			return "", e
		}
		buf = make([]uint16, used/2+1)
	}
	return "", errors.New("Ereignis zu groß")
}

// publisherCache hält Metadaten-Handles der Ereignisquellen für die Textausgabe.
var publisherCache = map[string]evtHandle{}

func evtMessage(provider string, ev evtHandle) string {
	pub, ok := publisherCache[provider]
	if !ok {
		p, err := windows.UTF16PtrFromString(provider)
		if err == nil {
			r, _, _ := procEvtOpenPublisherMetadata.Call(0, uintptr(unsafe.Pointer(p)), 0, 0, 0)
			pub = evtHandle(r)
		}
		if len(publisherCache) > 500 {
			for k, h := range publisherCache {
				evtClose(h)
				delete(publisherCache, k)
			}
		}
		publisherCache[provider] = pub
	}
	if pub == 0 {
		return ""
	}
	var used uint32
	buf := make([]uint16, 2048)
	for i := 0; i < 2; i++ {
		r, _, e := procEvtFormatMessage.Call(uintptr(pub), uintptr(ev), 0, 0, 0, evtFormatMessageEvent,
			uintptr(len(buf)), uintptr(unsafe.Pointer(&buf[0])), uintptr(unsafe.Pointer(&used)))
		if r != 0 {
			return windows.UTF16ToString(buf)
		}
		if !errors.Is(e, errInsufficientBuffer) || used == 0 || used > 1<<20 {
			return ""
		}
		buf = make([]uint16, used+1)
	}
	return ""
}

// Nur die System-Kopfdaten des Ereignisses werden ausgewertet. Die
// Ereignisdaten (EventData/UserData), die z.B. Kontonamen enthalten können,
// werden nicht gelesen.
type evtXML struct {
	System struct {
		Provider struct {
			Name string `xml:"Name,attr"`
		} `xml:"Provider"`
		EventID     string `xml:"EventID"`
		Level       int    `xml:"Level"`
		TimeCreated struct {
			SystemTime string `xml:"SystemTime,attr"`
		} `xml:"TimeCreated"`
		Channel string `xml:"Channel"`
	} `xml:"System"`
}

// systemLogs liest die Protokolle "System" und "Application". Das
// Sicherheitsprotokoll ("Security") wird bewusst NICHT gelesen, da es
// Anmeldenamen enthält.
func systemLogs(st *state, maxLevel int) ([]rawLog, error) {
	start := st.LastLogTime
	if start.IsZero() || time.Since(start) > 24*time.Hour {
		start = time.Now().Add(-10 * time.Minute)
	}
	levels := "Level=1 or Level=2 or Level=3"
	if maxLevel <= 3 {
		levels = "Level=1 or Level=2"
	}
	if maxLevel <= 2 {
		levels = "Level=1"
	}
	query := "*[System[(" + levels + ") and TimeCreated[@SystemTime>'" + start.UTC().Format("2006-01-02T15:04:05.000Z") + "']]]"

	newest := st.LastLogTime
	var logs []rawLog
	var firstErr error
	for _, channel := range []string{"System", "Application"} {
		rs, err := evtQuery(channel, query)
		if err != nil {
			if firstErr == nil {
				firstErr = err
			}
			continue
		}
		batch := make([]evtHandle, 64)
		for len(logs) < maxEventsPerRun {
			n, err := evtNext(rs, batch)
			if err != nil || n == 0 {
				break
			}
			for _, ev := range batch[:n] {
				if l, ok := renderEvent(ev, st.LastLogTime); ok && len(logs) < maxEventsPerRun {
					logs = append(logs, l)
					if l.Time.After(newest) {
						newest = l.Time
					}
				}
				evtClose(ev)
			}
		}
		evtClose(rs)
	}
	if newest.IsZero() {
		newest = time.Now().Add(-time.Minute)
	}
	st.LastLogTime = newest
	if len(logs) == 0 && firstErr != nil {
		return nil, firstErr
	}
	return logs, nil
}

func renderEvent(ev evtHandle, after time.Time) (rawLog, bool) {
	raw, err := evtRenderXML(ev)
	if err != nil {
		return rawLog{}, false
	}
	var x evtXML
	if xml.Unmarshal([]byte(raw), &x) != nil {
		return rawLog{}, false
	}
	t, err := time.Parse(time.RFC3339Nano, x.System.TimeCreated.SystemTime)
	if err != nil || !t.After(after) {
		return rawLog{}, false
	}
	lvl := 6
	switch x.System.Level {
	case 1:
		lvl = 2
	case 2:
		lvl = 3
	case 3:
		lvl = 4
	}
	return rawLog{
		Time:    t,
		Source:  x.System.Channel + "/" + x.System.Provider.Name,
		Level:   lvl,
		EventID: strings.TrimSpace(x.System.EventID),
		Message: evtMessage(x.System.Provider.Name, ev),
	}, true
}

// ---------- Lokale Benutzerkonten (für die Bereinigung) ----------

type userInfo10 struct {
	Name, Comment, UsrComment, FullName *uint16
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
	var buf *byte
	var read, total, resume uint32
	const filterNormalAccount = 2
	r, _, _ := procNetUserEnum.Call(0, 10, filterNormalAccount, uintptr(unsafe.Pointer(&buf)), 0xFFFFFFFF,
		uintptr(unsafe.Pointer(&read)), uintptr(unsafe.Pointer(&total)), uintptr(unsafe.Pointer(&resume)))
	if buf != nil {
		defer windows.NetApiBufferFree(buf)
	}
	if r != 0 || buf == nil {
		return accounts, names
	}
	for _, u := range unsafe.Slice((*userInfo10)(unsafe.Pointer(buf)), read) {
		if n := windows.UTF16PtrToString(u.Name); n != "" {
			accounts = append(accounts, n)
		}
		if f := windows.UTF16PtrToString(u.FullName); f != "" {
			names = append(names, f)
		}
	}
	return accounts, names
}
