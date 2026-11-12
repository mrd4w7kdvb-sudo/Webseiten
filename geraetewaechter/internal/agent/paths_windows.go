//go:build windows

package agent

import (
	"os"
	"path/filepath"

	"golang.org/x/sys/windows"
)

func programData() string {
	if p := os.Getenv("ProgramData"); p != "" {
		return p
	}
	return `C:\ProgramData`
}

func baseConfigDir() string { return filepath.Join(programData(), "Geraetewaechter") }
func baseDataDir() string   { return filepath.Join(programData(), "Geraetewaechter", "data") }

// restrictPermissions entzieht normalen Benutzern den Zugriff auf den
// Agenten-Schlüssel: nur SYSTEM und Administratoren, Vererbung geschützt.
// SetNamedSecurityInfo überträgt die Rechte auch auf vorhandene Dateien.
func restrictPermissions(dir string) error {
	sd, err := windows.SecurityDescriptorFromString("D:PAI(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)")
	if err != nil {
		return err
	}
	dacl, _, err := sd.DACL()
	if err != nil {
		return err
	}
	return windows.SetNamedSecurityInfo(dir, windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION, nil, nil, dacl, nil)
}

// InstallPath ist der Zielort des Agenten bei "gw-agent setup".
func InstallPath() string {
	pf := os.Getenv("ProgramFiles")
	if pf == "" {
		pf = `C:\Program Files`
	}
	return filepath.Join(pf, "Geraetewaechter", "gw-agent.exe")
}

// IsAdmin meldet, ob der Prozess mit Administratorrechten läuft.
func IsAdmin() bool {
	return windows.GetCurrentProcessToken().IsElevated()
}
