package scheduler

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"math/rand"
	"sync"
	"sync/atomic"
	"time"

	"github.com/statusforge/status-agent/internal/model"
)

type Runner interface {
	Validate(model.Monitor) error
	IsPassive(model.Monitor) bool
	Run(context.Context, model.Monitor, string, time.Time) model.Result
}

type Sink func(context.Context, model.Result) error

type task struct {
	fingerprint string
	cancel      context.CancelFunc
	done        chan struct{}
}

type Scheduler struct {
	ctx       context.Context
	cancel    context.CancelFunc
	agentID   string
	runner    Runner
	sink      Sink
	semaphore chan struct{}
	mu        sync.Mutex
	tasks     map[string]*task
	rngMu     sync.Mutex
	rng       *rand.Rand
	active    atomic.Int64
}

func New(parent context.Context, agentID string, concurrency int, runner Runner, sink Sink) *Scheduler {
	ctx, cancel := context.WithCancel(parent)
	return &Scheduler{ctx: ctx, cancel: cancel, agentID: agentID, runner: runner, sink: sink, semaphore: make(chan struct{}, concurrency), tasks: make(map[string]*task), rng: rand.New(rand.NewSource(time.Now().UnixNano()))}
}

func (s *Scheduler) Update(monitors []model.Monitor) {
	desired := make(map[string]model.Monitor, len(monitors))
	for _, monitor := range monitors {
		if monitor.Enabled && !s.runner.IsPassive(monitor) && monitor.ID != "" {
			desired[monitor.ID] = monitor
		}
	}

	type stopped struct {
		monitor model.Monitor
		task    *task
	}
	var stop []stopped
	s.mu.Lock()
	for id, running := range s.tasks {
		monitor, wanted := desired[id]
		if !wanted || running.fingerprint != fingerprint(monitor) {
			delete(s.tasks, id)
			stop = append(stop, stopped{monitor: monitor, task: running})
		}
	}
	s.mu.Unlock()
	for _, item := range stop {
		item.task.cancel()
		<-item.task.done
	}

	s.mu.Lock()
	defer s.mu.Unlock()
	for id, monitor := range desired {
		if _, exists := s.tasks[id]; exists {
			continue
		}
		taskCtx, cancel := context.WithCancel(s.ctx)
		t := &task{fingerprint: fingerprint(monitor), cancel: cancel, done: make(chan struct{})}
		s.tasks[id] = t
		go s.loop(taskCtx, monitor, t.done)
	}
}

func (s *Scheduler) Stop() {
	s.cancel()
	s.mu.Lock()
	tasks := make([]*task, 0, len(s.tasks))
	for _, running := range s.tasks {
		running.cancel()
		tasks = append(tasks, running)
	}
	s.tasks = make(map[string]*task)
	s.mu.Unlock()
	for _, running := range tasks {
		<-running.done
	}
}

func (s *Scheduler) Active() int { return int(s.active.Load()) }

func (s *Scheduler) loop(ctx context.Context, monitor model.Monitor, done chan struct{}) {
	defer close(done)
	for {
		scheduledAt := time.Now()
		select {
		case s.semaphore <- struct{}{}:
		case <-ctx.Done():
			return
		}
		s.active.Add(1)
		probeCtx, cancel := context.WithTimeout(ctx, monitor.Timeout())
		result := s.runner.Run(probeCtx, monitor, s.agentID, scheduledAt)
		cancel()
		s.active.Add(-1)
		<-s.semaphore
		if ctx.Err() == nil {
			_ = s.sink(ctx, result)
		}

		interval := monitor.Interval()
		if interval == 0 {
			interval = 60 * time.Second
		}
		if interval < 15*time.Second {
			interval = 15 * time.Second
		}
		if interval > 24*time.Hour {
			interval = 24 * time.Hour
		}
		// time.Since retains Go's monotonic clock reading. Subtract execution and
		// semaphore wait time so frequency is measured start-to-start without ever
		// overlapping a monitor with itself.
		delay := s.jitter(interval) - time.Since(scheduledAt)
		if delay < 0 {
			delay = 0
		}
		timer := time.NewTimer(delay)
		select {
		case <-timer.C:
		case <-ctx.Done():
			if !timer.Stop() {
				select {
				case <-timer.C:
				default:
				}
			}
			return
		}
	}
}

func JitteredInterval(base time.Duration, fraction, random float64) time.Duration {
	if base <= 0 {
		return base
	}
	if fraction < 0 {
		fraction = -fraction
	}
	if fraction > 1 {
		fraction = 1
	}
	if random < 0 {
		random = 0
	}
	if random > 1 {
		random = 1
	}
	factor := 1 - fraction + 2*fraction*random
	return time.Duration(float64(base) * factor)
}

func (s *Scheduler) jitter(interval time.Duration) time.Duration {
	s.rngMu.Lock()
	value := s.rng.Float64()
	s.rngMu.Unlock()
	return JitteredInterval(interval, 0.10, value)
}

func fingerprint(monitor model.Monitor) string {
	b, _ := json.Marshal(monitor)
	hash := sha256.Sum256(b)
	return hex.EncodeToString(hash[:])
}
