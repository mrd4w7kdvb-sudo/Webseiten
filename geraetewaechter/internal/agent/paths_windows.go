//go:build windows

package agent

import (
	"os"
	"os/exec"
	"path/filepath"
)

func programData() string {
	if p := os.Getenv("ProgramData"); p != "" {
		return p
	}
	return `C:\ProgramData`
}

func baseConfigDir() string { return filepath.Join(programData(), "Geraetewaechter") }
func baseDataDir() string   { return filepath.Join(programData(), "Geraetewaechter", "data") }

// restrictPermissions entzieht normalen Benutzern den Lesezugriff auf den
// Agenten-Schlüssel (nur SYSTEM und Administratoren).
func restrictPermissions(dir string) error {
	return exec.Command("icacls", dir, "/inheritance:r",
		"/grant:r", "*S-1-5-18:(OI)(CI)F", // SYSTEM
		"/grant:r", "*S-1-5-32-544:(OI)(CI)F", // Administratoren
	).Run()
}
