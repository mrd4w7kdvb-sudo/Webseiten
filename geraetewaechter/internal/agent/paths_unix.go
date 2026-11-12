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
