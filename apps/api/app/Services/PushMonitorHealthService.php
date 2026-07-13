<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Monitor;
use App\Models\QueueProbeRun;
use Carbon\CarbonImmutable;

class PushMonitorHealthService
{
    public function __construct(private readonly PushResultRecorder $recorder) {}

    public function evaluate(): int
    {
        $evaluated = 0;
        $now = CarbonImmutable::now('UTC')->startOfSecond();

        foreach (Monitor::query()->where('enabled', true)->whereIn('type', ['heartbeat', 'laravel_queue', 'laravel_scheduler'])->get() as $monitor) {
            $degradedAfter = max(30, (int) (($monitor->config['degraded_after_seconds'] ?? null) ?: 150));
            $downAfter = max($degradedAfter + 1, (int) (($monitor->config['down_after_seconds'] ?? null) ?: 210));

            if ($monitor->type === 'heartbeat') {
                $lastSuccess = $monitor->last_success_at ?: $monitor->created_at;
                $age = $lastSuccess->diffInSeconds($now);
                if ($age >= $downAfter && $monitor->status !== ComponentStatus::MajorOutage->value) {
                    $monitor->update(['consecutive_failures' => max(2, $monitor->consecutive_failures)]);
                    $this->recorder->record($monitor->fresh(), 'timeout', $now, null, 'heartbeat_missing', ['age_seconds' => $age]);
                    $evaluated++;
                } elseif ($age >= $degradedAfter && ! in_array($monitor->status, [ComponentStatus::Degraded->value, ComponentStatus::MajorOutage->value], true)) {
                    $monitor->update(['consecutive_failures' => max(1, $monitor->consecutive_failures)]);
                    $this->recorder->record($monitor->fresh(), 'timeout', $now, null, 'heartbeat_late', ['age_seconds' => $age]);
                    $evaluated++;
                }

                continue;
            }

            if ($monitor->type === 'laravel_scheduler') {
                $lastSuccess = $monitor->last_success_at ?: $monitor->created_at;
                $age = $lastSuccess->diffInSeconds($now);
                if ($age >= $downAfter && $monitor->status !== ComponentStatus::MajorOutage->value) {
                    $monitor->update(['consecutive_failures' => max(2, $monitor->consecutive_failures)]);
                    $this->recorder->record($monitor->fresh(), 'timeout', $now, null, 'scheduler_heartbeat_missing', ['age_seconds' => $age]);
                    $evaluated++;
                } elseif ($age >= $degradedAfter && ! in_array($monitor->status, [ComponentStatus::Degraded->value, ComponentStatus::MajorOutage->value], true)) {
                    $monitor->update(['consecutive_failures' => max(1, $monitor->consecutive_failures)]);
                    $this->recorder->record($monitor->fresh(), 'timeout', $now, null, 'scheduler_heartbeat_late', ['age_seconds' => $age]);
                    $evaluated++;
                }

                continue;
            }

            $run = QueueProbeRun::query()->where('monitor_id', $monitor->id)->where('status', 'pending')->oldest('enqueued_at')->first();
            if (! $run) {
                $lastSignal = collect([$monitor->last_event_at, $monitor->last_success_at, $monitor->created_at])
                    ->filter()
                    ->sortBy(fn ($value) => $value->getTimestamp())
                    ->last();
                $age = $lastSignal->diffInSeconds($now);
                if ($age >= $downAfter && $monitor->status !== ComponentStatus::MajorOutage->value) {
                    $monitor->update(['consecutive_failures' => max(2, $monitor->consecutive_failures)]);
                    $this->recorder->record($monitor->fresh(), 'timeout', $now, null, 'queue_probe_not_enqueued', ['age_seconds' => $age]);
                    $evaluated++;
                } elseif ($age >= $degradedAfter && ! in_array($monitor->status, [ComponentStatus::Degraded->value, ComponentStatus::MajorOutage->value], true)) {
                    $monitor->update(['consecutive_failures' => max(1, $monitor->consecutive_failures)]);
                    $this->recorder->record($monitor->fresh(), 'timeout', $now, null, 'queue_probe_late', ['age_seconds' => $age]);
                    $evaluated++;
                }

                continue;
            }
            $age = $run->enqueued_at->diffInSeconds($now);
            if ($age >= $downAfter && ! $run->down_at) {
                $monitor->update(['consecutive_failures' => max(2, $monitor->consecutive_failures)]);
                $this->recorder->record($monitor->fresh(), 'timeout', $now, null, 'queue_canary_timeout', ['probe_id' => $run->probe_id, 'target' => $run->target, 'age_seconds' => $age]);
                $run->update(['degraded_at' => $run->degraded_at ?: $now, 'down_at' => $now, 'status' => 'timed_out']);
                $evaluated++;
            } elseif ($age >= $degradedAfter && ! $run->degraded_at) {
                $monitor->update(['consecutive_failures' => max(1, $monitor->consecutive_failures)]);
                $this->recorder->record($monitor->fresh(), 'timeout', $now, null, 'queue_canary_late', ['probe_id' => $run->probe_id, 'target' => $run->target, 'age_seconds' => $age]);
                $run->update(['degraded_at' => $now]);
                $evaluated++;
            }
        }

        return $evaluated;
    }
}
