package model

import (
	"bytes"
	"encoding/json"
	"errors"
	"time"
)

const (
	StatusOK          = "ok"
	StatusFailed      = "failed"
	StatusTimeout     = "timeout"
	StatusConfigError = "config_error"
	StatusAuthError   = "auth_error"
	StatusUnknown     = "unknown"
)

type Plan struct {
	Version  string    `json:"version"`
	Monitors []Monitor `json:"monitors"`
}

func (p *Plan) UnmarshalJSON(data []byte) error {
	var wire struct {
		Version  json.RawMessage `json:"version"`
		Monitors []Monitor       `json:"monitors"`
	}
	if err := json.Unmarshal(data, &wire); err != nil {
		return err
	}
	version, err := scalarString(wire.Version)
	if err != nil {
		return errors.New("plan version must be a string or number")
	}
	p.Version, p.Monitors = version, wire.Monitors
	return nil
}

type Monitor struct {
	ID               string          `json:"id"`
	Type             string          `json:"type"`
	Enabled          bool            `json:"enabled"`
	IntervalSeconds  int             `json:"interval_seconds"`
	TimeoutMS        int             `json:"timeout_ms"`
	ConnectTimeoutMS int             `json:"connect_timeout_ms"`
	SlowThresholdMS  int             `json:"slow_threshold_ms,omitempty"`
	ConfigVersion    string          `json:"config_version"`
	Config           json.RawMessage `json:"config"`
}

func (m *Monitor) UnmarshalJSON(data []byte) error {
	var wire struct {
		ID               json.RawMessage `json:"id"`
		Type             string          `json:"type"`
		Enabled          bool            `json:"enabled"`
		IntervalSeconds  int             `json:"interval_seconds"`
		TimeoutMS        int             `json:"timeout_ms"`
		TimeoutSeconds   int             `json:"timeout_seconds"`
		ConnectTimeoutMS int             `json:"connect_timeout_ms"`
		SlowThresholdMS  int             `json:"slow_threshold_ms"`
		ConfigVersion    json.RawMessage `json:"config_version"`
		Config           json.RawMessage `json:"config"`
	}
	if err := json.Unmarshal(data, &wire); err != nil {
		return err
	}
	id, err := scalarString(wire.ID)
	if err != nil {
		return errors.New("monitor id must be a string or number")
	}
	configVersion, err := scalarString(wire.ConfigVersion)
	if err != nil {
		return errors.New("monitor config_version must be a string or number")
	}
	m.ID, m.Type, m.Enabled = id, wire.Type, wire.Enabled
	if wire.TimeoutMS == 0 && wire.TimeoutSeconds > 0 {
		wire.TimeoutMS = wire.TimeoutSeconds * 1000
	}
	m.IntervalSeconds, m.TimeoutMS, m.ConnectTimeoutMS, m.SlowThresholdMS = wire.IntervalSeconds, wire.TimeoutMS, wire.ConnectTimeoutMS, wire.SlowThresholdMS
	m.ConfigVersion, m.Config = configVersion, wire.Config
	return nil
}

func scalarString(raw json.RawMessage) (string, error) {
	if len(raw) == 0 || bytes.Equal(raw, []byte("null")) {
		return "", errors.New("missing scalar")
	}
	var value string
	if raw[0] == '"' {
		if err := json.Unmarshal(raw, &value); err != nil || value == "" {
			return "", errors.New("invalid string scalar")
		}
		return value, nil
	}
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	var number json.Number
	if err := decoder.Decode(&number); err != nil || number.String() == "" {
		return "", errors.New("invalid numeric scalar")
	}
	if _, err := number.Int64(); err != nil {
		return "", errors.New("numeric scalar must be an integer")
	}
	return number.String(), nil
}

func (m Monitor) Interval() time.Duration {
	return time.Duration(m.IntervalSeconds) * time.Second
}

func (m Monitor) Timeout() time.Duration {
	if m.TimeoutMS <= 0 {
		return 5 * time.Second
	}
	return time.Duration(m.TimeoutMS) * time.Millisecond
}

func (m Monitor) ConnectTimeout() time.Duration {
	if m.ConnectTimeoutMS <= 0 {
		return 2 * time.Second
	}
	return time.Duration(m.ConnectTimeoutMS) * time.Millisecond
}

type Result struct {
	MonitorID     string         `json:"monitor_id"`
	AgentID       string         `json:"agent_id"`
	ScheduledAt   time.Time      `json:"scheduled_at"`
	ConfigVersion string         `json:"config_version"`
	Status        string         `json:"status"`
	LatencyMS     int64          `json:"latency_ms"`
	ErrorCode     string         `json:"error_code,omitempty"`
	Message       string         `json:"message,omitempty"`
	Metrics       map[string]any `json:"metrics,omitempty"`
}

func (r Result) Urgent() bool { return r.Status != StatusOK }

type BatchRequest struct {
	Results []Result `json:"results"`
}

type Heartbeat struct {
	Version      string    `json:"version"`
	PlanVersion  string    `json:"plan_version,omitempty"`
	ObservedAt   time.Time `json:"observed_at"`
	ActiveChecks int       `json:"active_checks"`
	SpoolDepth   int       `json:"spool_depth"`
	SpoolDropped int64     `json:"spool_dropped"`
}

type EnrollRequest struct {
	Token        string   `json:"token"`
	Name         string   `json:"name"`
	Version      string   `json:"version"`
	Capabilities []string `json:"capabilities"`
}

type Credentials struct {
	AgentID      string `json:"agent_id"`
	Secret       string `json:"secret"`
	PlanURL      string `json:"plan_url,omitempty"`
	HeartbeatURL string `json:"heartbeat_url,omitempty"`
	ResultsURL   string `json:"results_url,omitempty"`
}
