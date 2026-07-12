package probe

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/statusforge/status-agent/internal/model"
	"github.com/statusforge/status-agent/internal/secret"
	"nhooyr.io/websocket"
)

func TestLaravelProbeTriggerSignature(t *testing.T) {
	headers := make(http.Header)
	body := []byte(`{"channel":"status-probe.public","nonce":"0123456789abcdef"}`)
	setLaravelProbeSignature(headers, body, "0123456789abcdef", "current-secret", "next-secret")
	timestamp := headers.Get("X-Status-Probe-Timestamp")
	bodyDigest := sha256.Sum256(body)
	bodyHash := hex.EncodeToString(bodyDigest[:])
	if got := headers.Get("X-Status-Probe-Content-SHA256"); got != bodyHash {
		t.Fatalf("body hash = %q", got)
	}
	canonical := "STATUS-PROBE-HMAC-SHA256-V1\n" + timestamp + "\n0123456789abcdef\n" + bodyHash
	expected := func(secret string) string {
		mac := hmac.New(sha256.New, []byte(secret))
		_, _ = mac.Write([]byte(canonical))
		return "sha256=" + hex.EncodeToString(mac.Sum(nil))
	}
	if got := headers.Get("X-Status-Probe-Signature"); got != expected("current-secret") {
		t.Fatalf("current signature = %q", got)
	}
	if got := headers.Get("X-Status-Probe-Signature-Next"); got != expected("next-secret") {
		t.Fatalf("next signature = %q", got)
	}
}

func TestReverbOriginDerivation(t *testing.T) {
	wsURL, _ := url.Parse("wss://status.example.test/socket")
	if got := reverbOrigin(reverbConfig{}, wsURL); got != "https://status.example.test" {
		t.Fatalf("derived origin = %q", got)
	}
	if got := reverbOrigin(reverbConfig{Origin: "https://app.example.test/"}, wsURL); got != "https://app.example.test" {
		t.Fatalf("explicit origin = %q", got)
	}
}

func TestReverbProbeSendsConfiguredOriginAndReceivesNonce(t *testing.T) {
	const origin = "https://status.example.test"
	const channel = "status-probe.public"
	var connectionMu sync.Mutex
	var connection *websocket.Conn
	subscribed := make(chan struct{})
	originSeen := make(chan string, 1)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/app/test-key":
			originSeen <- r.Header.Get("Origin")
			conn, err := websocket.Accept(w, r, &websocket.AcceptOptions{OriginPatterns: []string{"status.example.test"}})
			if err != nil {
				return
			}
			connectionMu.Lock()
			connection = conn
			connectionMu.Unlock()
			defer conn.Close(websocket.StatusNormalClosure, "test complete")
			_ = conn.Write(r.Context(), websocket.MessageText, []byte(`{"event":"pusher:connection_established","data":"{}"}`))
			if _, _, err := conn.Read(r.Context()); err != nil {
				return
			}
			_ = conn.Write(r.Context(), websocket.MessageText, []byte(`{"event":"pusher_internal:subscription_succeeded","channel":"status-probe.public","data":"{}"}`))
			close(subscribed)
			for {
				if _, _, err := conn.Read(r.Context()); err != nil {
					return
				}
			}
		case "/trigger":
			select {
			case <-subscribed:
			case <-time.After(time.Second):
				http.Error(w, "not subscribed", http.StatusServiceUnavailable)
				return
			}
			body, _ := io.ReadAll(r.Body)
			var payload map[string]string
			if json.Unmarshal(body, &payload) != nil || payload["nonce"] == "" {
				http.Error(w, "bad trigger", http.StatusBadRequest)
				return
			}
			message, _ := json.Marshal(map[string]any{"event": "status-probe.nonce", "channel": channel, "data": map[string]string{"nonce": payload["nonce"]}})
			connectionMu.Lock()
			conn := connection
			connectionMu.Unlock()
			writeCtx, cancel := context.WithTimeout(context.Background(), time.Second)
			defer cancel()
			if conn == nil || conn.Write(writeCtx, websocket.MessageText, message) != nil {
				http.Error(w, "broadcast failed", http.StatusServiceUnavailable)
				return
			}
			_ = json.NewEncoder(w).Encode(map[string]string{"nonce": payload["nonce"], "channel": channel, "event": "status-probe.nonce"})
		default:
			http.NotFound(w, r)
		}
	}))
	defer server.Close()

	wsURL := "ws" + strings.TrimPrefix(server.URL, "http") + "/app/test-key"
	config, _ := json.Marshal(map[string]any{"url": wsURL, "origin": origin, "channel": channel, "event": "status-probe.nonce", "trigger_url": server.URL + "/trigger"})
	monitor := model.Monitor{ID: "reverb", Type: "reverb", Enabled: true, IntervalSeconds: 60, TimeoutMS: 2000, ConnectTimeoutMS: 500, ConfigVersion: "1", Config: config}
	result := NewRegistry(secret.NewResolver("")).Run(context.Background(), monitor, "agent", time.Now())
	if result.Status != model.StatusOK {
		t.Fatalf("status = %s, code = %s, message = %s", result.Status, result.ErrorCode, result.Message)
	}
	select {
	case got := <-originSeen:
		if got != origin {
			t.Fatalf("Origin = %q, want %q", got, origin)
		}
	default:
		t.Fatal("websocket handshake did not expose Origin")
	}
}
