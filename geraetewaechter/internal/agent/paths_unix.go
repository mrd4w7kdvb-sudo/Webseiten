//go:build !windows

package agent

import (
	"os"
	"runtime"
)

func baseConfigDir() string {
	if runtime.GOOS == "darwin" {
		return "/Library/Application Support/Geraetewaechter"
	}
	return "/etc/geraetewaechter"
}

func baseDataDir() string {
	if runtime.GOOS == "darwin" {
		return "/Library/Application Support/Geraetewaechter/data"
	}
	return "/var/lib/geraetewaechter"
}

func restrictPermissions(dir string) error {
	return os.Chmod(dir, 0o700)
}

// InstallPath ist der Zielort des Agenten bei "gw-agent setup".
func InstallPath() string { return "/usr/local/bin/gw-agent" }

// IsAdmin meldet, ob der Prozess als root läuft.
func IsAdmin() bool { return os.Geteuid() == 0 }
