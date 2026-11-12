// gw-agent ist die lokale Sensor-Software des Gerätewächters.
package main

import (
	"context"
	"flag"
	"fmt"
	"io"
	"log"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/kardianos/service"

	"geraetewaechter/internal/agent"
)

const usage = `Gerätewächter-Agent %s

Aufruf: gw-agent <befehl> [optionen]

Befehle:
  setup --server URL --token SCHLÜSSEL [--name NAME]
                Komplett-Installation: Programm an festen Ort kopieren,
                Gerät registrieren, Systemdienst einrichten und starten
  enroll --server URL --token SCHLÜSSEL [--name NAME] [--force]
                Gerät beim Server registrieren
  install       als Systemdienst einrichten (Windows-Dienst, systemd, launchd)
  uninstall     Systemdienst entfernen
  start | stop | restart | status
                Systemdienst steuern
  run           im Vordergrund ausführen (wird vom Dienst verwendet)
  once          genau eine Meldung senden
  preview       Meldung erzeugen und anzeigen, OHNE sie zu senden
                (zur Kontrolle, welche Daten das Gerät verlassen würden)
  paths         Pfade von Konfiguration und Kontrolldatei anzeigen
  version       Version anzeigen
`

type program struct {
	cancel context.CancelFunc
	done   chan struct{}
}

func (p *program) Start(s service.Service) error {
	cfg, err := agent.LoadConfig()
	if err != nil {
		return fmt.Errorf("Konfiguration %s: %w (zuerst 'gw-agent enroll' ausführen)", agent.ConfigPath(), err)
	}
	if cfg.AgentID == "" {
		return fmt.Errorf("Gerät nicht registriert – zuerst 'gw-agent enroll' ausführen")
	}
	a, err := agent.New(cfg)
	if err != nil {
		return err
	}
	ctx, cancel := context.WithCancel(context.Background())
	p.cancel = cancel
	p.done = make(chan struct{})
	go func() {
		defer close(p.done)
		a.Run(ctx)
	}()
	return nil
}

func (p *program) Stop(s service.Service) error {
	if p.cancel != nil {
		p.cancel()
		<-p.done
	}
	return nil
}

// systemdUnit ist die Dienstdatei für Linux mit Sandbox-Einschränkungen:
// Der Agent darf das System nur lesen (außer seinen eigenen Ordnern), keine
// Rechte erweitern, keine Kernel-Einstellungen ändern und nur über das Netz
// kommunizieren. Benötigt werden nur Lesezugriffe (Journal, /proc, /sys).
const systemdUnit = `[Unit]
Description={{Description}}
ConditionFileIsExecutable={{Path | cmdEscape}}
After=network-online.target
Wants=network-online.target

[Service]
ExecStart={{Path | cmdEscape}}{{range Arguments}} {{. | cmd}}{{end}}
Restart=always
RestartSec=30
StartLimitInterval=0

# Sandbox
NoNewPrivileges=yes
ProtectSystem=strict
ReadWritePaths=/etc/geraetewaechter /var/lib/geraetewaechter
StateDirectory=geraetewaechter
ConfigurationDirectory=geraetewaechter
ProtectHome=read-only
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictSUIDSGID=yes
RestrictRealtime=yes
RestrictNamespaces=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes
RemoveIPC=yes
CapabilityBoundingSet=CAP_DAC_READ_SEARCH
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX AF_NETLINK
SystemCallArchitectures=native
SystemCallFilter=@system-service
SystemCallFilter=~@privileged @mount @reboot @swap @module @raw-io @debug @obsolete @cpu-emulation
SystemCallErrorNumber=EPERM
UMask=0077

[Install]
WantedBy=multi-user.target
`

func newService(exe string) (service.Service, error) {
	if exe == "" {
		exe, _ = os.Executable()
	}
	cfg := &service.Config{
		Name:        "geraetewaechter",
		DisplayName: "Gerätewächter Agent (Stockwerk IT)",
		Description: "Stockwerk IT Gerätewächter: überwacht den Systemzustand und meldet ihn datensparsam an den Monitoring-Server. Keine Fernsteuerung, keine personenbezogenen Daten.",
		Executable:  exe,
		Arguments:   []string{"run"},
		Option: service.KeyValue{
			"Restart":                "always",
			"OnFailure":              "restart",
			"OnFailureDelayDuration": "30s",
			"DelayedAutoStart":       true,
			"KeepAlive":              true,
			"RunAtLoad":              true,
			"SystemdScript":          systemdUnit,
		},
	}
	return service.New(&program{}, cfg)
}

// setup installiert den Agenten vollständig aus der heruntergeladenen Datei.
func setup(server, token, name string, allowHTTP bool) error {
	if !agent.IsAdmin() {
		return fmt.Errorf("bitte mit Administratorrechten ausführen (Windows: \"Als Administrator ausführen\", Linux/macOS: sudo)")
	}
	self, err := os.Executable()
	if err != nil {
		return err
	}
	if r, err := filepath.EvalSymlinks(self); err == nil {
		self = r
	}
	target := agent.InstallPath()
	svc, err := newService(target)
	if err != nil {
		return err
	}
	if !strings.EqualFold(filepath.Clean(self), filepath.Clean(target)) {
		_ = svc.Stop()
		if err := copyFile(self, target); err != nil {
			return fmt.Errorf("Programm konnte nicht nach %s kopiert werden: %w", target, err)
		}
		fmt.Println("Programm installiert:", target)
	}
	if err := agent.Enroll(server, token, name, false, allowHTTP); err != nil {
		return err
	}
	_ = svc.Stop()
	_ = svc.Uninstall()
	if err := svc.Install(); err != nil {
		return fmt.Errorf("Dienst konnte nicht eingerichtet werden: %w", err)
	}
	if err := svc.Start(); err != nil {
		return fmt.Errorf("Dienst konnte nicht gestartet werden: %w", err)
	}
	fmt.Println("Dienst \"Gerätewächter Agent\" eingerichtet und gestartet.")
	fmt.Println()
	fmt.Println("Konfiguration (Datenschutz-Einstellungen):", agent.ConfigPath())
	fmt.Println("Kontrolle der gesendeten Daten:          ", filepath.Join(agent.DataDir(), "letzte-meldung.json"))
	fmt.Println("Vorschau ohne Senden:                     ", target, "preview")
	return nil
}

func copyFile(src, dst string) error {
	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return err
	}
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()
	tmp := dst + ".neu"
	out, err := os.OpenFile(tmp, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		out.Close()
		return err
	}
	if err := out.Close(); err != nil {
		return err
	}
	// Ein gerade beendeter Dienst kann die Datei unter Windows kurz sperren.
	for i := 0; ; i++ {
		err = os.Rename(tmp, dst)
		if err == nil || i >= 10 {
			return err
		}
		time.Sleep(time.Second)
	}
}

func main() {
	if len(os.Args) < 2 {
		fmt.Printf(usage, agent.Version)
		os.Exit(2)
	}
	cmd := os.Args[1]
	args := os.Args[2:]

	switch cmd {
	case "enroll":
		fs := flag.NewFlagSet("enroll", flag.ExitOnError)
		server := fs.String("server", "", "Adresse des Servers, z.B. https://monitor.example.de")
		token := fs.String("token", "", "Registrierungsschlüssel des Kunden")
		name := fs.String("name", "", "Anzeigename (optional; Standard: kein Rechnername)")
		force := fs.Bool("force", false, "auch neu registrieren, wenn bereits registriert")
		allowHTTP := fs.Bool("allow-http", false, "unverschlüsseltes HTTP erlauben (nur zum Testen)")
		_ = fs.Parse(args)
		if *server == "" || *token == "" {
			fmt.Fprintln(os.Stderr, "--server und --token sind erforderlich")
			os.Exit(2)
		}
		if err := agent.Enroll(*server, *token, *name, *force, *allowHTTP); err != nil {
			fmt.Fprintln(os.Stderr, "Fehler:", err)
			os.Exit(1)
		}
	case "setup":
		fs := flag.NewFlagSet("setup", flag.ExitOnError)
		server := fs.String("server", "", "Adresse des Servers, z.B. https://monitor.example.de")
		token := fs.String("token", "", "Registrierungsschlüssel des Kunden")
		name := fs.String("name", "", "Anzeigename (optional)")
		allowHTTP := fs.Bool("allow-http", false, "unverschlüsseltes HTTP erlauben (nur zum Testen)")
		_ = fs.Parse(args)
		if *server == "" || *token == "" {
			fmt.Fprintln(os.Stderr, "--server und --token sind erforderlich")
			os.Exit(2)
		}
		if err := setup(*server, *token, *name, *allowHTTP); err != nil {
			fmt.Fprintln(os.Stderr, "Fehler:", err)
			os.Exit(1)
		}
	case "preview":
		if err := agent.Preview(os.Stdout); err != nil {
			fmt.Fprintln(os.Stderr, "Fehler:", err)
			os.Exit(1)
		}
	case "once":
		cfg, err := agent.LoadConfig()
		if err != nil {
			fmt.Fprintln(os.Stderr, "Fehler:", err)
			os.Exit(1)
		}
		a, err := agent.New(cfg)
		if err != nil {
			fmt.Fprintln(os.Stderr, "Fehler:", err)
			os.Exit(1)
		}
		if err := a.Once(); err != nil {
			fmt.Fprintln(os.Stderr, "Fehler:", err)
			os.Exit(1)
		}
		fmt.Println("Meldung gesendet.")
	case "paths":
		fmt.Println("Konfiguration:  ", agent.ConfigPath())
		fmt.Println("Arbeitsordner:  ", agent.DataDir())
		fmt.Println("Kontrolldatei:  ", agent.DataDir()+string(os.PathSeparator)+"letzte-meldung.json")
	case "version", "--version", "-v":
		fmt.Println(agent.Version)
	case "run":
		svc, err := newService("")
		if err != nil {
			log.Fatal(err)
		}
		if err := svc.Run(); err != nil {
			log.Fatal(err)
		}
	case "install":
		svc, err := newService("")
		if err != nil {
			log.Fatal(err)
		}
		_ = svc.Stop()
		_ = svc.Uninstall()
		if err := svc.Install(); err != nil {
			fmt.Fprintln(os.Stderr, "Installation fehlgeschlagen:", err)
			os.Exit(1)
		}
		fmt.Println("Dienst installiert.")
	case "uninstall", "start", "stop", "restart":
		svc, err := newService("")
		if err != nil {
			log.Fatal(err)
		}
		if err := service.Control(svc, cmd); err != nil {
			fmt.Fprintln(os.Stderr, "Fehler:", err)
			os.Exit(1)
		}
		fmt.Println("OK:", cmd)
	case "status":
		svc, err := newService("")
		if err != nil {
			log.Fatal(err)
		}
		st, err := svc.Status()
		if err != nil {
			fmt.Println("Status: nicht installiert oder unbekannt")
			os.Exit(1)
		}
		fmt.Println(map[service.Status]string{service.StatusRunning: "Status: läuft", service.StatusStopped: "Status: gestoppt"}[st])
	default:
		fmt.Printf(usage, agent.Version)
		os.Exit(2)
	}
}
