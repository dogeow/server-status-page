package app

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"strings"
	"sync/atomic"
	"time"

	"github.com/statusforge/status-agent/internal/client"
	"github.com/statusforge/status-agent/internal/config"
	"github.com/statusforge/status-agent/internal/model"
	"github.com/statusforge/status-agent/internal/probe"
	"github.com/statusforge/status-agent/internal/scheduler"
	"github.com/statusforge/status-agent/internal/secret"
	"github.com/statusforge/status-agent/internal/spool"
	"github.com/statusforge/status-agent/internal/version"
)

type App struct {
	cfg         config.Config
	logger      *slog.Logger
	secrets     *secret.Resolver
	client      *client.Client
	spool       *spool.Spool
	scheduler   *scheduler.Scheduler
	uploadWake  chan struct{}
	planVersion atomic.Value
	initialETag string
}

type cachedPlan struct {
	AgentID string     `json:"agent_id"`
	ETag    string     `json:"etag,omitempty"`
	Plan    model.Plan `json:"plan"`
}

func New(ctx context.Context, cfg config.Config, logger *slog.Logger) (*App, error) {
	if err := os.MkdirAll(cfg.StateDir, 0700); err != nil {
		return nil, fmt.Errorf("create agent state directory: %w", err)
	}
	if err := os.Chmod(cfg.StateDir, 0700); err != nil {
		return nil, fmt.Errorf("secure agent state directory: %w", err)
	}
	resolver := secret.NewResolver(cfg.SecretFileRoot)
	httpClient, err := client.NewHTTPClient(cfg.TLS)
	if err != nil {
		return nil, err
	}
	creds, err := client.LoadCredentials(cfg.CredentialsPath())
	if errors.Is(err, os.ErrNotExist) {
		token := cfg.EnrollmentToken
		if strings.HasPrefix(token, "env://") || strings.HasPrefix(token, "env:") || strings.HasPrefix(token, "file://") || strings.HasPrefix(token, "file:") {
			resolved, resolveErr := resolver.Resolve(token)
			if resolveErr != nil {
				return nil, fmt.Errorf("resolve enrollment token: %w", resolveErr)
			}
			token = resolved
		}
		resolver.Remember(token)
		enrollCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
		creds, err = client.Enroll(enrollCtx, httpClient, cfg.ServerURL, model.EnrollRequest{Token: token, Name: cfg.Name, Version: version.Version, Capabilities: capabilities()})
		cancel()
		if err == nil {
			err = client.SaveCredentials(cfg.CredentialsPath(), creds)
		}
	}
	if err != nil {
		return nil, fmt.Errorf("initialize credentials: %w", err)
	}
	cfg.EnrollmentToken = ""
	resolver.Remember(creds.Secret)
	sp, err := spool.Open(cfg.SpoolPath(), cfg.SpoolMaxResults)
	if err != nil {
		return nil, err
	}
	apiClient := client.New(cfg.ServerURL, httpClient, creds)
	a := &App{cfg: cfg, logger: logger, secrets: resolver, client: apiClient, spool: sp, uploadWake: make(chan struct{}, 1)}
	a.planVersion.Store("")
	registry := probe.NewRegistry(resolver)
	a.scheduler = scheduler.New(ctx, creds.AgentID, cfg.Concurrency, registry, a.storeResult)
	if cached, err := loadPlanCache(cfg.PlanCachePath()); err == nil && cached.AgentID == creds.AgentID {
		a.initialETag = cached.ETag
		a.planVersion.Store(cached.Plan.Version)
		a.scheduler.Update(cached.Plan.Monitors)
		logger.Info("cached monitor plan loaded", "version", cached.Plan.Version, "monitors", len(cached.Plan.Monitors))
	} else if err == nil {
		logger.Warn("cached monitor plan ignored", "error", "cached plan belongs to another agent")
	} else if !errors.Is(err, os.ErrNotExist) {
		logger.Warn("cached monitor plan ignored", "error", resolver.Redact(err.Error()))
	}
	return a, nil
}

func (a *App) Close() error {
	a.scheduler.Stop()
	return a.spool.Close()
}

func (a *App) Run(ctx context.Context) error {
	a.logger.Info("status agent started", "agent_id", a.client.AgentID(), "version", version.Version)
	planDone, uploadDone, heartbeatDone := make(chan struct{}), make(chan struct{}), make(chan struct{})
	go func() { defer close(planDone); a.planLoop(ctx) }()
	go func() { defer close(uploadDone); a.uploadLoop(ctx) }()
	go func() { defer close(heartbeatDone); a.heartbeatLoop(ctx) }()
	<-ctx.Done()
	a.scheduler.Stop()
	<-planDone
	<-uploadDone
	<-heartbeatDone
	return nil
}

func (a *App) planLoop(ctx context.Context) {
	etag := a.initialETag
	ticker := time.NewTicker(a.cfg.PlanPollInterval.Value())
	defer ticker.Stop()
	for {
		requestCtx, cancel := context.WithTimeout(ctx, 20*time.Second)
		plan, nextETag, changed, err := a.client.Plan(requestCtx, etag)
		cancel()
		if err != nil {
			a.logError("plan sync failed", err)
		} else if changed {
			if nextETag != "" {
				etag = nextETag
			}
			if err := savePlanCache(a.cfg.PlanCachePath(), cachedPlan{AgentID: a.client.AgentID(), ETag: etag, Plan: plan}); err != nil {
				a.logError("failed to persist monitor plan", err)
			}
			a.planVersion.Store(plan.Version)
			a.scheduler.Update(plan.Monitors)
			a.logger.Info("monitor plan applied", "version", plan.Version, "monitors", len(plan.Monitors))
		}
		select {
		case <-ticker.C:
		case <-ctx.Done():
			return
		}
	}
}

func (a *App) storeResult(ctx context.Context, result model.Result) error {
	if err := a.spool.Put(ctx, result); err != nil {
		a.logError("failed to persist probe result", err)
		return err
	}
	if result.Urgent() {
		select {
		case a.uploadWake <- struct{}{}:
		default:
		}
	}
	return nil
}

func (a *App) uploadLoop(ctx context.Context) {
	ticker := time.NewTicker(a.cfg.UploadInterval.Value())
	defer ticker.Stop()
	for {
		select {
		case <-ticker.C:
		case <-a.uploadWake:
		case <-ctx.Done():
			return
		}
		for batches := 0; batches < 10; batches++ {
			entries, err := a.spool.Batch(ctx, a.cfg.BatchSize)
			if err != nil {
				a.logError("failed to read result spool", err)
				break
			}
			if len(entries) == 0 {
				break
			}
			results, ids := make([]model.Result, len(entries)), make([]int64, len(entries))
			for i, entry := range entries {
				results[i], ids[i] = entry.Result, entry.ID
			}
			requestCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
			response, err := a.client.Results(requestCtx, results)
			cancel()
			if err != nil {
				a.logError("result upload failed", err)
				break
			}
			rejectedIndexes := make(map[int]string, len(response.Errors))
			for _, item := range response.Errors {
				rejectedIndexes[item.Index] = item.Code
			}
			acknowledged, rejected := make([]int64, 0, len(ids)), make([]int64, 0, len(response.Errors))
			for index, id := range ids {
				if _, rejectedByAPI := rejectedIndexes[index]; rejectedByAPI {
					rejected = append(rejected, id)
				} else {
					acknowledged = append(acknowledged, id)
				}
			}
			if err := a.spool.Delete(ctx, acknowledged); err != nil {
				a.logError("failed to acknowledge result spool", err)
				break
			}
			if err := a.spool.Discard(ctx, rejected); err != nil {
				a.logError("failed to discard rejected probe results", err)
				break
			}
			if len(rejected) > 0 {
				a.logger.Warn("control plane rejected probe results", "count", len(rejected), "codes", rejectedIndexes)
			}
			if len(entries) < a.cfg.BatchSize {
				break
			}
		}
	}
}

func (a *App) heartbeatLoop(ctx context.Context) {
	ticker := time.NewTicker(a.cfg.HeartbeatInterval.Value())
	defer ticker.Stop()
	for {
		stats, err := a.spool.Stats(ctx)
		if err != nil {
			a.logError("failed to inspect result spool", err)
		} else {
			planVersion, _ := a.planVersion.Load().(string)
			heartbeat := model.Heartbeat{Version: version.Version, PlanVersion: planVersion, ObservedAt: time.Now().UTC(), ActiveChecks: a.scheduler.Active(), SpoolDepth: stats.Depth, SpoolDropped: stats.Dropped}
			requestCtx, cancel := context.WithTimeout(ctx, 20*time.Second)
			err = a.client.Heartbeat(requestCtx, heartbeat)
			cancel()
			if err != nil {
				a.logError("heartbeat failed", err)
			}
		}
		select {
		case <-ticker.C:
		case <-ctx.Done():
			return
		}
	}
}

func (a *App) logError(message string, err error) {
	a.logger.Warn(message, "error", a.secrets.Redact(err.Error()))
}

func capabilities() []string {
	return []string{"http", "https", "nextjs", "laravel", "tcp", "dns", "tls", "squid", "mysql", "postgresql", "redis", "reverb", "pusher", "heartbeat", "push", "laravel_queue", "laravel_scheduler"}
}

func loadPlanCache(path string) (cachedPlan, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return cachedPlan{}, err
	}
	var cached cachedPlan
	if err := json.Unmarshal(b, &cached); err != nil || cached.AgentID == "" || cached.Plan.Version == "" {
		return cachedPlan{}, errors.New("invalid cached monitor plan")
	}
	return cached, nil
}

func savePlanCache(path string, cached cachedPlan) error {
	b, err := json.Marshal(cached)
	if err != nil {
		return err
	}
	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, b, 0600); err != nil {
		return err
	}
	if err := os.Chmod(tmp, 0600); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)
		return err
	}
	return nil
}
