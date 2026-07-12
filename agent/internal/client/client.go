package client

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/statusforge/status-agent/internal/config"
	"github.com/statusforge/status-agent/internal/model"
)

const maxResponseBytes = 4 << 20

type Client struct {
	baseURL string
	http    *http.Client
	creds   model.Credentials
}

func NewHTTPClient(cfg config.TLSConfig) (*http.Client, error) {
	tlsConfig := &tls.Config{MinVersion: tls.VersionTLS12, InsecureSkipVerify: cfg.InsecureSkipVerify} // #nosec G402: explicit operator option.
	if cfg.CAFile != "" {
		pem, err := os.ReadFile(cfg.CAFile)
		if err != nil {
			return nil, fmt.Errorf("read agent CA: %w", err)
		}
		pool, err := x509.SystemCertPool()
		if err != nil || pool == nil {
			pool = x509.NewCertPool()
		}
		if !pool.AppendCertsFromPEM(pem) {
			return nil, errors.New("agent CA file contains no certificates")
		}
		tlsConfig.RootCAs = pool
	}
	if cfg.ClientCertFile != "" || cfg.ClientKeyFile != "" {
		if cfg.ClientCertFile == "" || cfg.ClientKeyFile == "" {
			return nil, errors.New("both TLS client certificate and key are required")
		}
		cert, err := tls.LoadX509KeyPair(cfg.ClientCertFile, cfg.ClientKeyFile)
		if err != nil {
			return nil, fmt.Errorf("load TLS client certificate: %w", err)
		}
		tlsConfig.Certificates = []tls.Certificate{cert}
	}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.TLSClientConfig = tlsConfig
	transport.Proxy = http.ProxyFromEnvironment
	transport.MaxIdleConns = 50
	transport.MaxIdleConnsPerHost = 10
	transport.IdleConnTimeout = 90 * time.Second
	return &http.Client{
		Transport: transport,
		Timeout:   30 * time.Second,
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) == 0 {
				return nil
			}
			// Agent authentication headers are intentionally reusable only for the
			// configured control-plane origin. Following an origin-changing redirect
			// could disclose them to an unrelated host.
			origin := via[0].URL
			if !strings.EqualFold(req.URL.Scheme, origin.Scheme) || !strings.EqualFold(req.URL.Host, origin.Host) {
				return errors.New("control-plane redirect changed origin")
			}
			if len(via) >= 10 {
				return errors.New("too many control-plane redirects")
			}
			return nil
		},
	}, nil
}

func LoadCredentials(path string) (model.Credentials, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return model.Credentials{}, err
	}
	var creds model.Credentials
	if err := json.Unmarshal(b, &creds); err != nil {
		return model.Credentials{}, errors.New("invalid credentials file")
	}
	if creds.AgentID == "" || creds.Secret == "" {
		return model.Credentials{}, errors.New("credentials file is incomplete")
	}
	return creds, nil
}

func SaveCredentials(path string, creds model.Credentials) error {
	if creds.AgentID == "" || creds.Secret == "" {
		return errors.New("enrollment returned incomplete credentials")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return fmt.Errorf("create state directory: %w", err)
	}
	b, err := json.Marshal(creds)
	if err != nil {
		return err
	}
	tmp := path + ".tmp"
	file, err := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0600)
	if err != nil {
		return fmt.Errorf("write credentials: %w", err)
	}
	if err := file.Chmod(0600); err != nil {
		_ = file.Close()
		_ = os.Remove(tmp)
		return err
	}
	if _, err := file.Write(b); err != nil {
		_ = file.Close()
		_ = os.Remove(tmp)
		return fmt.Errorf("write credentials: %w", err)
	}
	if err := file.Sync(); err != nil {
		_ = file.Close()
		_ = os.Remove(tmp)
		return fmt.Errorf("sync credentials: %w", err)
	}
	if err := file.Close(); err != nil {
		_ = os.Remove(tmp)
		return fmt.Errorf("close credentials: %w", err)
	}
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)
		return fmt.Errorf("install credentials: %w", err)
	}
	if directory, err := os.Open(filepath.Dir(path)); err == nil {
		_ = directory.Sync()
		_ = directory.Close()
	}
	return nil
}

func Enroll(ctx context.Context, httpClient *http.Client, baseURL string, request model.EnrollRequest) (model.Credentials, error) {
	if request.Token == "" {
		return model.Credentials{}, errors.New("agent is not enrolled and enrollment_token is empty")
	}
	body, err := json.Marshal(request)
	if err != nil {
		return model.Credentials{}, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(baseURL, "/")+"/api/agent/v1/enroll", bytes.NewReader(body))
	if err != nil {
		return model.Credentials{}, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "status-agent/"+request.Version)
	resp, err := httpClient.Do(req)
	if err != nil {
		return model.Credentials{}, fmt.Errorf("enroll request failed: %w", err)
	}
	defer resp.Body.Close()
	b, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseBytes))
	if err != nil {
		return model.Credentials{}, errors.New("read enrollment response")
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return model.Credentials{}, fmt.Errorf("enrollment rejected with HTTP %d", resp.StatusCode)
	}
	var creds model.Credentials
	if err := json.Unmarshal(b, &creds); err != nil {
		return model.Credentials{}, errors.New("invalid enrollment response")
	}
	if creds.AgentID == "" || creds.Secret == "" {
		return model.Credentials{}, errors.New("enrollment response is incomplete")
	}
	return creds, nil
}

func New(baseURL string, httpClient *http.Client, creds model.Credentials) *Client {
	return &Client{baseURL: strings.TrimRight(baseURL, "/"), http: httpClient, creds: creds}
}

func Sign(secret, timestamp, nonce string, body []byte) string {
	digest := sha256.Sum256(body)
	canonical := timestamp + "\n" + nonce + "\n" + hex.EncodeToString(digest[:])
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(canonical))
	return hex.EncodeToString(mac.Sum(nil))
}

func (c *Client) AgentID() string { return c.creds.AgentID }

func (c *Client) Plan(ctx context.Context, etag string) (model.Plan, string, bool, error) {
	endpoint := c.endpoint(c.creds.PlanURL, "/api/agent/v1/plan")
	req, err := c.signedRequest(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return model.Plan{}, etag, false, err
	}
	if etag != "" {
		req.Header.Set("If-None-Match", etag)
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return model.Plan{}, etag, false, fmt.Errorf("fetch plan: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusNotModified {
		return model.Plan{}, etag, false, nil
	}
	if resp.StatusCode != http.StatusOK {
		return model.Plan{}, etag, false, fmt.Errorf("fetch plan returned HTTP %d", resp.StatusCode)
	}
	var plan model.Plan
	if err := decodeResponse(resp.Body, &plan); err != nil {
		return model.Plan{}, etag, false, fmt.Errorf("decode plan: %w", err)
	}
	if plan.Version == "" {
		return model.Plan{}, etag, false, errors.New("plan has no version")
	}
	return plan, resp.Header.Get("ETag"), true, nil
}

func (c *Client) Heartbeat(ctx context.Context, heartbeat model.Heartbeat) error {
	return c.postJSON(ctx, c.endpoint(c.creds.HeartbeatURL, "/api/agent/v1/heartbeat"), heartbeat)
}

type BatchResponse struct {
	Accepted   int          `json:"accepted"`
	Duplicates int          `json:"duplicates"`
	Skipped    int          `json:"skipped"`
	Errors     []BatchError `json:"errors"`
}

type BatchError struct {
	Index int    `json:"index"`
	Code  string `json:"code"`
}

func (c *Client) Results(ctx context.Context, results []model.Result) (BatchResponse, error) {
	if len(results) == 0 {
		return BatchResponse{}, nil
	}
	body, err := json.Marshal(model.BatchRequest{Results: results})
	if err != nil {
		return BatchResponse{}, err
	}
	req, err := c.signedRequest(ctx, http.MethodPost, c.endpoint(c.creds.ResultsURL, "/api/agent/v1/results/batch"), body)
	if err != nil {
		return BatchResponse{}, err
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return BatchResponse{}, err
	}
	defer resp.Body.Close()
	responseBody, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseBytes))
	if err != nil {
		return BatchResponse{}, errors.New("read result batch response")
	}
	if (resp.StatusCode < 200 || resp.StatusCode >= 300) && resp.StatusCode != http.StatusUnprocessableEntity {
		return BatchResponse{}, fmt.Errorf("agent API returned HTTP %d", resp.StatusCode)
	}
	var batch BatchResponse
	if err := json.Unmarshal(responseBody, &batch); err != nil {
		return BatchResponse{}, errors.New("invalid result batch response")
	}
	seen := make(map[int]bool, len(batch.Errors))
	for _, item := range batch.Errors {
		if item.Index < 0 || item.Index >= len(results) || item.Code == "" || seen[item.Index] {
			return BatchResponse{}, errors.New("invalid result batch error indexes")
		}
		seen[item.Index] = true
	}
	if batch.Accepted+batch.Duplicates+len(batch.Errors) != len(results) {
		return BatchResponse{}, errors.New("incomplete result batch acknowledgement")
	}
	return batch, nil
}

func (c *Client) postJSON(ctx context.Context, endpoint string, payload any) error {
	body, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	req, err := c.signedRequest(ctx, http.MethodPost, endpoint, body)
	if err != nil {
		return err
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, maxResponseBytes))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("agent API returned HTTP %d", resp.StatusCode)
	}
	return nil
}

func (c *Client) signedRequest(ctx context.Context, method, endpoint string, body []byte) (*http.Request, error) {
	timestamp := strconv.FormatInt(time.Now().Unix(), 10)
	nonceBytes := make([]byte, 16)
	if _, err := rand.Read(nonceBytes); err != nil {
		return nil, errors.New("generate request nonce")
	}
	nonce := hex.EncodeToString(nonceBytes)
	req, err := http.NewRequestWithContext(ctx, method, endpoint, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Agent-Id", c.creds.AgentID)
	req.Header.Set("X-Timestamp", timestamp)
	req.Header.Set("X-Nonce", nonce)
	req.Header.Set("X-Signature", Sign(c.creds.Secret, timestamp, nonce, body))
	req.Header.Set("User-Agent", "status-agent")
	return req, nil
}

func (c *Client) endpoint(configured, fallback string) string {
	base, _ := url.Parse(c.baseURL + "/")
	fallbackEndpoint := c.baseURL + fallback
	if configured == "" {
		return fallbackEndpoint
	}
	parsed, err := url.Parse(configured)
	if err != nil {
		return fallbackEndpoint
	}
	resolved := base.ResolveReference(parsed)
	if (resolved.Scheme != "http" && resolved.Scheme != "https") || resolved.Host == "" || resolved.Opaque != "" {
		return fallbackEndpoint
	}
	// The operator-provided server URL is the trust boundary and the known
	// reachable origin. Preserve endpoint paths returned by deployments whose
	// APP_URL/public origin differs, but never send the agent secret elsewhere.
	if !strings.EqualFold(resolved.Scheme, base.Scheme) || !strings.EqualFold(resolved.Host, base.Host) {
		resolved.Scheme, resolved.Host = base.Scheme, base.Host
	}
	resolved.User = nil
	resolved.Fragment = ""
	return resolved.String()
}

func decodeResponse(reader io.Reader, output any) error {
	decoder := json.NewDecoder(io.LimitReader(reader, maxResponseBytes))
	return decoder.Decode(output)
}
