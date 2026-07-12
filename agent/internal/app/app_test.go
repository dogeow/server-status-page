package app

import (
	"context"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"testing"

	"github.com/statusforge/status-agent/internal/client"
	"github.com/statusforge/status-agent/internal/config"
	"github.com/statusforge/status-agent/internal/model"
)

func TestPlanCacheRoundTripIsPrivate(t *testing.T) {
	path := filepath.Join(t.TempDir(), "plan.json")
	want := cachedPlan{AgentID: "agent-one", ETag: `"etag"`, Plan: model.Plan{Version: "3", Monitors: []model.Monitor{{ID: "one", Type: "http", Enabled: true, ConfigVersion: "1", Config: []byte(`{"url":"https://example.test"}`)}}}}
	if err := savePlanCache(path, want); err != nil {
		t.Fatal(err)
	}
	got, err := loadPlanCache(path)
	if err != nil {
		t.Fatal(err)
	}
	if got.ETag != want.ETag || got.Plan.Version != want.Plan.Version || len(got.Plan.Monitors) != 1 {
		t.Fatalf("unexpected cache: %#v", got)
	}
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0600 {
		t.Fatalf("cache permissions = %o", info.Mode().Perm())
	}
}

func TestExistingCredentialsDoNotRequireEnrollmentToken(t *testing.T) {
	stateDir := t.TempDir()
	cfg := config.Default()
	cfg.ServerURL, cfg.StateDir, cfg.EnrollmentToken = "https://control.example.test", stateDir, "env://INTENTIONALLY_MISSING_TOKEN"
	if err := client.SaveCredentials(cfg.CredentialsPath(), model.Credentials{AgentID: "00000000-0000-4000-8000-000000000001", Secret: "saved-secret"}); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	agent, err := New(ctx, cfg, slog.New(slog.NewTextHandler(io.Discard, nil)))
	if err != nil {
		t.Fatal(err)
	}
	if err := agent.Close(); err != nil {
		t.Fatal(err)
	}
}
