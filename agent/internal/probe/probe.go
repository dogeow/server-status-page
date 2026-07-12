package probe

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"strings"
	"time"
	"unicode"

	"github.com/statusforge/status-agent/internal/model"
	"github.com/statusforge/status-agent/internal/secret"
)

type Outcome struct {
	Status    string
	ErrorCode string
	Message   string
	Metrics   map[string]any
}

type Registry struct{ secrets *secret.Resolver }

func NewRegistry(secrets *secret.Resolver) *Registry { return &Registry{secrets: secrets} }

func (r *Registry) Validate(monitor model.Monitor) error {
	if monitor.ID == "" {
		return errors.New("monitor id is required")
	}
	if monitor.IntervalSeconds != 0 && monitor.IntervalSeconds < 15 {
		return errors.New("interval_seconds must be at least 15")
	}
	if monitor.IntervalSeconds > 86400 {
		return errors.New("interval_seconds must not exceed 86400")
	}
	if monitor.Timeout() <= 0 || monitor.Timeout() > 5*time.Minute {
		return errors.New("timeout must be between 1ms and 5m")
	}
	raw, err := r.secrets.ResolveJSON(monitor.Config)
	if err != nil {
		return err
	}
	switch strings.ToLower(monitor.Type) {
	case "http", "https", "nextjs", "laravel":
		return validateHTTP(raw)
	case "tcp":
		return validateTCP(raw)
	case "dns":
		return validateDNS(raw)
	case "tls":
		return validateTLS(raw)
	case "squid":
		return validateSquid(raw)
	case "mysql":
		return validateMySQL(raw)
	case "postgres", "postgresql":
		return validatePostgres(raw)
	case "redis":
		return validateRedis(raw)
	case "reverb", "pusher":
		return validateReverb(raw)
	case "heartbeat", "push", "laravel_queue", "laravel_scheduler":
		return nil
	default:
		return fmt.Errorf("unsupported probe type %q", monitor.Type)
	}
}

func (r *Registry) IsPassive(monitor model.Monitor) bool {
	t := strings.ToLower(monitor.Type)
	return t == "heartbeat" || t == "push" || t == "laravel_queue" || t == "laravel_scheduler"
}

func (r *Registry) Run(ctx context.Context, monitor model.Monitor, agentID string, scheduledAt time.Time) model.Result {
	result := model.Result{MonitorID: monitor.ID, AgentID: agentID, ScheduledAt: scheduledAt.UTC(), ConfigVersion: monitor.ConfigVersion}
	started := time.Now()
	raw, err := r.secrets.ResolveJSON(monitor.Config)
	if err != nil {
		result.Status, result.ErrorCode, result.Message = model.StatusConfigError, "secret_resolution_failed", clean(r.secrets.Redact(err.Error()))
		return result
	}
	if err := r.Validate(model.Monitor{ID: monitor.ID, Type: monitor.Type, Enabled: monitor.Enabled, IntervalSeconds: monitor.IntervalSeconds, TimeoutMS: monitor.TimeoutMS, ConnectTimeoutMS: monitor.ConnectTimeoutMS, ConfigVersion: monitor.ConfigVersion, Config: raw}); err != nil {
		result.Status, result.ErrorCode, result.Message = model.StatusConfigError, "invalid_config", clean(r.secrets.Redact(err.Error()))
		return result
	}
	var outcome Outcome
	switch strings.ToLower(monitor.Type) {
	case "http", "https", "nextjs", "laravel":
		forceNonce := strings.EqualFold(monitor.Type, "nextjs") || strings.EqualFold(monitor.Type, "laravel")
		outcome = runHTTP(ctx, raw, monitor.ConnectTimeout(), forceNonce)
	case "tcp":
		outcome = runTCP(ctx, raw, monitor.ConnectTimeout())
	case "dns":
		outcome = runDNS(ctx, raw, monitor.ConnectTimeout())
	case "tls":
		outcome = runTLS(ctx, raw, monitor.ConnectTimeout())
	case "squid":
		outcome = runSquid(ctx, raw, monitor.ConnectTimeout())
	case "mysql":
		outcome = runMySQL(ctx, raw, monitor.ConnectTimeout())
	case "postgres", "postgresql":
		outcome = runPostgres(ctx, raw, monitor.ConnectTimeout())
	case "redis":
		outcome = runRedis(ctx, raw, monitor.ConnectTimeout())
	case "reverb", "pusher":
		outcome = runReverb(ctx, raw, monitor.ConnectTimeout())
	default:
		outcome = Outcome{Status: model.StatusConfigError, ErrorCode: "unsupported_probe", Message: "unsupported probe type"}
	}
	result.LatencyMS = time.Since(started).Milliseconds()
	result.Status = outcome.Status
	if result.Status == "" {
		result.Status = model.StatusUnknown
	}
	result.ErrorCode = clean(outcome.ErrorCode)
	result.Message = clean(r.secrets.Redact(outcome.Message))
	result.Metrics = outcome.Metrics
	return result
}

func decodeConfig(raw json.RawMessage, target any) error {
	if err := json.Unmarshal(raw, target); err != nil {
		return errors.New("invalid probe configuration")
	}
	return nil
}

func fromError(ctx context.Context, code string, err error) Outcome {
	var networkError net.Error
	if errors.Is(ctx.Err(), context.DeadlineExceeded) || errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &networkError) && networkError.Timeout()) {
		return Outcome{Status: model.StatusTimeout, ErrorCode: "timeout", Message: "probe timed out"}
	}
	message := strings.ToLower(err.Error())
	if strings.Contains(message, "authentication") || strings.Contains(message, "password") || strings.Contains(message, "access denied") || strings.Contains(message, "28p01") || strings.Contains(message, "noauth") || strings.Contains(message, "wrongpass") {
		return Outcome{Status: model.StatusAuthError, ErrorCode: "authentication_failed", Message: "probe authentication failed"}
	}
	return Outcome{Status: model.StatusFailed, ErrorCode: code, Message: "probe failed"}
}

func clean(value string) string {
	value = strings.Map(func(r rune) rune {
		if unicode.IsControl(r) && r != '\t' {
			return -1
		}
		return r
	}, value)
	value = strings.TrimSpace(value)
	if len(value) > 256 {
		value = value[:256]
	}
	return value
}
