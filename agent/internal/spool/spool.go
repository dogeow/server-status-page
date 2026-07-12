package spool

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/statusforge/status-agent/internal/model"
	_ "modernc.org/sqlite"
)

type Spool struct {
	db      *sql.DB
	maxRows int
}

type Entry struct {
	ID     int64
	Result model.Result
}

type Stats struct {
	Depth   int
	Dropped int64
}

func Open(path string, maxRows int) (*Spool, error) {
	if maxRows < 1 {
		return nil, errors.New("spool max rows must be positive")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return nil, fmt.Errorf("create spool directory: %w", err)
	}
	if file, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0600); err != nil {
		return nil, fmt.Errorf("create spool: %w", err)
	} else {
		_ = file.Close()
		if err := os.Chmod(path, 0600); err != nil {
			return nil, fmt.Errorf("secure spool: %w", err)
		}
	}
	db, err := sql.Open("sqlite", path)
	if err != nil {
		return nil, fmt.Errorf("open spool: %w", err)
	}
	db.SetMaxOpenConns(1)
	db.SetConnMaxLifetime(0)
	statements := []string{
		`PRAGMA journal_mode=WAL`,
		`PRAGMA synchronous=FULL`,
		`PRAGMA busy_timeout=5000`,
		`CREATE TABLE IF NOT EXISTS results (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			idempotency_key TEXT NOT NULL UNIQUE,
			payload BLOB NOT NULL,
			urgent INTEGER NOT NULL DEFAULT 0,
			created_at TEXT NOT NULL
		)`,
		`CREATE INDEX IF NOT EXISTS results_delivery_order ON results(id ASC)`,
		`CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value INTEGER NOT NULL)`,
		`INSERT OR IGNORE INTO metadata(key, value) VALUES ('dropped', 0)`,
	}
	for _, statement := range statements {
		if _, err := db.Exec(statement); err != nil {
			_ = db.Close()
			return nil, fmt.Errorf("initialize spool: %w", err)
		}
	}
	return &Spool{db: db, maxRows: maxRows}, nil
}

func (s *Spool) Close() error { return s.db.Close() }

func (s *Spool) Put(ctx context.Context, result model.Result) error {
	payload, err := json.Marshal(result)
	if err != nil {
		return fmt.Errorf("encode result: %w", err)
	}
	key := strings.Join([]string{result.MonitorID, result.AgentID, result.ScheduledAt.UTC().Format(time.RFC3339Nano), result.ConfigVersion}, "|")
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()
	if _, err := tx.ExecContext(ctx,
		`INSERT OR IGNORE INTO results(idempotency_key, payload, urgent, created_at) VALUES (?, ?, ?, ?)`,
		key, payload, result.Urgent(), time.Now().UTC().Format(time.RFC3339Nano),
	); err != nil {
		return fmt.Errorf("spool result: %w", err)
	}
	res, err := tx.ExecContext(ctx, `DELETE FROM results WHERE id IN (
		SELECT id FROM results ORDER BY id DESC LIMIT -1 OFFSET ?
	)`, s.maxRows)
	if err != nil {
		return fmt.Errorf("bound spool: %w", err)
	}
	deleted, _ := res.RowsAffected()
	if deleted > 0 {
		if _, err := tx.ExecContext(ctx, `UPDATE metadata SET value = value + ? WHERE key = 'dropped'`, deleted); err != nil {
			return err
		}
	}
	return tx.Commit()
}

func (s *Spool) Batch(ctx context.Context, limit int) ([]Entry, error) {
	if limit < 1 {
		return nil, errors.New("batch limit must be positive")
	}
	// Preserve execution order when backfilling after an outage. Prioritizing
	// failures ahead of earlier successes or later recoveries corrupts the state
	// machine timeline and can create a stale incident after reconnection.
	rows, err := s.db.QueryContext(ctx, `SELECT id, payload FROM results ORDER BY id ASC LIMIT ?`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	entries := make([]Entry, 0, limit)
	for rows.Next() {
		var entry Entry
		var payload []byte
		if err := rows.Scan(&entry.ID, &payload); err != nil {
			return nil, err
		}
		if err := json.Unmarshal(payload, &entry.Result); err != nil {
			return nil, fmt.Errorf("decode spooled result %d: %w", entry.ID, err)
		}
		entries = append(entries, entry)
	}
	return entries, rows.Err()
}

func (s *Spool) Delete(ctx context.Context, ids []int64) error {
	if len(ids) == 0 {
		return nil
	}
	placeholders := make([]string, len(ids))
	args := make([]any, len(ids))
	for i, id := range ids {
		placeholders[i], args[i] = "?", id
	}
	_, err := s.db.ExecContext(ctx, `DELETE FROM results WHERE id IN (`+strings.Join(placeholders, ",")+`)`, args...)
	return err
}

func (s *Spool) Discard(ctx context.Context, ids []int64) error {
	if len(ids) == 0 {
		return nil
	}
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()
	placeholders := make([]string, len(ids))
	args := make([]any, len(ids))
	for i, id := range ids {
		placeholders[i], args[i] = "?", id
	}
	result, err := tx.ExecContext(ctx, `DELETE FROM results WHERE id IN (`+strings.Join(placeholders, ",")+`)`, args...)
	if err != nil {
		return err
	}
	discarded, _ := result.RowsAffected()
	if discarded > 0 {
		if _, err := tx.ExecContext(ctx, `UPDATE metadata SET value = value + ? WHERE key = 'dropped'`, discarded); err != nil {
			return err
		}
	}
	return tx.Commit()
}

func (s *Spool) Stats(ctx context.Context) (Stats, error) {
	var stats Stats
	if err := s.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM results`).Scan(&stats.Depth); err != nil {
		return stats, err
	}
	if err := s.db.QueryRowContext(ctx, `SELECT value FROM metadata WHERE key = 'dropped'`).Scan(&stats.Dropped); err != nil {
		return stats, err
	}
	return stats, nil
}
