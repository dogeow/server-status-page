package probe

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"reflect"
	"sort"
	"strings"
	"time"

	"github.com/statusforge/status-agent/internal/model"
)

const maxProbeResponse = 1 << 20

type httpConfig struct {
	URL                 string            `json:"url"`
	Method              string            `json:"method,omitempty"`
	Headers             map[string]string `json:"headers,omitempty"`
	Body                string            `json:"body,omitempty"`
	ExpectedStatus      statusCodes       `json:"expected_status,omitempty"`
	Keyword             string            `json:"keyword,omitempty"`
	JSONPath            string            `json:"json_path,omitempty"`
	JSONEquals          any               `json:"json_equals,omitempty"`
	RequireNonce        bool              `json:"require_nonce,omitempty"`
	NonceResponseHeader string            `json:"nonce_response_header,omitempty"`
	InsecureSkipVerify  bool              `json:"insecure_skip_verify,omitempty"`
}

type statusCodes []int

func (s *statusCodes) UnmarshalJSON(data []byte) error {
	var list []int
	if len(data) > 0 && data[0] == '[' {
		if err := json.Unmarshal(data, &list); err != nil {
			return err
		}
	} else {
		var single int
		if err := json.Unmarshal(data, &single); err != nil {
			return err
		}
		list = []int{single}
	}
	*s = list
	return nil
}

func validateHTTP(raw json.RawMessage) error {
	var cfg httpConfig
	if err := decodeConfig(raw, &cfg); err != nil {
		return err
	}
	validationURL := strings.ReplaceAll(cfg.URL, "{{nonce}}", "validation-nonce")
	u, err := url.Parse(validationURL)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return errors.New("http url must be absolute http(s)")
	}
	method := strings.ToUpper(cfg.Method)
	if method != "" && method != http.MethodGet && method != http.MethodHead && method != http.MethodPost {
		return errors.New("http method must be GET, HEAD, or POST")
	}
	for _, status := range cfg.ExpectedStatus {
		if status < 100 || status > 599 {
			return errors.New("invalid expected HTTP status")
		}
	}
	if cfg.JSONPath != "" && cfg.Method == http.MethodHead {
		return errors.New("JSON assertion cannot use HEAD")
	}
	return nil
}

func runHTTP(ctx context.Context, raw json.RawMessage, connectTimeout time.Duration, forceNonce bool) Outcome {
	var cfg httpConfig
	_ = decodeConfig(raw, &cfg)
	method := strings.ToUpper(cfg.Method)
	if method == "" {
		method = http.MethodGet
	}
	nonce := randomHex(16)
	requireNonce := forceNonce || cfg.RequireNonce || strings.Contains(cfg.URL, "{{nonce}}")
	requestURL := strings.ReplaceAll(cfg.URL, "{{nonce}}", url.QueryEscape(nonce))
	if requireNonce && !strings.Contains(cfg.URL, "{{nonce}}") {
		u, _ := url.Parse(requestURL)
		q := u.Query()
		// The built-in Next.js, control-plane Laravel, and laravel-probe
		// readiness endpoints all use the public `nonce` contract. Headers are
		// still sent below so header-based integrations remain compatible.
		q.Set("nonce", nonce)
		u.RawQuery = q.Encode()
		requestURL = u.String()
	}
	request, err := http.NewRequestWithContext(ctx, method, requestURL, strings.NewReader(cfg.Body))
	if err != nil {
		return Outcome{Status: model.StatusConfigError, ErrorCode: "invalid_request", Message: "cannot build HTTP request"}
	}
	for key, value := range cfg.Headers {
		request.Header.Set(key, value)
	}
	request.Header.Set("Cache-Control", "no-cache, no-store, max-age=0")
	request.Header.Set("Pragma", "no-cache")
	if requireNonce {
		request.Header.Set("X-Status-Nonce", nonce)
		request.Header.Set("X-Status-Probe-Nonce", nonce)
	}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.Proxy = http.ProxyFromEnvironment
	transport.DialContext = (&net.Dialer{Timeout: connectTimeout, KeepAlive: 30 * time.Second}).DialContext
	transport.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12, InsecureSkipVerify: cfg.InsecureSkipVerify} // #nosec G402: per-monitor explicit option.
	defer transport.CloseIdleConnections()
	client := &http.Client{Transport: transport, CheckRedirect: rejectCrossOriginRedirect}
	resp, err := client.Do(request)
	if err != nil {
		return fromError(ctx, "http_request_failed", err)
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxProbeResponse+1))
	if err != nil {
		return fromError(ctx, "http_read_failed", err)
	}
	if len(body) > maxProbeResponse {
		return Outcome{Status: model.StatusFailed, ErrorCode: "response_too_large", Message: "HTTP response exceeded 1 MiB"}
	}
	if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
		return Outcome{Status: model.StatusAuthError, ErrorCode: "http_authentication_failed", Message: "HTTP authentication failed"}
	}
	if !expectedHTTPStatus(resp.StatusCode, []int(cfg.ExpectedStatus)) {
		return Outcome{Status: model.StatusFailed, ErrorCode: "unexpected_http_status", Message: fmt.Sprintf("unexpected HTTP status %d", resp.StatusCode), Metrics: map[string]any{"http_status": resp.StatusCode}}
	}
	if cfg.Keyword != "" && !bytes.Contains(body, []byte(cfg.Keyword)) {
		return Outcome{Status: model.StatusFailed, ErrorCode: "keyword_mismatch", Message: "expected keyword was not found"}
	}
	if cfg.JSONPath != "" {
		var doc any
		if err := json.Unmarshal(body, &doc); err != nil {
			return Outcome{Status: model.StatusFailed, ErrorCode: "invalid_json_response", Message: "HTTP response was not valid JSON"}
		}
		value, ok := jsonPath(doc, cfg.JSONPath)
		if !ok || !reflect.DeepEqual(value, cfg.JSONEquals) {
			return Outcome{Status: model.StatusFailed, ErrorCode: "json_assertion_failed", Message: "JSON response assertion failed"}
		}
	}
	if requireNonce {
		header := cfg.NonceResponseHeader
		if header == "" {
			header = "X-Status-Nonce"
		}
		if resp.Header.Get(header) != nonce && resp.Header.Get("X-Status-Probe-Nonce") != nonce && !bytes.Contains(body, []byte(nonce)) {
			return Outcome{Status: model.StatusFailed, ErrorCode: "nonce_mismatch", Message: "readiness endpoint did not echo the nonce"}
		}
	}
	return Outcome{Status: model.StatusOK, Metrics: map[string]any{"http_status": resp.StatusCode, "response_bytes": len(body)}}
}

func expectedHTTPStatus(got int, expected []int) bool {
	if len(expected) == 0 {
		return got >= 200 && got < 300
	}
	for _, status := range expected {
		if got == status {
			return true
		}
	}
	return false
}

func rejectCrossOriginRedirect(req *http.Request, via []*http.Request) error {
	if len(via) == 0 {
		return nil
	}
	origin := via[0].URL
	if !strings.EqualFold(req.URL.Scheme, origin.Scheme) || !strings.EqualFold(req.URL.Host, origin.Host) {
		return errors.New("probe redirect changed origin")
	}
	if len(via) >= 10 {
		return errors.New("too many probe redirects")
	}
	return nil
}

func jsonPath(value any, path string) (any, bool) {
	current := value
	for _, part := range strings.Split(strings.TrimPrefix(path, "$."), ".") {
		if part == "" {
			continue
		}
		object, ok := current.(map[string]any)
		if !ok {
			return nil, false
		}
		current, ok = object[part]
		if !ok {
			return nil, false
		}
	}
	return current, true
}

type tcpConfig struct {
	Address, Send, Expect string
	ReadBytes             int `json:"read_bytes,omitempty"`
}

func validateTCP(raw json.RawMessage) error {
	var cfg struct {
		Address   string `json:"address"`
		Send      string `json:"send,omitempty"`
		Expect    string `json:"expect,omitempty"`
		ReadBytes int    `json:"read_bytes,omitempty"`
	}
	if err := decodeConfig(raw, &cfg); err != nil {
		return err
	}
	if _, _, err := net.SplitHostPort(cfg.Address); err != nil {
		return errors.New("tcp address must be host:port")
	}
	if cfg.ReadBytes < 0 || cfg.ReadBytes > 65536 {
		return errors.New("tcp read_bytes must be between 0 and 65536")
	}
	return nil
}

func runTCP(ctx context.Context, raw json.RawMessage, connectTimeout time.Duration) Outcome {
	var cfg struct {
		Address   string `json:"address"`
		Send      string `json:"send,omitempty"`
		Expect    string `json:"expect,omitempty"`
		ReadBytes int    `json:"read_bytes,omitempty"`
	}
	_ = decodeConfig(raw, &cfg)
	dialer := net.Dialer{Timeout: connectTimeout}
	conn, err := dialer.DialContext(ctx, "tcp", cfg.Address)
	if err != nil {
		return fromError(ctx, "tcp_connect_failed", err)
	}
	defer conn.Close()
	if deadline, ok := ctx.Deadline(); ok {
		_ = conn.SetDeadline(deadline)
	}
	if cfg.Send != "" {
		if _, err := io.WriteString(conn, cfg.Send); err != nil {
			return fromError(ctx, "tcp_write_failed", err)
		}
	}
	if cfg.Expect != "" || cfg.ReadBytes > 0 {
		size := cfg.ReadBytes
		if size == 0 {
			size = 4096
		}
		buffer := make([]byte, size)
		n, err := conn.Read(buffer)
		if err != nil {
			return fromError(ctx, "tcp_read_failed", err)
		}
		if cfg.Expect != "" && !bytes.Contains(buffer[:n], []byte(cfg.Expect)) {
			return Outcome{Status: model.StatusFailed, ErrorCode: "tcp_response_mismatch", Message: "TCP response assertion failed"}
		}
	}
	return Outcome{Status: model.StatusOK}
}

type dnsConfig struct {
	Name, Server string
	ExpectedIPs  []string `json:"expected_ips,omitempty"`
}

func validateDNS(raw json.RawMessage) error {
	var cfg struct {
		Name        string   `json:"name"`
		Server      string   `json:"server,omitempty"`
		ExpectedIPs []string `json:"expected_ips,omitempty"`
	}
	if err := decodeConfig(raw, &cfg); err != nil {
		return err
	}
	if strings.TrimSpace(cfg.Name) == "" {
		return errors.New("dns name is required")
	}
	if cfg.Server != "" {
		if _, _, err := net.SplitHostPort(cfg.Server); err != nil {
			return errors.New("dns server must be host:port")
		}
	}
	for _, ip := range cfg.ExpectedIPs {
		if net.ParseIP(ip) == nil {
			return errors.New("dns expected_ips contains an invalid IP")
		}
	}
	return nil
}

func runDNS(ctx context.Context, raw json.RawMessage, connectTimeout time.Duration) Outcome {
	var cfg struct {
		Name        string   `json:"name"`
		Server      string   `json:"server,omitempty"`
		ExpectedIPs []string `json:"expected_ips,omitempty"`
	}
	_ = decodeConfig(raw, &cfg)
	resolver := net.DefaultResolver
	if cfg.Server != "" {
		resolver = &net.Resolver{PreferGo: true, Dial: func(dialCtx context.Context, network, _ string) (net.Conn, error) {
			return (&net.Dialer{Timeout: connectTimeout}).DialContext(dialCtx, network, cfg.Server)
		}}
	}
	ips, err := resolver.LookupHost(ctx, cfg.Name)
	if err != nil {
		return fromError(ctx, "dns_lookup_failed", err)
	}
	if len(cfg.ExpectedIPs) > 0 {
		got := make(map[string]bool, len(ips))
		for _, ip := range ips {
			parsed := net.ParseIP(ip)
			if parsed != nil {
				got[parsed.String()] = true
			}
		}
		for _, expected := range cfg.ExpectedIPs {
			if !got[net.ParseIP(expected).String()] {
				return Outcome{Status: model.StatusFailed, ErrorCode: "dns_answer_mismatch", Message: "DNS answer did not contain all expected addresses"}
			}
		}
	}
	sort.Strings(ips)
	return Outcome{Status: model.StatusOK, Metrics: map[string]any{"answer_count": len(ips)}}
}

type tlsConfig struct {
	Address, ServerName string
	MinValidDays        int  `json:"min_valid_days,omitempty"`
	InsecureSkipVerify  bool `json:"insecure_skip_verify,omitempty"`
}

func validateTLS(raw json.RawMessage) error {
	var cfg struct {
		Address            string `json:"address"`
		ServerName         string `json:"server_name,omitempty"`
		MinValidDays       int    `json:"min_valid_days,omitempty"`
		InsecureSkipVerify bool   `json:"insecure_skip_verify,omitempty"`
	}
	if err := decodeConfig(raw, &cfg); err != nil {
		return err
	}
	host, _, err := net.SplitHostPort(cfg.Address)
	if err != nil {
		return errors.New("tls address must be host:port")
	}
	if cfg.ServerName == "" && net.ParseIP(host) != nil && !cfg.InsecureSkipVerify {
		return errors.New("tls server_name is required for an IP address")
	}
	if cfg.MinValidDays < 0 || cfg.MinValidDays > 3650 {
		return errors.New("tls min_valid_days is invalid")
	}
	return nil
}

func runTLS(ctx context.Context, raw json.RawMessage, connectTimeout time.Duration) Outcome {
	var cfg struct {
		Address            string `json:"address"`
		ServerName         string `json:"server_name,omitempty"`
		MinValidDays       int    `json:"min_valid_days,omitempty"`
		InsecureSkipVerify bool   `json:"insecure_skip_verify,omitempty"`
	}
	_ = decodeConfig(raw, &cfg)
	host, _, _ := net.SplitHostPort(cfg.Address)
	serverName := cfg.ServerName
	if serverName == "" {
		serverName = host
	}
	dialer := tls.Dialer{NetDialer: &net.Dialer{Timeout: connectTimeout}, Config: &tls.Config{ServerName: serverName, MinVersion: tls.VersionTLS12, InsecureSkipVerify: cfg.InsecureSkipVerify}} // #nosec G402: explicit monitor option.
	conn, err := dialer.DialContext(ctx, "tcp", cfg.Address)
	if err != nil {
		return fromError(ctx, "tls_handshake_failed", err)
	}
	defer conn.Close()
	state := conn.(*tls.Conn).ConnectionState()
	if len(state.PeerCertificates) == 0 {
		return Outcome{Status: model.StatusFailed, ErrorCode: "tls_no_certificate", Message: "server returned no certificate"}
	}
	expires := state.PeerCertificates[0].NotAfter
	outcome := tlsCertificateOutcome(expires, cfg.MinValidDays, time.Now())
	if outcome.Status != model.StatusOK || outcome.ErrorCode != "" {
		return outcome
	}
	outcome.Metrics["tls_version"] = tls.VersionName(state.Version)
	return outcome
}

func tlsCertificateOutcome(expires time.Time, minValidDays int, now time.Time) Outcome {
	days := int(expires.Sub(now).Hours() / 24)
	metrics := map[string]any{"valid_days": days, "expires_at": expires.UTC().Format(time.RFC3339)}
	if !expires.After(now) {
		return Outcome{Status: model.StatusFailed, ErrorCode: "tls_certificate_expired", Message: "TLS certificate has expired", Metrics: metrics}
	}
	if days < minValidDays {
		return Outcome{Status: model.StatusOK, ErrorCode: "tls_certificate_expiring", Message: "TLS certificate expires too soon", Metrics: metrics}
	}
	return Outcome{Status: model.StatusOK, Metrics: metrics}
}

func randomHex(size int) string {
	b := make([]byte, size)
	if _, err := rand.Read(b); err != nil {
		return hex.EncodeToString([]byte(time.Now().String()))[:size*2]
	}
	return hex.EncodeToString(b)
}
