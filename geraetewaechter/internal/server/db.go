package server

import (
	"database/sql"
	"fmt"

	_ "modernc.org/sqlite"
)

const schema = `
PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;

CREATE TABLE IF NOT EXISTS tenants (
	id              INTEGER PRIMARY KEY AUTOINCREMENT,
	name            TEXT NOT NULL,
	branch          TEXT NOT NULL DEFAULT 'sonstige',
	contact         TEXT NOT NULL DEFAULT '',
	notes           TEXT NOT NULL DEFAULT '',
	enroll_token    TEXT NOT NULL UNIQUE,
	interval_sec    INTEGER NOT NULL DEFAULT 60,
	watch_processes TEXT NOT NULL DEFAULT '[]',
	watch_services  TEXT NOT NULL DEFAULT '[]',
	created_at      INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS agents (
	id               TEXT PRIMARY KEY,
	tenant_id        INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
	display_name     TEXT NOT NULL,
	key_hash         TEXT NOT NULL,
	os               TEXT NOT NULL DEFAULT '',
	platform         TEXT NOT NULL DEFAULT '',
	platform_version TEXT NOT NULL DEFAULT '',
	kernel           TEXT NOT NULL DEFAULT '',
	arch             TEXT NOT NULL DEFAULT '',
	agent_version    TEXT NOT NULL DEFAULT '',
	uptime           INTEGER NOT NULL DEFAULT 0,
	created_at       INTEGER NOT NULL,
	last_seen        INTEGER NOT NULL DEFAULT 0,
	disabled         INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS metrics (
	agent_id TEXT NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
	ts       INTEGER NOT NULL,
	name     TEXT NOT NULL,
	labels   TEXT NOT NULL DEFAULT '',
	value    REAL NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_metrics ON metrics(agent_id, name, ts);
CREATE INDEX IF NOT EXISTS idx_metrics_ts ON metrics(ts);

CREATE TABLE IF NOT EXISTS latest (
	agent_id TEXT NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
	name     TEXT NOT NULL,
	labels   TEXT NOT NULL DEFAULT '',
	value    REAL NOT NULL,
	ts       INTEGER NOT NULL,
	PRIMARY KEY (agent_id, name, labels)
);

CREATE TABLE IF NOT EXISTS checks (
	agent_id TEXT NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
	kind     TEXT NOT NULL,
	name     TEXT NOT NULL,
	ok       INTEGER NOT NULL,
	detail   TEXT NOT NULL DEFAULT '',
	ts       INTEGER NOT NULL,
	PRIMARY KEY (agent_id, kind, name)
);

CREATE TABLE IF NOT EXISTS logs (
	id          INTEGER PRIMARY KEY AUTOINCREMENT,
	agent_id    TEXT NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
	ts          INTEGER NOT NULL,
	received    INTEGER NOT NULL,
	channel     TEXT NOT NULL,
	source      TEXT NOT NULL,
	level       INTEGER NOT NULL,
	event_id    TEXT NOT NULL DEFAULT '',
	message     TEXT NOT NULL DEFAULT '',
	fingerprint TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_logs ON logs(agent_id, ts);
CREATE INDEX IF NOT EXISTS idx_logs_recv ON logs(received);

CREATE TABLE IF NOT EXISTS channels (
	id      INTEGER PRIMARY KEY AUTOINCREMENT,
	name    TEXT NOT NULL,
	kind    TEXT NOT NULL,
	config  TEXT NOT NULL DEFAULT '{}',
	enabled INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS rules (
	id             INTEGER PRIMARY KEY AUTOINCREMENT,
	name           TEXT NOT NULL,
	enabled        INTEGER NOT NULL DEFAULT 1,
	tenant_id      INTEGER REFERENCES tenants(id) ON DELETE CASCADE,
	agent_id       TEXT REFERENCES agents(id) ON DELETE CASCADE,
	kind           TEXT NOT NULL,
	metric         TEXT NOT NULL DEFAULT '',
	label_filter   TEXT NOT NULL DEFAULT '',
	op             TEXT NOT NULL DEFAULT '>',
	threshold      REAL NOT NULL DEFAULT 0,
	duration_min   INTEGER NOT NULL DEFAULT 0,
	log_max_level  INTEGER NOT NULL DEFAULT 3,
	log_source_re  TEXT NOT NULL DEFAULT '',
	log_message_re TEXT NOT NULL DEFAULT '',
	log_count      INTEGER NOT NULL DEFAULT 1,
	severity       TEXT NOT NULL DEFAULT 'warnung',
	channels       TEXT NOT NULL DEFAULT '[]',
	repeat_min     INTEGER NOT NULL DEFAULT 0,
	created_at     INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS alerts (
	id            INTEGER PRIMARY KEY AUTOINCREMENT,
	rule_id       INTEGER NOT NULL REFERENCES rules(id) ON DELETE CASCADE,
	agent_id      TEXT NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
	labels        TEXT NOT NULL DEFAULT '',
	state         TEXT NOT NULL,
	value         REAL NOT NULL DEFAULT 0,
	message       TEXT NOT NULL DEFAULT '',
	started_at    INTEGER NOT NULL,
	resolved_at   INTEGER NOT NULL DEFAULT 0,
	acked_by      TEXT NOT NULL DEFAULT '',
	acked_at      INTEGER NOT NULL DEFAULT 0,
	last_notified INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_alerts_state ON alerts(state);

CREATE TABLE IF NOT EXISTS users (
	id         INTEGER PRIMARY KEY AUTOINCREMENT,
	username   TEXT NOT NULL UNIQUE,
	pw_hash    TEXT NOT NULL,
	created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS sessions (
	token_hash TEXT PRIMARY KEY,
	user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
	expires    INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
	key   TEXT PRIMARY KEY,
	value TEXT NOT NULL
);
`

func openDB(path string) (*sql.DB, error) {
	db, err := sql.Open("sqlite", "file:"+path+"?_pragma=busy_timeout(10000)&_pragma=foreign_keys(1)")
	if err != nil {
		return nil, err
	}
	// SQLite verträgt nur einen Schreiber gleichzeitig.
	db.SetMaxOpenConns(1)
	if _, err := db.Exec(schema); err != nil {
		return nil, fmt.Errorf("schema: %w", err)
	}
	return db, nil
}
