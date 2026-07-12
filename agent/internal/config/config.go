package config

import (
	"bytes"
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	"gopkg.in/yaml.v3"
)

type Config struct {
	ServerURL         string    `yaml:"server_url"`
	EnrollmentToken   string    `yaml:"enrollment_token"`
	Name              string    `yaml:"name"`
	StateDir          string    `yaml:"state_dir"`
	SecretFileRoot    string    `yaml:"secret_file_root"`
	PlanPollInterval  Duration  `yaml:"plan_poll_interval"`
	HeartbeatInterval Duration  `yaml:"heartbeat_interval"`
	UploadInterval    Duration  `yaml:"upload_interval"`
	BatchSize         int       `yaml:"batch_size"`
	Concurrency       int       `yaml:"concurrency"`
	SpoolMaxResults   int       `yaml:"spool_max_results"`
	LogLevel          string    `yaml:"log_level"`
	TLS               TLSConfig `yaml:"tls"`
}

type Duration time.Duration

func (d *Duration) UnmarshalYAML(node *yaml.Node) error {
	parsed, err := time.ParseDuration(node.Value)
	if err != nil {
		return fmt.Errorf("invalid duration %q", node.Value)
	}
	*d = Duration(parsed)
	return nil
}

func (d Duration) Value() time.Duration { return time.Duration(d) }

type TLSConfig struct {
	CAFile             string `yaml:"ca_file"`
	ClientCertFile     string `yaml:"client_cert_file"`
	ClientKeyFile      string `yaml:"client_key_file"`
	InsecureSkipVerify bool   `yaml:"insecure_skip_verify"`
}

func Default() Config {
	hostname, _ := os.Hostname()
	return Config{
		Name:              hostname,
		StateDir:          "/var/lib/status-agent",
		PlanPollInterval:  Duration(15 * time.Second),
		HeartbeatInterval: Duration(30 * time.Second),
		UploadInterval:    Duration(5 * time.Second),
		BatchSize:         100,
		Concurrency:       10,
		SpoolMaxResults:   10000,
		LogLevel:          "info",
	}
}

func Load(path string) (Config, error) {
	cfg := Default()
	if path != "" {
		b, err := os.ReadFile(path)
		if err != nil {
			return Config{}, fmt.Errorf("read config: %w", err)
		}
		decoder := yaml.NewDecoder(bytes.NewReader(b))
		decoder.KnownFields(true)
		if err := decoder.Decode(&cfg); err != nil {
			return Config{}, fmt.Errorf("parse config: %w", err)
		}
	}
	override(&cfg.ServerURL, "STATUS_AGENT_SERVER_URL")
	override(&cfg.EnrollmentToken, "STATUS_AGENT_ENROLLMENT_TOKEN")
	override(&cfg.Name, "STATUS_AGENT_NAME")
	override(&cfg.StateDir, "STATUS_AGENT_STATE_DIR")
	override(&cfg.SecretFileRoot, "STATUS_AGENT_SECRET_FILE_ROOT")
	override(&cfg.LogLevel, "STATUS_AGENT_LOG_LEVEL")
	if err := cfg.Validate(); err != nil {
		return Config{}, err
	}
	return cfg, nil
}

func override(dst *string, key string) {
	if value, ok := os.LookupEnv(key); ok {
		*dst = value
	}
}

func (c *Config) Validate() error {
	c.ServerURL = strings.TrimRight(strings.TrimSpace(c.ServerURL), "/")
	if c.ServerURL == "" {
		return errors.New("server_url is required")
	}
	if !strings.HasPrefix(c.ServerURL, "https://") && !strings.HasPrefix(c.ServerURL, "http://") {
		return errors.New("server_url must use http or https")
	}
	serverURL, err := url.Parse(c.ServerURL)
	if err != nil || serverURL.Host == "" || serverURL.User != nil || serverURL.RawQuery != "" || serverURL.Fragment != "" {
		return errors.New("server_url must be an absolute URL without credentials, query, or fragment")
	}
	if c.Name == "" {
		return errors.New("name is required")
	}
	if c.StateDir == "" {
		return errors.New("state_dir is required")
	}
	abs, err := filepath.Abs(c.StateDir)
	if err != nil {
		return fmt.Errorf("state_dir: %w", err)
	}
	c.StateDir = abs
	if c.PlanPollInterval.Value() < time.Second {
		return errors.New("plan_poll_interval must be at least 1s")
	}
	if c.HeartbeatInterval.Value() < 5*time.Second {
		return errors.New("heartbeat_interval must be at least 5s")
	}
	if c.UploadInterval.Value() < time.Second {
		return errors.New("upload_interval must be at least 1s")
	}
	if c.BatchSize < 1 || c.BatchSize > 500 {
		return errors.New("batch_size must be between 1 and 500")
	}
	if c.Concurrency < 1 || c.Concurrency > 1000 {
		return errors.New("concurrency must be between 1 and 1000")
	}
	if c.SpoolMaxResults < c.BatchSize {
		return errors.New("spool_max_results must be at least batch_size")
	}
	return nil
}

func (c Config) CredentialsPath() string { return filepath.Join(c.StateDir, "credentials.json") }
func (c Config) SpoolPath() string       { return filepath.Join(c.StateDir, "spool.db") }
func (c Config) PlanCachePath() string   { return filepath.Join(c.StateDir, "plan.json") }
