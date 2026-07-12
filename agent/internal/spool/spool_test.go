package spool

import (
	"context"
	"path/filepath"
	"testing"
	"time"

	"github.com/statusforge/status-agent/internal/model"
)

func TestSpoolDeduplicatesAndDeletes(t *testing.T) {
	sp, err := Open(filepath.Join(t.TempDir(), "spool.db"), 10)
	if err != nil {
		t.Fatal(err)
	}
	defer sp.Close()
	result := model.Result{MonitorID: "m1", AgentID: "a1", ScheduledAt: time.Unix(1, 0).UTC(), ConfigVersion: "v1", Status: model.StatusOK}
	if err := sp.Put(context.Background(), result); err != nil {
		t.Fatal(err)
	}
	if err := sp.Put(context.Background(), result); err != nil {
		t.Fatal(err)
	}
	entries, err := sp.Batch(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 1 {
		t.Fatalf("got %d entries, want 1", len(entries))
	}
	if err := sp.Delete(context.Background(), []int64{entries[0].ID}); err != nil {
		t.Fatal(err)
	}
	stats, err := sp.Stats(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if stats.Depth != 0 {
		t.Fatalf("depth = %d, want 0", stats.Depth)
	}
}

func TestSpoolSurvivesAgentRestart(t *testing.T) {
	path := filepath.Join(t.TempDir(), "spool.db")
	sp, err := Open(path, 10)
	if err != nil {
		t.Fatal(err)
	}
	want := model.Result{MonitorID: "m1", AgentID: "a1", ScheduledAt: time.Unix(1, 123).UTC(), ConfigVersion: "v1", Status: model.StatusFailed, ErrorCode: "offline"}
	if err := sp.Put(context.Background(), want); err != nil {
		t.Fatal(err)
	}
	if err := sp.Close(); err != nil {
		t.Fatal(err)
	}

	sp, err = Open(path, 10)
	if err != nil {
		t.Fatal(err)
	}
	defer sp.Close()
	entries, err := sp.Batch(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 1 || entries[0].Result.MonitorID != want.MonitorID || entries[0].Result.ScheduledAt.UnixNano() != want.ScheduledAt.UnixNano() || entries[0].Result.ConfigVersion != want.ConfigVersion {
		t.Fatalf("reopened entries = %#v", entries)
	}
}

func TestSpoolIsBoundedAndKeepsNewestState(t *testing.T) {
	sp, err := Open(filepath.Join(t.TempDir(), "spool.db"), 2)
	if err != nil {
		t.Fatal(err)
	}
	defer sp.Close()
	for i, status := range []string{model.StatusFailed, model.StatusOK, model.StatusOK} {
		result := model.Result{MonitorID: string(rune('a' + i)), AgentID: "agent", ScheduledAt: time.Unix(int64(i+1), 0), ConfigVersion: "v1", Status: status}
		if err := sp.Put(context.Background(), result); err != nil {
			t.Fatal(err)
		}
	}
	entries, err := sp.Batch(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 2 {
		t.Fatalf("got %d entries, want 2", len(entries))
	}
	if entries[0].Result.MonitorID != "b" || entries[1].Result.MonitorID != "c" {
		t.Fatalf("bounded spool did not retain newest results: %#v", entries)
	}
	stats, err := sp.Stats(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if stats.Dropped != 1 {
		t.Fatalf("dropped = %d, want 1", stats.Dropped)
	}
}

func TestSpoolBackfillPreservesFailureAndRecoveryOrder(t *testing.T) {
	sp, err := Open(filepath.Join(t.TempDir(), "spool.db"), 10)
	if err != nil {
		t.Fatal(err)
	}
	defer sp.Close()
	statuses := []string{model.StatusOK, model.StatusFailed, model.StatusOK}
	for i, status := range statuses {
		result := model.Result{MonitorID: "monitor", AgentID: "agent", ScheduledAt: time.Unix(int64(i+1), 0), ConfigVersion: "v1", Status: status}
		if err := sp.Put(context.Background(), result); err != nil {
			t.Fatal(err)
		}
	}
	entries, err := sp.Batch(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != len(statuses) {
		t.Fatalf("got %d entries", len(entries))
	}
	for i, want := range statuses {
		if entries[i].Result.Status != want {
			t.Fatalf("entry %d status = %q, want %q", i, entries[i].Result.Status, want)
		}
	}
}

func TestDiscardCountsRejectedResults(t *testing.T) {
	sp, err := Open(filepath.Join(t.TempDir(), "spool.db"), 10)
	if err != nil {
		t.Fatal(err)
	}
	defer sp.Close()
	result := model.Result{MonitorID: "m1", AgentID: "a1", ScheduledAt: time.Unix(1, 0).UTC(), ConfigVersion: "v1", Status: model.StatusFailed}
	if err := sp.Put(context.Background(), result); err != nil {
		t.Fatal(err)
	}
	entries, err := sp.Batch(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}
	if err := sp.Discard(context.Background(), []int64{entries[0].ID}); err != nil {
		t.Fatal(err)
	}
	stats, err := sp.Stats(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if stats.Depth != 0 || stats.Dropped != 1 {
		t.Fatalf("stats = %#v", stats)
	}
}
