package agent

import (
	"runtime"
	"strings"
	"time"

	"github.com/shirou/gopsutil/v4/cpu"
	"github.com/shirou/gopsutil/v4/disk"
	"github.com/shirou/gopsutil/v4/host"
	"github.com/shirou/gopsutil/v4/load"
	"github.com/shirou/gopsutil/v4/mem"
	"github.com/shirou/gopsutil/v4/net"
	"github.com/shirou/gopsutil/v4/process"

	"geraetewaechter/internal/privacy"
	"geraetewaechter/internal/proto"
)

// collector sammelt Messwerte. Es werden ausschließlich aggregierte
// Systemwerte erhoben – keine Prozess-Kommandozeilen, Benutzer, Fenster,
// Dateinamen oder Netzwerkverbindungen.
type collector struct {
	san      *privacy.Sanitizer
	lastNet  *net.IOCountersStat
	lastNetT time.Time
}

func newCollector(san *privacy.Sanitizer) *collector {
	_, _ = cpu.Percent(0, false) // Startwert für die erste Messung
	return &collector{san: san}
}

func (c *collector) systemInfo() proto.SystemInfo {
	si := proto.SystemInfo{OS: runtime.GOOS, Arch: runtime.GOARCH}
	if h, err := host.Info(); err == nil {
		si.Platform = h.Platform
		si.PlatformVersion = h.PlatformVersion
		si.KernelVersion = h.KernelVersion
		si.UptimeSeconds = h.Uptime
	}
	return si
}

func (c *collector) metrics(si proto.SystemInfo) []proto.Metric {
	var out []proto.Metric
	add := func(name string, v float64, labels map[string]string) {
		out = append(out, proto.Metric{Name: name, Value: v, Labels: labels})
	}

	if p, err := cpu.Percent(0, false); err == nil && len(p) > 0 {
		add("cpu.percent", round(p[0]), nil)
	}
	add("cpu.cores", float64(runtime.NumCPU()), nil)
	if runtime.GOOS != "windows" {
		if l, err := load.Avg(); err == nil {
			add("load.1", round(l.Load1), nil)
			add("load.15", round(l.Load15), nil)
		}
	}
	if vm, err := mem.VirtualMemory(); err == nil {
		add("mem.percent", round(vm.UsedPercent), nil)
		add("mem.used_bytes", float64(vm.Used), nil)
		add("mem.total_bytes", float64(vm.Total), nil)
	}
	if sw, err := mem.SwapMemory(); err == nil && sw.Total > 0 {
		add("swap.percent", round(sw.UsedPercent), nil)
	}
	add("uptime.seconds", float64(si.UptimeSeconds), nil)

	if parts, err := disk.Partitions(false); err == nil {
		seen := map[string]bool{}
		for _, p := range parts {
			if skipMount(p) || seen[p.Mountpoint] {
				continue
			}
			seen[p.Mountpoint] = true
			u, err := disk.Usage(p.Mountpoint)
			if err != nil || u.Total == 0 {
				continue
			}
			lb := map[string]string{"mount": c.san.Label(p.Mountpoint)}
			add("disk.percent", round(u.UsedPercent), lb)
			add("disk.free_bytes", float64(u.Free), lb)
			add("disk.total_bytes", float64(u.Total), lb)
		}
	}

	if ios, err := net.IOCounters(true); err == nil {
		var sum net.IOCountersStat
		for _, i := range ios {
			n := strings.ToLower(i.Name)
			if n == "lo" || strings.HasPrefix(n, "loopback") || strings.HasPrefix(n, "lo0") {
				continue
			}
			sum.BytesRecv += i.BytesRecv
			sum.BytesSent += i.BytesSent
			sum.Errin += i.Errin
			sum.Errout += i.Errout
		}
		now := time.Now()
		if c.lastNet != nil {
			dt := now.Sub(c.lastNetT).Seconds()
			if dt > 0 && sum.BytesRecv >= c.lastNet.BytesRecv && sum.BytesSent >= c.lastNet.BytesSent {
				add("net.rx_bytes_per_s", round(float64(sum.BytesRecv-c.lastNet.BytesRecv)/dt), nil)
				add("net.tx_bytes_per_s", round(float64(sum.BytesSent-c.lastNet.BytesSent)/dt), nil)
				if sum.Errin+sum.Errout >= c.lastNet.Errin+c.lastNet.Errout {
					add("net.errors", float64(sum.Errin+sum.Errout-c.lastNet.Errin-c.lastNet.Errout), nil)
				}
			}
		}
		c.lastNet, c.lastNetT = &sum, now
	}

	if pids, err := process.Pids(); err == nil {
		add("proc.count", float64(len(pids)), nil)
	}
	if b, ok := battery(); ok {
		add("battery.percent", b.percent, nil)
		add("battery.charging", b2f(b.charging), nil)
	}
	if t, ok := cpuTemperature(); ok {
		add("temp.cpu", round(t), nil)
	}
	return out
}

// skipMount blendet virtuelle und wechselbare Datenträger aus. Wechselmedien
// werden nicht erfasst, da ihre Bezeichnungen persönlich sein können.
func skipMount(p disk.PartitionStat) bool {
	fs := strings.ToLower(p.Fstype)
	switch fs {
	case "tmpfs", "devtmpfs", "squashfs", "overlay", "proc", "sysfs", "cgroup", "cgroup2", "devfs", "autofs",
		"nullfs", "fuse.snapfuse", "efivarfs", "ramfs", "iso9660", "udf", "cdfs":
		return true
	}
	m := p.Mountpoint
	for _, pre := range []string{"/snap", "/run", "/sys", "/proc", "/dev", "/media", "/mnt/wsl", "/Volumes", "/private/var/vm", "/var/lib/docker"} {
		if m == pre || strings.HasPrefix(m, pre+"/") {
			return true
		}
	}
	if strings.HasPrefix(m, "/System/Volumes/") && m != "/System/Volumes/Data" {
		return true
	}
	return false
}

// checks prüft, ob die gewünschten Programme und Dienste laufen. Es werden
// nur die Prozessnamen verglichen, keine weiteren Prozessdaten gelesen.
func (c *collector) checks(procs, services []string) []proto.CheckResult {
	var out []proto.CheckResult
	if len(procs) > 0 {
		running := map[string]int{}
		if ps, err := process.Processes(); err == nil {
			for _, p := range ps {
				if n, err := p.Name(); err == nil {
					running[normProc(n)]++
				}
			}
		}
		for _, want := range procs {
			n := running[normProc(want)]
			out = append(out, proto.CheckResult{Kind: "process", Name: want, OK: n > 0, Detail: plural(n, "Instanz", "Instanzen")})
		}
	}
	for _, svc := range services {
		ok, detail := serviceRunning(svc)
		out = append(out, proto.CheckResult{Kind: "service", Name: svc, OK: ok, Detail: detail})
	}
	return out
}

func normProc(n string) string {
	n = strings.ToLower(strings.TrimSpace(n))
	return strings.TrimSuffix(n, ".exe")
}

func plural(n int, one, many string) string {
	if n == 1 {
		return "1 " + one
	}
	return itoa(n) + " " + many
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var b [20]byte
	i := len(b)
	for n > 0 {
		i--
		b[i] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		i--
		b[i] = '-'
	}
	return string(b[i:])
}

func round(f float64) float64 { return float64(int64(f*10+0.5)) / 10 }

func b2f(b bool) float64 {
	if b {
		return 1
	}
	return 0
}

type batteryState struct {
	percent  float64
	charging bool
}
