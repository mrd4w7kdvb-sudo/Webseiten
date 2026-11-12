package server

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha1" //nolint:gosec // RFC 6238 verwendet HMAC-SHA1; alle gängigen Authenticator-Apps erwarten das
	"database/sql"
	"encoding/base32"
	"encoding/binary"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"time"

	"golang.org/x/crypto/bcrypt"
)

// Zwei-Faktor-Anmeldung mit zeitbasierten Einmalcodes (TOTP, RFC 6238),
// kompatibel mit Microsoft/Google Authenticator, Aegis, 2FAS, Bitwarden, …

const totpStep = 30

var b32 = base32.StdEncoding.WithPadding(base32.NoPadding)

func newTOTPSecret() string {
	b := make([]byte, 20)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return b32.EncodeToString(b)
}

func totpCode(secret string, counter int64) (string, error) {
	key, err := b32.DecodeString(strings.ToUpper(strings.ReplaceAll(secret, " ", "")))
	if err != nil {
		return "", err
	}
	var msg [8]byte
	binary.BigEndian.PutUint64(msg[:], uint64(counter))
	m := hmac.New(sha1.New, key)
	m.Write(msg[:])
	sum := m.Sum(nil)
	off := sum[len(sum)-1] & 0x0f
	v := binary.BigEndian.Uint32(sum[off:off+4]) & 0x7fffffff
	return fmt.Sprintf("%06d", v%1000000), nil
}

// totpVerify prüft einen Code (±30 s Toleranz) und liefert den verwendeten
// Zeitschritt. Bereits verwendete Schritte (<= last) werden abgelehnt, damit
// ein abgefangener Code nicht ein zweites Mal funktioniert.
func totpVerify(secret, code string, last int64, now time.Time) (int64, bool) {
	code = strings.ReplaceAll(strings.TrimSpace(code), " ", "")
	if len(code) != 6 {
		return 0, false
	}
	cur := now.Unix() / totpStep
	for _, c := range []int64{cur - 1, cur, cur + 1} {
		if c <= last {
			continue
		}
		want, err := totpCode(secret, c)
		if err == nil && hmac.Equal([]byte(want), []byte(code)) {
			return c, true
		}
	}
	return 0, false
}

// checkTOTP prüft den Code eines Benutzers mit aktiver Zwei-Faktor-Anmeldung.
func (s *Server) checkTOTP(userID int64, code string) bool {
	var secret string
	var last int64
	if err := s.db.QueryRow(`SELECT totp_secret, totp_last FROM users WHERE id=?`, userID).Scan(&secret, &last); err != nil {
		return false
	}
	step, ok := totpVerify(secret, code, last, time.Now())
	if !ok {
		return false
	}
	_, _ = s.db.Exec(`UPDATE users SET totp_last=? WHERE id=?`, step, userID)
	return true
}

func (s *Server) totpEnabled(userID int64) bool {
	var en int
	_ = s.db.QueryRow(`SELECT totp_enabled FROM users WHERE id=?`, userID).Scan(&en)
	return en != 0
}

func (s *Server) handleTOTPSetup(w http.ResponseWriter, r *http.Request, u *User) {
	if s.totpEnabled(u.ID) {
		httpErr(w, 400, "Zwei-Faktor-Anmeldung ist bereits aktiv")
		return
	}
	secret := newTOTPSecret()
	if _, err := s.db.Exec(`UPDATE users SET totp_secret=?, totp_last=0 WHERE id=?`, secret, u.ID); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	host := "Geraetewaechter"
	if pu, err := url.Parse(s.cfg.PublicURL); err == nil && pu.Host != "" {
		host = pu.Hostname()
	}
	label := url.PathEscape("Gerätewächter (" + host + "):" + u.Username)
	uri := "otpauth://totp/" + label + "?secret=" + secret + "&issuer=" + url.QueryEscape("Gerätewächter") + "&algorithm=SHA1&digits=6&period=30"
	// Für die manuelle Eingabe in Vierergruppen
	var grouped []string
	for i := 0; i < len(secret); i += 4 {
		grouped = append(grouped, secret[i:min(i+4, len(secret))])
	}
	writeJSON(w, 200, map[string]string{"secret": strings.Join(grouped, " "), "uri": uri})
}

func (s *Server) handleTOTPEnable(w http.ResponseWriter, r *http.Request, u *User) {
	var req struct{ Code string }
	if err := readJSON(r, &req, 1024); err != nil {
		httpErr(w, 400, "ungültig")
		return
	}
	if !s.checkTOTP(u.ID, req.Code) {
		httpErr(w, 400, "Code falsch – Uhrzeit des Handys prüfen und erneut versuchen")
		return
	}
	if _, err := s.db.Exec(`UPDATE users SET totp_enabled=1 WHERE id=?`, u.ID); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func (s *Server) handleTOTPDisable(w http.ResponseWriter, r *http.Request, u *User) {
	var req struct{ Password, Code string }
	if err := readJSON(r, &req, 1024); err != nil {
		httpErr(w, 400, "ungültig")
		return
	}
	var hash string
	if err := s.db.QueryRow(`SELECT pw_hash FROM users WHERE id=?`, u.ID).Scan(&hash); err != nil ||
		bcrypt.CompareHashAndPassword([]byte(hash), []byte(req.Password)) != nil || !s.checkTOTP(u.ID, req.Code) {
		httpErr(w, 403, "Passwort oder Code falsch")
		return
	}
	if _, err := s.db.Exec(`UPDATE users SET totp_enabled=0, totp_secret='' WHERE id=?`, u.ID); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

// ResetTOTP schaltet die Zwei-Faktor-Anmeldung eines Benutzers ab (Notfall per Kommandozeile).
func ResetTOTP(db *sql.DB, username string) error {
	res, err := db.Exec(`UPDATE users SET totp_enabled=0, totp_secret='', totp_last=0 WHERE username=?`, username)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return fmt.Errorf("Benutzer %q nicht gefunden", username)
	}
	_, err = db.Exec(`DELETE FROM sessions WHERE user_id=(SELECT id FROM users WHERE username=?)`, username)
	return err
}
