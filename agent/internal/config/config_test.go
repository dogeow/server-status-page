package config

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestLoadDurationAndEnvironmentOverrides(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "config.yaml")
	content := "server_url: https://status.example.test/\nname: original\nstate_dir: " + filepath.Join(dir, "state") + "\nplan_poll_interval: 15s\nheartbeat_interval: 30s\nupload_interval: 5s\nbatch_size: 50\nconcurrency: 4\nspool_max_results: 100\n"
	if err := os.WriteFile(path, []byte(content), 0600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("STATUS_AGENT_NAME", "overridden")
	cfg, err := Load(path)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Name != "overridden" {
		t.Fatalf("name = %q", cfg.Name)
	}
	if cfg.ServerURL != "https://status.example.test" {
		t.Fatalf("server URL = %q", cfg.ServerURL)
	}
	if cfg.PlanPollInterval.Value() != 15*time.Second {
		t.Fatalf("poll interval = %s", cfg.PlanPollInterval.Value())
	}
}

func TestLoadRejectsUnknownConfigurationKeys(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.yaml")
	content := "server_url: https://status.example.test\nname: agent\nstate_dir: /tmp/status-agent-test\nplan_poll_interval: 15s\nheartbeat_interval: 30s\nupload_interval: 5s\nbatch_size: 50\nconcurrency: 4\nspool_max_results: 100\nplan_pol_interval: 5s\n"
	if err := os.WriteFile(path, []byte(content), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(path); err == nil {
		t.Fatal("unknown configuration key was accepted")
	}
}
