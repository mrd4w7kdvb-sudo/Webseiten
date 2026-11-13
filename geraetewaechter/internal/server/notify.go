package server

import (
	"bytes"
	"crypto/tls"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net"
	"net/http"
	"net/smtp"
	"strings"
	"time"
)

// Notification ist der Inhalt einer Alarm-Benachrichtigung. Sie enthält nur
// Kunden- und Gerätebezeichnungen aus der eigenen Verwaltung, keine Daten
// vom Kundengerät außer dem bereinigten Alarmtext.
type Notification struct {
	Event    string    `json:"event"` // ausgelöst | behoben | erinnerung | test
	Severity string    `json:"severity"`
	Rule     string    `json:"rule"`
	Tenant   string    `json:"tenant"`
	Device   string    `json:"device"`
	DeviceID string    `json:"device_id"`
	Labels   string    `json:"labels,omitempty"`
	Message  string    `json:"message"`
	Time     time.Time `json:"time"`
	Link     string    `json:"link,omitempty"`
}

func (n Notification) subject() string {
	icon := map[string]string{"kritisch": "🔴", "warnung": "🟠", "info": "🔵"}[n.Severity]
	if n.Event == "behoben" {
		icon = "✅"
	}
	return fmt.Sprintf("%s [%s] %s – %s / %s", icon, strings.ToUpper(n.Event), n.Rule, n.Tenant, n.Device)
}

func (n Notification) body() string {
	var b strings.Builder
	fmt.Fprintf(&b, "Ereignis:   %s\n", n.Event)
	fmt.Fprintf(&b, "Schwere:    %s\n", n.Severity)
	fmt.Fprintf(&b, "Regel:      %s\n", n.Rule)
	fmt.Fprintf(&b, "Kunde:      %s\n", n.Tenant)
	fmt.Fprintf(&b, "Gerät:      %s\n", n.Device)
	if n.Labels != "" {
		fmt.Fprintf(&b, "Details:    %s\n", n.Labels)
	}
	fmt.Fprintf(&b, "Meldung:    %s\n", n.Message)
	fmt.Fprintf(&b, "Zeit:       %s\n", n.Time.Local().Format("02.01.2006 15:04:05"))
	if n.Link != "" {
		fmt.Fprintf(&b, "\n%s\n", n.Link)
	}
	return b.String()
}

// Channel ist ein Benachrichtigungskanal.
type Channel struct {
	ID      int64          `json:"id"`
	Name    string         `json:"name"`
	Kind    string         `json:"kind"` // email | webhook | ntfy | telegram
	Config  map[string]any `json:"config"`
	Enabled bool           `json:"enabled"`
}

var secretKeys = []string{"password", "token", "bot_token"}

const secretMask = "••••••••"

// Notifier verschickt Benachrichtigungen.
type Notifier struct {
	db        *sql.DB
	publicURL string
}

func (n *Notifier) load(ids []int64) []Channel {
	var out []Channel
	for _, id := range ids {
		c, err := loadChannel(n.db, id)
		if err == nil && c.Enabled {
			out = append(out, *c)
		}
	}
	return out
}

// Send verschickt n an alle angegebenen Kanäle.
func (n *Notifier) Send(ids []int64, msg Notification) {
	if msg.Link == "" && msg.DeviceID != "" {
		msg.Link = n.publicURL + "/#/geraet/" + msg.DeviceID
	}
	for _, c := range n.load(ids) {
		go func(c Channel) {
			if err := sendWithRetry(c, msg); err != nil {
				log.Printf("Benachrichtigung über %q: %v", c.Name, err)
			}
		}(c)
	}
}

func cfgStr(c map[string]any, k string) string {
	if v, ok := c[k]; ok {
		switch t := v.(type) {
		case string:
			return strings.TrimSpace(t)
		case float64:
			return fmt.Sprintf("%d", int64(t))
		}
	}
	return ""
}

var httpClient = &http.Client{Timeout: 15 * time.Second}

func deliver(c Channel, n Notification) error {
	switch c.Kind {
	case "email":
		return sendMail(c.Config, n)
	case "webhook":
		body, _ := json.Marshal(map[string]any{"text": n.subject() + "\n" + n.body(), "notification": n})
		req, err := http.NewRequest(http.MethodPost, cfgStr(c.Config, "url"), bytes.NewReader(body))
		if err != nil {
			return err
		}
		req.Header.Set("Content-Type", "application/json")
		if t := cfgStr(c.Config, "token"); t != "" {
			req.Header.Set("Authorization", "Bearer "+t)
		}
		return doReq(req)
	case "ntfy":
		req, err := http.NewRequest(http.MethodPost, cfgStr(c.Config, "url"), strings.NewReader(n.body()))
		if err != nil {
			return err
		}
		req.Header.Set("Title", strings.Map(func(r rune) rune {
			if r > 127 {
				return -1
			}
			return r
		}, n.subject()))
		prio := map[string]string{"kritisch": "urgent", "warnung": "high", "info": "default"}[n.Severity]
		if n.Event == "behoben" {
			prio = "default"
		}
		req.Header.Set("Priority", prio)
		req.Header.Set("Tags", n.Severity)
		if n.Link != "" {
			req.Header.Set("Click", n.Link)
		}
		if t := cfgStr(c.Config, "token"); t != "" {
			req.Header.Set("Authorization", "Bearer "+t)
		}
		return doReq(req)
	case "telegram":
		body, _ := json.Marshal(map[string]any{"chat_id": cfgStr(c.Config, "chat_id"), "text": n.subject() + "\n\n" + n.body(), "disable_web_page_preview": true})
		req, err := http.NewRequest(http.MethodPost, "https://api.telegram.org/bot"+cfgStr(c.Config, "bot_token")+"/sendMessage", bytes.NewReader(body))
		if err != nil {
			return err
		}
		req.Header.Set("Content-Type", "application/json")
		return doReq(req)
	}
	return errors.New("unbekannter Kanaltyp")
}

func doReq(req *http.Request) error {
	resp, err := httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 300 {
		return fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	return nil
}

func sendMail(cfg map[string]any, n Notification) error {
	host := cfgStr(cfg, "host")
	port := cfgStr(cfg, "port")
	if port == "" {
		port = "587"
	}
	from := cfgStr(cfg, "from")
	var to []string
	for _, t := range strings.Split(cfgStr(cfg, "to"), ",") {
		if t = strings.TrimSpace(t); t != "" {
			to = append(to, t)
		}
	}
	if host == "" || from == "" || len(to) == 0 {
		return errors.New("E-Mail-Kanal unvollständig (host, from, to)")
	}
	msg := "From: " + from + "\r\nTo: " + strings.Join(to, ", ") +
		"\r\nSubject: =?UTF-8?B?" + b64(n.subject()) + "?=" +
		"\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit" +
		"\r\nDate: " + time.Now().Format(time.RFC1123Z) + "\r\n\r\n" + strings.ReplaceAll(n.body(), "\n", "\r\n")
	addr := net.JoinHostPort(host, port)
	var auth smtp.Auth
	if u := cfgStr(cfg, "username"); u != "" {
		auth = smtp.PlainAuth("", u, cfgStr(cfg, "password"), host)
	}
	if port == "465" { // implizites TLS
		conn, err := tls.DialWithDialer(&net.Dialer{Timeout: 15 * time.Second}, "tcp", addr, &tls.Config{ServerName: host, MinVersion: tls.VersionTLS12})
		if err != nil {
			return err
		}
		c, err := smtp.NewClient(conn, host)
		if err != nil {
			return err
		}
		defer c.Close()
		if auth != nil {
			if err := c.Auth(auth); err != nil {
				return err
			}
		}
		if err := c.Mail(from); err != nil {
			return err
		}
		for _, t := range to {
			if err := c.Rcpt(t); err != nil {
				return err
			}
		}
		wc, err := c.Data()
		if err != nil {
			return err
		}
		if _, err := wc.Write([]byte(msg)); err != nil {
			return err
		}
		if err := wc.Close(); err != nil {
			return err
		}
		return c.Quit()
	}
	// Port 587/25: STARTTLS (net/smtp erzwingt TLS, bevor Zugangsdaten gesendet werden)
	return smtp.SendMail(addr, auth, from, to, []byte(msg))
}

// ---------- Kanal-Verwaltung ----------

func loadChannel(db *sql.DB, id int64) (*Channel, error) {
	var c Channel
	var cfg string
	var en int
	if err := db.QueryRow(`SELECT id, name, kind, config, enabled FROM channels WHERE id=?`, id).Scan(&c.ID, &c.Name, &c.Kind, &cfg, &en); err != nil {
		return nil, err
	}
	c.Enabled = en != 0
	_ = json.Unmarshal([]byte(cfg), &c.Config)
	if c.Config == nil {
		c.Config = map[string]any{}
	}
	return &c, nil
}

func maskSecrets(c *Channel) {
	for _, k := range secretKeys {
		if cfgStr(c.Config, k) != "" {
			c.Config[k] = secretMask
		}
	}
}

func (s *Server) handleChannelList(w http.ResponseWriter, r *http.Request, _ *User) {
	rows, err := s.db.Query(`SELECT id FROM channels ORDER BY name`)
	if err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	var ids []int64
	for rows.Next() {
		var id int64
		if rows.Scan(&id) == nil {
			ids = append(ids, id)
		}
	}
	rows.Close()
	out := []Channel{}
	for _, id := range ids {
		if c, err := loadChannel(s.db, id); err == nil {
			maskSecrets(c)
			out = append(out, *c)
		}
	}
	writeJSON(w, 200, out)
}

func (s *Server) handleChannelSave(w http.ResponseWriter, r *http.Request, _ *User) {
	var c Channel
	if err := readJSON(r, &c, 64<<10); err != nil {
		httpErr(w, 400, "ungültig")
		return
	}
	c.Name = short(c.Name, 80)
	if c.Name == "" {
		httpErr(w, 400, "Name fehlt")
		return
	}
	switch c.Kind {
	case "email", "webhook", "ntfy", "telegram":
	default:
		httpErr(w, 400, "Kanaltyp ungültig")
		return
	}
	if c.Kind == "webhook" || c.Kind == "ntfy" {
		u := cfgStr(c.Config, "url")
		if !strings.HasPrefix(u, "https://") && !strings.HasPrefix(u, "http://") {
			httpErr(w, 400, "URL muss mit https:// beginnen")
			return
		}
	}
	if c.Config == nil {
		c.Config = map[string]any{}
	}
	id, isUpdate := pathInt(r, "id")
	if isUpdate {
		// maskierte Geheimnisse beibehalten
		if old, err := loadChannel(s.db, id); err == nil {
			for _, k := range secretKeys {
				if cfgStr(c.Config, k) == secretMask {
					c.Config[k] = old.Config[k]
				}
			}
		}
		if _, err := s.db.Exec(`UPDATE channels SET name=?, kind=?, config=?, enabled=? WHERE id=?`, c.Name, c.Kind, toJSON(c.Config), b2i(c.Enabled), id); err != nil {
			httpErr(w, 500, err.Error())
			return
		}
		c.ID = id
	} else {
		res, err := s.db.Exec(`INSERT INTO channels(name, kind, config, enabled) VALUES(?,?,?,?)`, c.Name, c.Kind, toJSON(c.Config), b2i(c.Enabled))
		if err != nil {
			httpErr(w, 500, err.Error())
			return
		}
		c.ID, _ = res.LastInsertId()
	}
	maskSecrets(&c)
	writeJSON(w, 200, c)
}

func (s *Server) handleChannelDelete(w http.ResponseWriter, r *http.Request, _ *User) {
	id, ok := pathInt(r, "id")
	if !ok {
		httpErr(w, 400, "id")
		return
	}
	if _, err := s.db.Exec(`DELETE FROM channels WHERE id=?`, id); err != nil {
		httpErr(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func (s *Server) handleChannelTest(w http.ResponseWriter, r *http.Request, _ *User) {
	id, ok := pathInt(r, "id")
	if !ok {
		httpErr(w, 400, "id")
		return
	}
	c, err := loadChannel(s.db, id)
	if err != nil {
		httpErr(w, 404, "Kanal nicht gefunden")
		return
	}
	n := Notification{Event: "test", Severity: "info", Rule: "Testnachricht", Tenant: "Gerätewächter", Device: "-",
		Message: "Dieser Kanal ist korrekt eingerichtet.", Time: time.Now(), Link: s.cfg.PublicURL}
	if err := deliver(*c, n); err != nil {
		httpErr(w, 502, "Versand fehlgeschlagen: "+err.Error())
		return
	}
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func b64(s string) string { return base64.StdEncoding.EncodeToString([]byte(s)) }
