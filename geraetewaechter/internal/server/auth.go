package server

import (
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"log"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/bcrypt"
)

const sessionCookie = "gw_session"
const sessionTTL = 12 * time.Hour

// User ist ein Administrator der Weboberfläche.
type User struct {
	ID       int64  `json:"id"`
	Username string `json:"username"`
}

func randomToken(n int) string {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(err)
	}
	return base64.RawURLEncoding.EncodeToString(b)
}

func sha256hex(s string) string {
	h := sha256.Sum256([]byte(s))
	return hex.EncodeToString(h[:])
}

func (s *Server) bootstrapAdmin() error {
	var n int
	if err := s.db.QueryRow(`SELECT COUNT(*) FROM users`).Scan(&n); err != nil {
		return err
	}
	if n > 0 {
		return nil
	}
	pw := s.cfg.AdminPassword
	generated := false
	if pw == "" {
		pw = randomToken(12)
		generated = true
	}
	if err := SetPassword(s.db, s.cfg.AdminUser, pw); err != nil {
		return err
	}
	if generated {
		log.Printf("ERSTER START: Admin %q mit Passwort %q angelegt – bitte sofort ändern!", s.cfg.AdminUser, pw)
	} else {
		log.Printf("ERSTER START: Admin %q angelegt (Passwort aus GW_ADMIN_PASSWORD).", s.cfg.AdminUser)
	}
	return nil
}

// SetPassword legt einen Benutzer an oder setzt dessen Passwort.
func SetPassword(db *sql.DB, username, password string) error {
	username = strings.TrimSpace(username)
	if username == "" {
		return errors.New("Benutzername fehlt")
	}
	if len(password) < 10 {
		return errors.New("Passwort muss mindestens 10 Zeichen haben")
	}
	h, err := bcrypt.GenerateFromPassword([]byte(password), 12)
	if err != nil {
		return err
	}
	_, err = db.Exec(`INSERT INTO users(username, pw_hash, created_at) VALUES(?,?,?)
		ON CONFLICT(username) DO UPDATE SET pw_hash=excluded.pw_hash`, username, string(h), time.Now().Unix())
	if err == nil {
		_, err = db.Exec(`DELETE FROM sessions WHERE user_id=(SELECT id FROM users WHERE username=?)`, username)
	}
	return err
}

func (s *Server) requireUser(h func(http.ResponseWriter, *http.Request, *User)) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := r.Cookie(sessionCookie)
		if err != nil || c.Value == "" {
			httpErr(w, http.StatusUnauthorized, "nicht angemeldet")
			return
		}
		u := &User{}
		err = s.db.QueryRow(`SELECT u.id, u.username FROM sessions s JOIN users u ON u.id=s.user_id
			WHERE s.token_hash=? AND s.expires>?`, sha256hex(c.Value), time.Now().Unix()).Scan(&u.ID, &u.Username)
		if err != nil {
			httpErr(w, http.StatusUnauthorized, "Sitzung abgelaufen")
			return
		}
		// CSRF-Schutz: verändernde Anfragen müssen vom eigenen Frontend kommen.
		if r.Method != http.MethodGet && r.Header.Get("X-GW-Request") != "1" {
			httpErr(w, http.StatusForbidden, "ungültige Anfrage")
			return
		}
		h(w, r, u)
	})
}

func (s *Server) handleLogin(w http.ResponseWriter, r *http.Request) {
	ip := clientKey(r)
	if !s.limiter.allow(ip) {
		httpErr(w, http.StatusTooManyRequests, "zu viele Versuche, bitte später erneut")
		return
	}
	var req struct{ Username, Password, Code string }
	if err := readJSON(r, &req, 4096); err != nil {
		httpErr(w, http.StatusBadRequest, "ungültig")
		return
	}
	// zusätzlich je Benutzername (verteilte Rateversuche von vielen Adressen)
	userKey := "user:" + strings.ToLower(strings.TrimSpace(req.Username))
	if !s.limiter.allowN(userKey, 30) {
		httpErr(w, http.StatusTooManyRequests, "Konto vorübergehend gesperrt (zu viele Fehlversuche), bitte in 15 Minuten erneut")
		return
	}
	var id int64
	var hash string
	err := s.db.QueryRow(`SELECT id, pw_hash FROM users WHERE username=?`, strings.TrimSpace(req.Username)).Scan(&id, &hash)
	if err != nil {
		// gleiche Laufzeit wie bei falschem Passwort
		_ = bcrypt.CompareHashAndPassword(dummyHash(), []byte(req.Password))
		s.limiter.fail(ip)
		httpErr(w, http.StatusUnauthorized, "Anmeldung fehlgeschlagen")
		return
	}
	if bcrypt.CompareHashAndPassword([]byte(hash), []byte(req.Password)) != nil {
		s.limiter.fail(ip)
		s.limiter.fail(userKey)
		httpErr(w, http.StatusUnauthorized, "Anmeldung fehlgeschlagen")
		return
	}
	if s.totpEnabled(id) {
		if strings.TrimSpace(req.Code) == "" {
			writeJSON(w, http.StatusUnauthorized, map[string]any{"error": "Bestätigungscode erforderlich", "totp_required": true})
			return
		}
		if !s.checkTOTP(id, req.Code) {
			s.limiter.fail(ip)
			s.limiter.fail(userKey)
			writeJSON(w, http.StatusUnauthorized, map[string]any{"error": "Bestätigungscode falsch", "totp_required": true})
			return
		}
	}
	tok := randomToken(32)
	exp := time.Now().Add(sessionTTL)
	if _, err := s.db.Exec(`INSERT INTO sessions(token_hash,user_id,expires) VALUES(?,?,?)`, sha256hex(tok), id, exp.Unix()); err != nil {
		httpErr(w, http.StatusInternalServerError, "Fehler")
		return
	}
	http.SetCookie(w, &http.Cookie{
		Name: sessionCookie, Value: tok, Path: "/", Expires: exp,
		HttpOnly: true, Secure: s.cfg.CookieSecure, SameSite: http.SameSiteStrictMode,
	})
	writeJSON(w, http.StatusOK, User{ID: id, Username: req.Username})
}

func (s *Server) handleLogout(w http.ResponseWriter, r *http.Request) {
	if c, err := r.Cookie(sessionCookie); err == nil {
		_, _ = s.db.Exec(`DELETE FROM sessions WHERE token_hash=?`, sha256hex(c.Value))
	}
	http.SetCookie(w, &http.Cookie{Name: sessionCookie, Value: "", Path: "/", MaxAge: -1, HttpOnly: true, Secure: s.cfg.CookieSecure, SameSite: http.SameSiteStrictMode})
	writeJSON(w, http.StatusOK, map[string]bool{"ok": true})
}

func (s *Server) handleMe(w http.ResponseWriter, r *http.Request, u *User) {
	writeJSON(w, http.StatusOK, map[string]any{"user": u, "public_url": s.cfg.PublicURL, "totp_enabled": s.totpEnabled(u.ID),
		"retention": map[string]int{"metrics": s.cfg.MetricRetention, "logs": s.cfg.LogRetention, "alerts": s.cfg.AlertRetention}})
}

func (s *Server) handlePassword(w http.ResponseWriter, r *http.Request, u *User) {
	var req struct{ Old, New string }
	if err := readJSON(r, &req, 4096); err != nil {
		httpErr(w, http.StatusBadRequest, "ungültig")
		return
	}
	var hash string
	if err := s.db.QueryRow(`SELECT pw_hash FROM users WHERE id=?`, u.ID).Scan(&hash); err != nil ||
		bcrypt.CompareHashAndPassword([]byte(hash), []byte(req.Old)) != nil {
		httpErr(w, http.StatusForbidden, "altes Passwort falsch")
		return
	}
	if err := SetPassword(s.db, u.Username, req.New); err != nil {
		httpErr(w, http.StatusBadRequest, err.Error())
		return
	}
	writeJSON(w, http.StatusOK, map[string]bool{"ok": true})
}

// ---------- Schutz gegen Passwort-Raten (nur im Arbeitsspeicher) ----------

type loginLimiter struct {
	mu   sync.Mutex
	hits map[string][]time.Time
}

func newLoginLimiter() *loginLimiter { return &loginLimiter{hits: map[string][]time.Time{}} }

func (l *loginLimiter) allow(k string) bool { return l.allowN(k, 8) }

func (l *loginLimiter) allowN(k string, limit int) bool {
	l.mu.Lock()
	defer l.mu.Unlock()
	cut := time.Now().Add(-15 * time.Minute)
	keep := l.hits[k][:0]
	for _, t := range l.hits[k] {
		if t.After(cut) {
			keep = append(keep, t)
		}
	}
	l.hits[k] = keep
	if len(l.hits) > 10000 { // Speicher begrenzen
		l.hits = map[string][]time.Time{}
	}
	return len(keep) < limit
}

func (l *loginLimiter) fail(k string) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.hits[k] = append(l.hits[k], time.Now())
}

// clientKey liefert einen Hash der Client-IP (die IP selbst wird nirgends gespeichert).
func clientKey(r *http.Request) string {
	ip, _, _ := net.SplitHostPort(r.RemoteAddr)
	// X-Forwarded-For nur auswerten, wenn die Anfrage vom eigenen Proxy
	// (Caddy im selben Docker-Netz bzw. auf demselben Rechner) kommt. Sonst
	// könnte ein Angreifer den Header fälschen und die Sperre umgehen.
	if remote := net.ParseIP(ip); remote != nil && (remote.IsLoopback() || remote.IsPrivate()) {
		if xff := r.Header.Get("X-Forwarded-For"); xff != "" {
			parts := strings.Split(xff, ",")
			ip = strings.TrimSpace(parts[len(parts)-1]) // vom eigenen Proxy gesetzter Eintrag
		}
	}
	return sha256hex(strings.TrimSpace(ip))[:16]
}

var (
	dummyOnce sync.Once
	dummy     []byte
)

func dummyHash() []byte {
	dummyOnce.Do(func() { dummy, _ = bcrypt.GenerateFromPassword([]byte(randomToken(8)), 12) })
	return dummy
}

func constEq(a, b string) bool {
	return subtle.ConstantTimeCompare([]byte(a), []byte(b)) == 1
}
