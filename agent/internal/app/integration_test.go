package app

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/statusforge/status-agent/internal/client"
	"github.com/statusforge/status-agent/internal/config"
	"github.com/statusforge/status-agent/internal/model"
)

func TestAgentEnrollsPollsRunsAndUploads(t *testing.T) {
	readiness := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		nonce := r.URL.Query().Get("nonce")
		w.Header().Set("X-Status-Nonce", nonce)
		_, _ = w.Write([]byte(`{"nonce":"` + nonce + `"}`))
	}))
	defer readiness.Close()

	resultReceived := make(chan model.Result, 1)
	const agentID = "00000000-0000-4000-8000-000000000099"
	const agentSecret = "integration-agent-secret"
	var controlPlane *httptest.Server
	controlPlane = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		if r.URL.Path != "/api/agent/v1/enroll" {
			expected := client.Sign(agentSecret, r.Header.Get("X-Timestamp"), r.Header.Get("X-Nonce"), body)
			if r.Header.Get("X-Agent-Id") != agentID || r.Header.Get("X-Signature") != expected {
				http.Error(w, "bad signature", http.StatusUnauthorized)
				return
			}
		}
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/api/agent/v1/enroll":
			_ = json.NewEncoder(w).Encode(map[string]any{"agent_id": agentID, "secret": agentSecret, "plan_url": "http://localhost/api/agent/v1/plan", "heartbeat_url": "http://localhost/api/agent/v1/heartbeat", "results_url": "http://localhost/api/agent/v1/results/batch"})
		case "/api/agent/v1/plan":
			w.Header().Set("ETag", `"plan-1"`)
			_ = json.NewEncoder(w).Encode(map[string]any{"version": 1, "monitors": []any{map[string]any{"id": 9, "type": "http", "enabled": true, "interval_seconds": 60, "timeout_ms": 1000, "connect_timeout_ms": 500, "config_version": "1", "config": map[string]any{"url": readiness.URL + "?nonce={{nonce}}"}}}})
		case "/api/agent/v1/heartbeat":
			_, _ = w.Write([]byte(`{"ok":true}`))
		case "/api/agent/v1/results/batch":
			var batch model.BatchRequest
			if err := json.Unmarshal(body, &batch); err != nil || len(batch.Results) != 1 {
				http.Error(w, "bad batch", http.StatusUnprocessableEntity)
				return
			}
			resultReceived <- batch.Results[0]
			w.WriteHeader(http.StatusAccepted)
			_, _ = w.Write([]byte(`{"accepted":1,"duplicates":0,"skipped":0,"errors":[]}`))
		default:
			http.NotFound(w, r)
		}
	}))
	defer controlPlane.Close()

	stateDir := t.TempDir()
	cfg := config.Default()
	cfg.ServerURL, cfg.EnrollmentToken, cfg.Name, cfg.StateDir = controlPlane.URL, "one-time-integration-token", "integration", stateDir
	cfg.PlanPollInterval, cfg.HeartbeatInterval, cfg.UploadInterval = config.Duration(100*time.Millisecond), config.Duration(100*time.Millisecond), config.Duration(50*time.Millisecond)
	cfg.BatchSize, cfg.Concurrency, cfg.SpoolMaxResults = 10, 2, 100
	ctx, cancel := context.WithCancel(context.Background())
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	agent, err := New(ctx, cfg, logger)
	if err != nil {
		t.Fatal(err)
	}
	done := make(chan error, 1)
	go func() { done <- agent.Run(ctx) }()
	select {
	case result := <-resultReceived:
		if result.MonitorID != "9" || result.Status != model.StatusOK || result.ConfigVersion != "1" {
			t.Fatalf("unexpected result: %#v", result)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("timed out waiting for uploaded result")
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if err := agent.Close(); err != nil {
		t.Fatal(err)
	}
}
