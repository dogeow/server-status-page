package probe

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"time"

	"github.com/statusforge/status-agent/internal/model"
)

type squidConfig struct {
	ProxyURL           string `json:"proxy_url"`
	CanaryURL          string `json:"canary_url"`
	Username           string `json:"username,omitempty"`
	Password           string `json:"password,omitempty"`
	InsecureSkipVerify bool   `json:"insecure_skip_verify,omitempty"`
}

func validateSquid(raw json.RawMessage) error {
	var cfg squidConfig
	if err := decodeConfig(raw, &cfg); err != nil {
		return err
	}
	proxy, err := url.Parse(cfg.ProxyURL)
	if err != nil || (proxy.Scheme != "http" && proxy.Scheme != "https") || proxy.Host == "" {
		return errors.New("squid proxy_url must be absolute http(s)")
	}
	canary, err := url.Parse(cfg.CanaryURL)
	if err != nil || canary.Scheme != "https" || canary.Host == "" {
		return errors.New("squid canary_url must use absolute https")
	}
	return nil
}

type canaryResult struct {
	ok      bool
	auth    bool
	latency time.Duration
}

func runSquid(ctx context.Context, raw json.RawMessage, connectTimeout time.Duration) Outcome {
	var cfg squidConfig
	_ = decodeConfig(raw, &cfg)
	nonce := randomHex(16)
	proxyURL, _ := url.Parse(cfg.ProxyURL)
	if cfg.Username != "" {
		proxyURL.User = url.UserPassword(cfg.Username, cfg.Password)
	}
	directTransport := http.DefaultTransport.(*http.Transport).Clone()
	directTransport.Proxy = nil
	directTransport.DialContext = (&net.Dialer{Timeout: connectTimeout}).DialContext
	directTransport.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12, InsecureSkipVerify: cfg.InsecureSkipVerify} // #nosec G402
	proxyTransport := directTransport.Clone()
	proxyTransport.Proxy = http.ProxyURL(proxyURL)
	defer directTransport.CloseIdleConnections()
	defer proxyTransport.CloseIdleConnections()
	directCh, proxyCh := make(chan canaryResult, 1), make(chan canaryResult, 1)
	go func() {
		directCh <- fetchCanary(ctx, &http.Client{Transport: directTransport, CheckRedirect: rejectCrossOriginRedirect}, cfg.CanaryURL, nonce)
	}()
	go func() {
		proxyCh <- fetchCanary(ctx, &http.Client{Transport: proxyTransport, CheckRedirect: rejectCrossOriginRedirect}, cfg.CanaryURL, nonce)
	}()
	var direct, proxied canaryResult
	for received := 0; received < 2; received++ {
		select {
		case direct = <-directCh:
		case proxied = <-proxyCh:
		case <-ctx.Done():
			return fromError(ctx, "squid_timeout", ctx.Err())
		}
	}
	if !direct.ok {
		return Outcome{Status: model.StatusUnknown, ErrorCode: "canary_control_failed", Message: "direct canary control request failed"}
	}
	if proxied.auth {
		return Outcome{Status: model.StatusAuthError, ErrorCode: "proxy_authentication_failed", Message: "Squid proxy authentication failed"}
	}
	if !proxied.ok {
		return Outcome{Status: model.StatusFailed, ErrorCode: "squid_proxy_failed", Message: "canary request through Squid failed"}
	}
	return Outcome{Status: model.StatusOK, Metrics: map[string]any{"proxy_ms": proxied.latency.Milliseconds(), "control_ms": direct.latency.Milliseconds()}}
}

func fetchCanary(ctx context.Context, client *http.Client, endpoint, nonce string) canaryResult {
	u, _ := url.Parse(endpoint)
	query := u.Query()
	query.Set("_status_nonce", nonce)
	u.RawQuery = query.Encode()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u.String(), nil)
	if err != nil {
		return canaryResult{}
	}
	req.Header.Set("X-Status-Nonce", nonce)
	req.Header.Set("X-Status-Probe-Nonce", nonce)
	req.Header.Set("Cache-Control", "no-cache, no-store, max-age=0")
	started := time.Now()
	resp, err := client.Do(req)
	latency := time.Since(started)
	if err != nil {
		return canaryResult{latency: latency}
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxProbeResponse))
	if err != nil {
		return canaryResult{latency: latency}
	}
	if resp.StatusCode == http.StatusProxyAuthRequired {
		return canaryResult{auth: true, latency: latency}
	}
	ok := resp.StatusCode >= 200 && resp.StatusCode < 300 && (resp.Header.Get("X-Status-Nonce") == nonce || resp.Header.Get("X-Status-Probe-Nonce") == nonce || bytes.Contains(body, []byte(nonce)))
	return canaryResult{ok: ok, latency: latency}
}
