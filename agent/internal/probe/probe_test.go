package probe

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/statusforge/status-agent/internal/model"
	"github.com/statusforge/status-agent/internal/secret"
)

func TestHTTPProbeChecksJSONAndNonce(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		nonce := r.Header.Get("X-Status-Nonce")
		w.Header().Set("X-Status-Nonce", nonce)
		w.Header().Set("Content-Type", "application/json")
		_, _ = io.WriteString(w, `{"status":"ready","nonce":"`+nonce+`"}`)
	}))
	defer server.Close()
	config, _ := json.Marshal(map[string]any{"url": server.URL, "expected_status": 200, "require_nonce": true, "json_path": "status", "json_equals": "ready"})
	monitor := model.Monitor{ID: "http", Type: "http", Enabled: true, IntervalSeconds: 60, TimeoutMS: 1000, ConfigVersion: "1", Config: config}
	result := NewRegistry(secret.NewResolver("")).Run(context.Background(), monitor, "agent", time.Now())
	if result.Status != model.StatusOK {
		t.Fatalf("status = %s, code = %s, message = %s", result.Status, result.ErrorCode, result.Message)
	}
}

func TestHTTPProbeDoesNotForwardHeadersAcrossOrigins(t *testing.T) {
	destinationReached := make(chan bool, 1)
	destination := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		destinationReached <- r.Header.Get("X-Probe-Secret") != ""
		w.WriteHeader(http.StatusNoContent)
	}))
	defer destination.Close()
	source := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, destination.URL, http.StatusTemporaryRedirect)
	}))
	defer source.Close()

	config, _ := json.Marshal(map[string]any{"url": source.URL, "headers": map[string]string{"X-Probe-Secret": "must-not-leak"}})
	monitor := model.Monitor{ID: "http", Type: "http", Enabled: true, IntervalSeconds: 60, TimeoutMS: 1000, ConfigVersion: "1", Config: config}
	result := NewRegistry(secret.NewResolver("")).Run(context.Background(), monitor, "agent", time.Now())
	if result.Status != model.StatusFailed || result.ErrorCode != "http_request_failed" {
		t.Fatalf("status = %s, code = %s", result.Status, result.ErrorCode)
	}
	select {
	case hadSecret := <-destinationReached:
		t.Fatalf("cross-origin destination was reached; secret present = %v", hadSecret)
	default:
	}
}

func TestHTTPProbeSupportsNonceTemplateAndLaravelHeader(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		nonce := r.URL.Query().Get("nonce")
		if nonce == "" || r.Header.Get("X-Status-Probe-Nonce") != nonce {
			http.Error(w, "missing nonce", http.StatusBadRequest)
			return
		}
		w.Header().Set("X-Status-Probe-Nonce", nonce)
		_, _ = fmt.Fprintf(w, `{"nonce":%q}`, nonce)
	}))
	defer server.Close()
	config, _ := json.Marshal(map[string]any{"url": server.URL + "?nonce={{nonce}}"})
	monitor := model.Monitor{ID: "laravel", Type: "laravel", Enabled: true, IntervalSeconds: 60, TimeoutMS: 1000, ConfigVersion: "1", Config: config}
	result := NewRegistry(secret.NewResolver("")).Run(context.Background(), monitor, "agent", time.Now())
	if result.Status != model.StatusOK {
		t.Fatalf("status = %s, code = %s, message = %s", result.Status, result.ErrorCode, result.Message)
	}
}

func TestLaravelAndNextJSProbesAlwaysRequireFreshNonce(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		nonce := r.URL.Query().Get("nonce")
		if nonce == "" || r.Header.Get("X-Status-Probe-Nonce") != nonce || r.Header.Get("X-Status-Nonce") != nonce {
			http.Error(w, "missing nonce", http.StatusBadRequest)
			return
		}
		w.Header().Set("X-Status-Probe-Nonce", nonce)
		_, _ = fmt.Fprintf(w, `{"nonce":%q}`, nonce)
	}))
	defer server.Close()

	for _, probeType := range []string{"nextjs", "laravel"} {
		t.Run(probeType, func(t *testing.T) {
			config, _ := json.Marshal(map[string]any{"url": server.URL})
			monitor := model.Monitor{ID: probeType, Type: probeType, Enabled: true, IntervalSeconds: 60, TimeoutMS: 1000, ConfigVersion: "1", Config: config}
			result := NewRegistry(secret.NewResolver("")).Run(context.Background(), monitor, "agent", time.Now())
			if result.Status != model.StatusOK {
				t.Fatalf("status = %s, code = %s, message = %s", result.Status, result.ErrorCode, result.Message)
			}
		})
	}
}

func TestTCPProbeRoundTrip(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	go func() {
		conn, err := listener.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		buffer := make([]byte, 4)
		_, _ = io.ReadFull(conn, buffer)
		_, _ = io.WriteString(conn, "pong")
	}()
	config, _ := json.Marshal(map[string]any{"address": listener.Addr().String(), "send": "ping", "expect": "pong"})
	monitor := model.Monitor{ID: "tcp", Type: "tcp", Enabled: true, IntervalSeconds: 60, TimeoutMS: 1000, ConfigVersion: "1", Config: config}
	result := NewRegistry(secret.NewResolver("")).Run(context.Background(), monitor, "agent", time.Now())
	if result.Status != model.StatusOK {
		t.Fatalf("status = %s, code = %s", result.Status, result.ErrorCode)
	}
}

func TestDatabaseConfigValidation(t *testing.T) {
	registry := NewRegistry(secret.NewResolver(""))
	tests := []struct {
		name, probeType, raw string
		wantError            bool
	}{
		{"mysql valid", "mysql", `{"host":"db","port":3306,"user":"probe","tls_mode":"required"}`, false},
		{"mysql missing host", "mysql", `{"user":"probe"}`, true},
		{"mysql dsn", "mysql", `{"dsn":"probe:secret@tcp(db:3306)/app?tls=preferred"}`, false},
		{"postgres valid", "postgresql", `{"host":"db","port":5432,"user":"probe","database":"app","tls_mode":"preferred"}`, false},
		{"postgres missing user", "postgresql", `{"host":"db"}`, true},
		{"postgres dsn", "postgresql", `{"dsn":"postgres://probe:secret@db/app?sslmode=prefer"}`, false},
		{"redis host alias", "redis", `{"host":"redis","port":6379,"mode":"ping"}`, false},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			monitor := model.Monitor{ID: "db", Type: tc.probeType, IntervalSeconds: 60, TimeoutMS: 1000, Config: json.RawMessage(tc.raw)}
			err := registry.Validate(monitor)
			if (err != nil) != tc.wantError {
				t.Fatalf("Validate() error = %v, wantError %v", err, tc.wantError)
			}
		})
	}
}

func TestDatastoreOperationTimeoutUsesTotalProbeDeadline(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	got := remainingTimeout(ctx, 2*time.Second)
	if got < 4*time.Second || got > 5*time.Second {
		t.Fatalf("operation timeout = %s, want remaining total probe timeout", got)
	}
	if got := remainingTimeout(context.Background(), 2*time.Second); got != 2*time.Second {
		t.Fatalf("fallback timeout = %s", got)
	}
}

func TestTLSCertificateNearExpiryIsAWarningNotAFailure(t *testing.T) {
	now := time.Date(2026, 7, 16, 0, 0, 0, 0, time.UTC)
	outcome := tlsCertificateOutcome(now.Add(48*time.Hour), 7, now)
	if outcome.Status != model.StatusOK || outcome.ErrorCode != "tls_certificate_expiring" {
		t.Fatalf("status = %s, code = %s", outcome.Status, outcome.ErrorCode)
	}

	expired := tlsCertificateOutcome(now.Add(-time.Second), 7, now)
	if expired.Status != model.StatusFailed || expired.ErrorCode != "tls_certificate_expired" {
		t.Fatalf("expired status = %s, code = %s", expired.Status, expired.ErrorCode)
	}
}

func TestShellProbeIsForbidden(t *testing.T) {
	registry := NewRegistry(secret.NewResolver(""))
	err := registry.Validate(model.Monitor{ID: "shell", Type: "shell", IntervalSeconds: 60, Config: json.RawMessage(`{"command":"true"}`)})
	if err == nil {
		t.Fatal("shell probe unexpectedly accepted")
	}
	if got := fmt.Sprint(err); got == "" {
		t.Fatal("empty validation error")
	}
}
