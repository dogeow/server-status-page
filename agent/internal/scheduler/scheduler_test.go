package scheduler

import (
	"context"
	"sync/atomic"
	"testing"
	"time"

	"github.com/statusforge/status-agent/internal/model"
)

func TestJitteredIntervalBounds(t *testing.T) {
	base := 100 * time.Second
	if got := JitteredInterval(base, .10, 0); got != 90*time.Second {
		t.Fatalf("low bound = %s", got)
	}
	if got := JitteredInterval(base, .10, 1); got != 110*time.Second {
		t.Fatalf("high bound = %s", got)
	}
	if got := JitteredInterval(base, .10, .5); got != base {
		t.Fatalf("midpoint = %s", got)
	}
}

type fakeRunner struct {
	calls   atomic.Int64
	active  atomic.Int64
	max     atomic.Int64
	release <-chan struct{}
}

func (f *fakeRunner) Validate(model.Monitor) error { return nil }
func (f *fakeRunner) IsPassive(model.Monitor) bool { return false }
func (f *fakeRunner) Run(ctx context.Context, monitor model.Monitor, agent string, at time.Time) model.Result {
	f.calls.Add(1)
	active := f.active.Add(1)
	for {
		old := f.max.Load()
		if active <= old || f.max.CompareAndSwap(old, active) {
			break
		}
	}
	select {
	case <-f.release:
	case <-ctx.Done():
	}
	f.active.Add(-1)
	return model.Result{MonitorID: monitor.ID, AgentID: agent, ScheduledAt: at, ConfigVersion: monitor.ConfigVersion, Status: model.StatusOK}
}

func TestSchedulerLimitsConcurrencyAndDoesNotDuplicateUnchangedPlan(t *testing.T) {
	release := make(chan struct{})
	runner := &fakeRunner{release: release}
	scheduler := New(context.Background(), "agent", 1, runner, func(context.Context, model.Result) error { return nil })
	monitors := []model.Monitor{{ID: "one", Type: "tcp", Enabled: true, ConfigVersion: "1"}, {ID: "two", Type: "tcp", Enabled: true, ConfigVersion: "1"}}
	scheduler.Update(monitors)
	time.Sleep(30 * time.Millisecond)
	scheduler.Update(monitors)
	if got := runner.max.Load(); got != 1 {
		t.Fatalf("max concurrency = %d, want 1", got)
	}
	if got := runner.calls.Load(); got != 1 {
		t.Fatalf("calls before release = %d, want 1", got)
	}
	close(release)
	time.Sleep(30 * time.Millisecond)
	scheduler.Stop()
}
