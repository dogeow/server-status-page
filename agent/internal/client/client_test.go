package client

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/statusforge/status-agent/internal/config"
	"github.com/statusforge/status-agent/internal/model"
)

func TestSign(t *testing.T) {
	got := Sign("secret", "1700000000", "abc", []byte(`{"x":1}`))
	want := "8738660771ca8b87071809e63e08cb75c09490ccb00f632f7c70e6333684892e"
	if got != want {
		t.Fatalf("Sign() = %s, want %s", got, want)
	}
}

func TestResultsAcceptsStructuredPartialAcknowledgement(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/agent/v1/results/batch" || r.Header.Get("X-Signature") == "" {
			http.Error(w, "bad request", http.StatusBadRequest)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusAccepted)
		_, _ = w.Write([]byte(`{"accepted":1,"duplicates":0,"skipped":0,"errors":[{"index":1,"code":"monitor_not_assigned"}]}`))
	}))
	defer server.Close()
	api := New(server.URL, server.Client(), model.Credentials{AgentID: "agent", Secret: "secret"})
	results := []model.Result{{MonitorID: "1", AgentID: "agent", ScheduledAt: time.Now(), ConfigVersion: "1", Status: model.StatusOK}, {MonitorID: "2", AgentID: "agent", ScheduledAt: time.Now(), ConfigVersion: "1", Status: model.StatusFailed}}
	response, err := api.Results(context.Background(), results)
	if err != nil {
		t.Fatal(err)
	}
	if response.Accepted != 1 || len(response.Errors) != 1 || response.Errors[0].Index != 1 {
		t.Fatalf("unexpected response: %#v", response)
	}
}

func TestPlanUsesETagAndFreshSignedNonce(t *testing.T) {
	requests := 0
	nonces := map[string]bool{}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests++
		nonce := r.Header.Get("X-Nonce")
		if nonce == "" || nonces[nonce] || r.Header.Get("X-Signature") == "" {
			http.Error(w, "missing or reused authentication", http.StatusUnauthorized)
			return
		}
		nonces[nonce] = true
		if r.Header.Get("If-None-Match") == `"plan-1"` {
			w.Header().Set("ETag", `"plan-1"`)
			w.WriteHeader(http.StatusNotModified)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("ETag", `"plan-1"`)
		_, _ = w.Write([]byte(`{"version":"1","monitors":[]}`))
	}))
	defer server.Close()

	api := New(server.URL, server.Client(), model.Credentials{AgentID: "agent", Secret: "secret"})
	plan, etag, changed, err := api.Plan(context.Background(), "")
	if err != nil || !changed || plan.Version != "1" || etag != `"plan-1"` {
		t.Fatalf("initial plan = %#v, etag = %q, changed = %v, error = %v", plan, etag, changed, err)
	}
	_, nextETag, changed, err := api.Plan(context.Background(), etag)
	if err != nil || changed || nextETag != etag || requests != 2 || len(nonces) != 2 {
		t.Fatalf("conditional plan: etag = %q, changed = %v, requests = %d, nonces = %d, error = %v", nextETag, changed, requests, len(nonces), err)
	}
}

func TestSignEmptyBodyIsStable(t *testing.T) {
	first := Sign("secret", "1", "n", nil)
	second := Sign("secret", "1", "n", []byte{})
	if first != second {
		t.Fatalf("nil and empty bodies differ")
	}
}

func TestEndpointNeverLeavesConfiguredControlPlaneOrigin(t *testing.T) {
	client := New("https://agent.internal:8443", nil, model.Credentials{})
	for _, configured := range []string{
		"http://localhost/api/agent/v1/plan",
		"//localhost/api/agent/v1/plan",
		"https://user:password@localhost/api/agent/v1/plan#secret",
	} {
		got := client.endpoint(configured, "/fallback")
		if want := "https://agent.internal:8443/api/agent/v1/plan"; got != want {
			t.Fatalf("endpoint(%q) = %q, want %q", configured, got, want)
		}
		if strings.Contains(got, "user") || strings.Contains(got, "secret") {
			t.Fatalf("endpoint retained credentials or fragment: %q", got)
		}
	}
}

func TestEndpointFallbackPreservesConfiguredBasePath(t *testing.T) {
	client := New("https://agent.internal/status", nil, model.Credentials{})
	if got, want := client.endpoint("", "/api/agent/v1/plan"), "https://agent.internal/status/api/agent/v1/plan"; got != want {
		t.Fatalf("endpoint fallback = %q, want %q", got, want)
	}
}

func TestControlPlaneHTTPClientRejectsCrossOriginRedirect(t *testing.T) {
	destination := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	}))
	defer destination.Close()
	source := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, destination.URL, http.StatusTemporaryRedirect)
	}))
	defer source.Close()

	httpClient, err := NewHTTPClient(config.TLSConfig{})
	if err != nil {
		t.Fatal(err)
	}
	req, _ := http.NewRequest(http.MethodGet, source.URL, nil)
	req.Header.Set("X-Signature", "must-not-leave-origin")
	if _, err := httpClient.Do(req); err == nil || !strings.Contains(err.Error(), "redirect changed origin") {
		t.Fatalf("cross-origin redirect error = %v", err)
	}
}
