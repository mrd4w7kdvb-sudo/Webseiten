package server

import (
	"context"
	"database/sql"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// Backup erstellt eine konsistente Kopie der Datenbank (auch im laufenden Betrieb).
func Backup(db *sql.DB, target string) error {
	if err := os.MkdirAll(filepath.Dir(target), 0o700); err != nil {
		return err
	}
	_ = os.Remove(target)
	if _, err := db.Exec(`VACUUM INTO ?`, target); err != nil {
		return err
	}
	return os.Chmod(target, 0o600)
}

// autoBackup legt einmal täglich eine Sicherung in DATA/backups an und
// behält die letzten BackupKeep Stück. Diese Sicherungen liegen auf
// demselben Server – für Schutz vor Totalausfall zusätzlich extern kopieren.
func (s *Server) autoBackup(now time.Time) {
	if s.cfg.BackupKeep <= 0 {
		return
	}
	dir := filepath.Join(s.cfg.DataDir, "backups")
	name := filepath.Join(dir, "geraetewaechter-"+now.Format("2006-01-02")+".db")
	if _, err := os.Stat(name); err == nil {
		return
	}
	if err := Backup(s.db, name); err != nil {
		log.Printf("Sicherung fehlgeschlagen: %v", err)
		return
	}
	ents, _ := os.ReadDir(dir)
	var files []string
	for _, e := range ents {
		if strings.HasPrefix(e.Name(), "geraetewaechter-") && strings.HasSuffix(e.Name(), ".db") {
			files = append(files, e.Name())
		}
	}
	sort.Strings(files)
	for len(files) > s.cfg.BackupKeep {
		_ = os.Remove(filepath.Join(dir, files[0]))
		files = files[1:]
	}
}

// heartbeat meldet einem externen Wächter (z.B. healthchecks.io, Uptime
// Kuma, Better Stack), dass der Server läuft. Bleibt das Signal aus, alarmiert
// der externe Dienst – so fällt auch ein Ausfall des Monitorings selbst auf.
func (s *Server) heartbeat() {
	if s.cfg.HeartbeatURL == "" {
		return
	}
	go func() {
		// Zusätzlich prüfen, ob die Datenbank antwortet.
		url := s.cfg.HeartbeatURL
		if err := s.db.Ping(); err != nil {
			url = strings.TrimRight(url, "/") + "/fail"
		}
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		if err != nil {
			return
		}
		resp, err := httpClient.Do(req)
		if err != nil {
			log.Printf("Lebenszeichen an %s fehlgeschlagen: %v", s.cfg.HeartbeatURL, err)
			return
		}
		resp.Body.Close()
		if resp.StatusCode >= 300 {
			log.Printf("Lebenszeichen: HTTP %d", resp.StatusCode)
		}
	}()
}

// sendWithRetry versucht eine Benachrichtigung bis zu dreimal.
func sendWithRetry(c Channel, n Notification) error {
	var err error
	for i, wait := range []time.Duration{0, 10 * time.Second, 60 * time.Second} {
		time.Sleep(wait)
		if err = deliver(c, n); err == nil {
			return nil
		}
		log.Printf("Benachrichtigung über %q, Versuch %d: %v", c.Name, i+1, err)
	}
	return fmt.Errorf("endgültig fehlgeschlagen: %w", err)
}
