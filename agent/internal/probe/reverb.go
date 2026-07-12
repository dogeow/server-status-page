package probe

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/statusforge/status-agent/internal/model"
	"nhooyr.io/websocket"
)

type reverbConfig struct {
	URL                string            `json:"url"`
	Origin             string            `json:"origin,omitempty"`
	AppKey             string            `json:"app_key,omitempty"`
	Channel            string            `json:"channel"`
	Event              string            `json:"event,omitempty"`
	TriggerURL         string            `json:"trigger_url"`
	TriggerHeaders     map[string]string `json:"trigger_headers,omitempty"`
	TriggerSecret      string            `json:"trigger_secret,omitempty"`
	TriggerSecretNext  string            `json:"trigger_secret_next,omitempty"`
	InsecureSkipVerify bool              `json:"insecure_skip_verify,omitempty"`
}

func validateReverb(raw json.RawMessage) error {
	var cfg reverbConfig
	if err := decodeConfig(raw, &cfg); err != nil {
		return err
	}
	u, err := url.Parse(cfg.URL)
	if err != nil || (u.Scheme != "ws" && u.Scheme != "wss") || u.Host == "" {
		return errors.New("reverb url must be absolute ws(s)")
	}
	if cfg.Origin != "" {
		origin, err := url.Parse(cfg.Origin)
		if err != nil || (origin.Scheme != "http" && origin.Scheme != "https") || origin.Host == "" || origin.User != nil || (origin.Path != "" && origin.Path != "/") || origin.RawQuery != "" || origin.Fragment != "" {
			return errors.New("reverb origin must be an http(s) origin without a path")
		}
	}
	trigger, err := url.Parse(cfg.TriggerURL)
	if err != nil || (trigger.Scheme != "http" && trigger.Scheme != "https") || trigger.Host == "" {
		return errors.New("reverb trigger_url must be absolute http(s)")
	}
	return nil
}

type pusherMessage struct {
	Event   string          `json:"event"`
	Channel string          `json:"channel,omitempty"`
	Data    json.RawMessage `json:"data"`
}

func runReverb(ctx context.Context, raw json.RawMessage, connectTimeout time.Duration) Outcome {
	var cfg reverbConfig
	_ = decodeConfig(raw, &cfg)
	if cfg.Channel == "" {
		cfg.Channel = "status-probe.public"
	}
	wsURL, _ := url.Parse(cfg.URL)
	if cfg.AppKey != "" && !strings.Contains(wsURL.Path, "/app/") {
		wsURL.Path = strings.TrimRight(wsURL.Path, "/") + "/app/" + url.PathEscape(cfg.AppKey)
	}
	q := wsURL.Query()
	if q.Get("protocol") == "" {
		q.Set("protocol", "7")
	}
	q.Set("client", "status-agent")
	q.Set("version", "1.0")
	wsURL.RawQuery = q.Encode()
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.DialContext = (&net.Dialer{Timeout: connectTimeout}).DialContext
	transport.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12, InsecureSkipVerify: cfg.InsecureSkipVerify} // #nosec G402
	defer transport.CloseIdleConnections()
	httpClient := &http.Client{Transport: transport, CheckRedirect: rejectCrossOriginRedirect}
	dialHeaders := make(http.Header)
	dialHeaders.Set("Origin", reverbOrigin(cfg, wsURL))
	conn, response, err := websocket.Dial(ctx, wsURL.String(), &websocket.DialOptions{HTTPClient: httpClient, HTTPHeader: dialHeaders})
	if err != nil {
		if response != nil && (response.StatusCode == http.StatusUnauthorized || response.StatusCode == http.StatusForbidden) {
			return Outcome{Status: model.StatusAuthError, ErrorCode: "reverb_authentication_failed", Message: "Reverb websocket authentication failed"}
		}
		return fromError(ctx, "reverb_connect_failed", err)
	}
	defer conn.Close(websocket.StatusNormalClosure, "probe complete")
	if _, err := readPusherEvent(ctx, conn, "pusher:connection_established", ""); err != nil {
		return fromError(ctx, "reverb_handshake_failed", err)
	}
	subscribe, _ := json.Marshal(map[string]any{"event": "pusher:subscribe", "data": map[string]string{"channel": cfg.Channel}})
	if err := conn.Write(ctx, websocket.MessageText, subscribe); err != nil {
		return fromError(ctx, "reverb_subscribe_failed", err)
	}
	if _, err := readPusherEvent(ctx, conn, "pusher_internal:subscription_succeeded", cfg.Channel); err != nil {
		return fromError(ctx, "reverb_subscription_failed", err)
	}
	authNonce := randomHex(16)
	payload, _ := json.Marshal(map[string]string{"nonce": authNonce, "channel": cfg.Channel})
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, cfg.TriggerURL, bytes.NewReader(payload))
	if err != nil {
		return Outcome{Status: model.StatusConfigError, ErrorCode: "reverb_trigger_invalid", Message: "invalid Reverb trigger request"}
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Status-Nonce", authNonce)
	for key, value := range cfg.TriggerHeaders {
		req.Header.Set(key, value)
	}
	setLaravelProbeSignature(req.Header, payload, authNonce, cfg.TriggerSecret, cfg.TriggerSecretNext)
	resp, err := httpClient.Do(req)
	if err != nil {
		return fromError(ctx, "reverb_trigger_failed", err)
	}
	body, readErr := io.ReadAll(io.LimitReader(resp.Body, 4097))
	_ = resp.Body.Close()
	if readErr != nil || len(body) > 4096 {
		return Outcome{Status: model.StatusFailed, ErrorCode: "reverb_trigger_response_invalid", Message: "Reverb trigger response could not be read"}
	}
	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return Outcome{Status: model.StatusAuthError, ErrorCode: "reverb_trigger_authentication_failed", Message: "Reverb trigger authentication failed"}
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return Outcome{Status: model.StatusFailed, ErrorCode: "reverb_trigger_failed", Message: "Reverb trigger returned a non-success status"}
	}
	broadcastNonce, event := authNonce, cfg.Event
	var trigger struct {
		Nonce   string `json:"nonce"`
		Channel string `json:"channel"`
		Event   string `json:"event"`
	}
	if json.Unmarshal(body, &trigger) == nil {
		if trigger.Nonce != "" {
			broadcastNonce = trigger.Nonce
		}
		if event == "" {
			event = trigger.Event
		}
		if trigger.Channel != "" && trigger.Channel != cfg.Channel {
			return Outcome{Status: model.StatusFailed, ErrorCode: "reverb_channel_mismatch", Message: "Reverb trigger used a different channel"}
		}
	}
	message, err := readPusherNonce(ctx, conn, event, cfg.Channel, broadcastNonce)
	if err != nil {
		return fromError(ctx, "reverb_event_failed", err)
	}
	if !bytes.Contains(message.Data, []byte(broadcastNonce)) {
		return Outcome{Status: model.StatusFailed, ErrorCode: "reverb_nonce_mismatch", Message: "Reverb event did not contain the expected nonce"}
	}
	return Outcome{Status: model.StatusOK, Metrics: map[string]any{"channel": cfg.Channel}}
}

func reverbOrigin(cfg reverbConfig, wsURL *url.URL) string {
	if cfg.Origin != "" {
		return strings.TrimSuffix(cfg.Origin, "/")
	}
	scheme := "http"
	if wsURL.Scheme == "wss" {
		scheme = "https"
	}
	return (&url.URL{Scheme: scheme, Host: wsURL.Host}).String()
}

func setLaravelProbeSignature(headers http.Header, body []byte, nonce, currentSecret, nextSecret string) {
	if currentSecret == "" && nextSecret == "" {
		return
	}
	timestamp := strconv.FormatInt(time.Now().Unix(), 10)
	digest := sha256.Sum256(body)
	bodyHash := hex.EncodeToString(digest[:])
	canonical := "STATUS-PROBE-HMAC-SHA256-V1\n" + timestamp + "\n" + nonce + "\n" + bodyHash
	sign := func(secret string) string {
		mac := hmac.New(sha256.New, []byte(secret))
		_, _ = mac.Write([]byte(canonical))
		return "sha256=" + hex.EncodeToString(mac.Sum(nil))
	}
	headers.Set("X-Status-Probe-Timestamp", timestamp)
	headers.Set("X-Status-Probe-Nonce", nonce)
	headers.Set("X-Status-Probe-Content-SHA256", bodyHash)
	if currentSecret != "" {
		headers.Set("X-Status-Probe-Signature", sign(currentSecret))
		if nextSecret != "" {
			headers.Set("X-Status-Probe-Signature-Next", sign(nextSecret))
		}
	} else {
		headers.Set("X-Status-Probe-Signature", sign(nextSecret))
	}
}

func readPusherNonce(ctx context.Context, conn *websocket.Conn, expectedEvent, expectedChannel, nonce string) (pusherMessage, error) {
	for {
		message, err := readPusherEvent(ctx, conn, expectedEvent, expectedChannel)
		if err != nil {
			return pusherMessage{}, err
		}
		if bytes.Contains(message.Data, []byte(nonce)) {
			return message, nil
		}
	}
}

func readPusherEvent(ctx context.Context, conn *websocket.Conn, expectedEvent, expectedChannel string) (pusherMessage, error) {
	for {
		kind, payload, err := conn.Read(ctx)
		if err != nil {
			return pusherMessage{}, err
		}
		if kind != websocket.MessageText {
			continue
		}
		var message pusherMessage
		if json.Unmarshal(payload, &message) != nil {
			continue
		}
		if message.Event == "pusher:ping" {
			pong, _ := json.Marshal(map[string]any{"event": "pusher:pong", "data": map[string]any{}})
			_ = conn.Write(ctx, websocket.MessageText, pong)
			continue
		}
		if message.Event == "pusher:error" {
			return pusherMessage{}, errors.New("Reverb returned a protocol error")
		}
		eventMatches := expectedEvent == "" || message.Event == expectedEvent
		channelMatches := expectedChannel == "" || message.Channel == expectedChannel
		if eventMatches && channelMatches {
			return message, nil
		}
	}
}
