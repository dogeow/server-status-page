<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\OutboxEvent;
use App\Models\StatusInterval;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class StateEvaluator
{
    public function evaluate(CheckResult $result): bool
    {
        return DB::transaction(function () use ($result): bool {
            $monitor = Monitor::query()->lockForUpdate()->findOrFail($result->monitor_id);
            if ($monitor->last_checked_at !== null && $result->scheduled_at->lessThanOrEqualTo($monitor->last_checked_at)) {
                return false;
            }
            $component = Component::query()->with('group')->lockForUpdate()->findOrFail($monitor->component_id);
            $inMaintenance = $this->inMaintenance($component, $result->scheduled_at);
            $this->applyResult($monitor, $result, $inMaintenance);
            $this->applyAggregate($component, $result->scheduled_at, $inMaintenance);

            return true;
        });
    }

    public function reconcile(Component $component, CarbonInterface $at): void
    {
        DB::transaction(function () use ($component, $at): void {
            $locked = Component::query()->with('group')->lockForUpdate()->findOrFail($component->id);
            $this->applyAggregate($locked, $at, $this->inMaintenance($locked, $at));
        });
    }

    public function reconcileMaintenanceBoundaries(?CarbonInterface $at = null): int
    {
        $at = $at ? CarbonImmutable::parse($at)->utc() : CarbonImmutable::now('UTC');
        $activeWindow = function ($query) use ($at): void {
            $query->where('maintenance_windows.starts_at', '<=', $at)
                ->where('maintenance_windows.ends_at', '>=', $at)
                ->whereNotIn('maintenance_windows.status', ['cancelled', 'completed']);
        };
        $entering = Component::query()
            ->where('status', '!=', ComponentStatus::Maintenance->value)
            ->whereHas('maintenanceWindows', $activeWindow)
            ->pluck('id');
        $leaving = Component::query()
            ->where('status', ComponentStatus::Maintenance->value)
            ->whereDoesntHave('maintenanceWindows', $activeWindow)
            ->pluck('id');

        $changed = 0;
        foreach ($entering->merge($leaving)->unique() as $id) {
            $component = Component::query()->find($id);
            if (! $component) {
                continue;
            }
            $previous = $component->status;
            $this->reconcile($component, $at);
            if ($component->fresh()->status !== $previous) {
                $changed++;
            }
        }

        return $changed;
    }

    private function applyResult(Monitor $monitor, CheckResult $result, bool $suppressAlerts): void
    {
        $previous = $monitor->status;
        $status = strtolower($result->status);
        $isCertificateExpiryWarning = $result->error_code === 'tls_certificate_expiring';
        $isSuccess = $isCertificateExpiryWarning || in_array($status, ['ok', 'success', 'operational', 'pass'], true);
        $isConfigurationError = in_array($status, ['config_error', 'auth_error'], true);
        $isUnknown = $status === 'unknown';
        $isInconclusiveCanaryControl = $isUnknown && $result->error_code === 'canary_control_failed';
        $isSlow = ! $isCertificateExpiryWarning && $isSuccess && $monitor->slow_threshold_ms && $result->latency_ms > $monitor->slow_threshold_ms;

        if ($isInconclusiveCanaryControl) {
            $monitor->consecutive_failures = 0;
            $monitor->consecutive_successes = 0;
            $monitor->consecutive_slow = 0;
            $monitor->last_error_code = $result->error_code;
        } elseif ($isUnknown) {
            $monitor->status = ComponentStatus::Unknown->value;
            $monitor->consecutive_failures = 0;
            $monitor->consecutive_successes = 0;
            $monitor->consecutive_slow = 0;
            $monitor->last_error_code = $result->error_code;
        } elseif ($isConfigurationError) {
            $monitor->loadMissing('component.group');
            $errorCode = $result->error_code ?: $status;
            $shouldAlert = $previous !== ComponentStatus::Unknown->value
                || $monitor->last_error_code !== $errorCode
                || ! $monitor->last_alerted_at
                || $monitor->last_alerted_at->lte(now()->subMinutes(60));
            $monitor->status = ComponentStatus::Unknown->value;
            $monitor->consecutive_failures = 0;
            $monitor->consecutive_successes = 0;
            $monitor->consecutive_slow = 0;
            $monitor->last_error_code = $errorCode;
            if ($shouldAlert && ! $suppressAlerts) {
                $monitor->last_alerted_at = now();
                $this->outbox('monitor.configuration_error', 'monitor', $monitor->id, [
                    'monitor_id' => $monitor->id,
                    'component_id' => $monitor->component_id,
                    'status_page_id' => $monitor->component->group->status_page_id,
                    'error_code' => $errorCode,
                ]);
            }
        } elseif ($isSuccess) {
            if ($isCertificateExpiryWarning) {
                $monitor->loadMissing('component.group');
                $shouldAlert = $monitor->last_error_code !== $result->error_code
                    || ! $monitor->last_alerted_at
                    || $monitor->last_alerted_at->lte(now()->subDay());
                if ($shouldAlert && ! $suppressAlerts) {
                    $monitor->last_alerted_at = now();
                    $this->outbox('monitor.tls_certificate_expiring', 'monitor', $monitor->id, [
                        'monitor_id' => $monitor->id,
                        'component_id' => $monitor->component_id,
                        'status_page_id' => $monitor->component->group->status_page_id,
                        'error_code' => $result->error_code,
                        'expires_at' => data_get($result->metrics, 'expires_at'),
                    ]);
                }
            }
            $monitor->consecutive_failures = 0;
            $monitor->consecutive_successes = $isSlow ? 0 : $monitor->consecutive_successes + 1;
            $monitor->consecutive_slow = $isSlow ? $monitor->consecutive_slow + 1 : 0;
            $monitor->last_error_code = $isCertificateExpiryWarning ? $result->error_code : null;
            $monitor->last_success_at = $result->scheduled_at;

            if ($monitor->consecutive_slow >= 3) {
                $monitor->status = ComponentStatus::Degraded->value;
            } elseif ($monitor->status === ComponentStatus::Operational->value || $monitor->consecutive_successes >= 2) {
                $monitor->status = ComponentStatus::Operational->value;
            }
        } else {
            $monitor->consecutive_failures++;
            $monitor->consecutive_successes = 0;
            $monitor->consecutive_slow = 0;
            $monitor->last_error_code = null;

            if ($monitor->consecutive_failures >= 3) {
                $monitor->status = ComponentStatus::MajorOutage->value;
            } elseif ($monitor->consecutive_failures >= 2) {
                $monitor->status = ComponentStatus::Degraded->value;
            }
        }

        $monitor->last_checked_at = $result->scheduled_at;
        if ($monitor->status !== $previous) {
            $monitor->status_changed_at = $result->scheduled_at;
        }
        $monitor->save();
    }

    private function applyAggregate(Component $component, CarbonInterface $at, bool $inMaintenance): void
    {
        $component->load('group');
        $previous = $component->status;
        $statuses = $component->monitors()->where('enabled', true)->get(['status', 'config'])
            ->map(function (Monitor $monitor): string {
                if ($monitor->status === ComponentStatus::MajorOutage->value
                    && ($monitor->config['max_public_status'] ?? null) === ComponentStatus::Degraded->value) {
                    return ComponentStatus::Degraded->value;
                }

                return $monitor->status;
            });

        if ($inMaintenance) {
            $next = ComponentStatus::Maintenance->value;
        } elseif ($statuses->isEmpty() || $statuses->every(fn (string $value) => $value === ComponentStatus::Unknown->value)) {
            $next = ComponentStatus::Unknown->value;
        } elseif ($statuses->contains(ComponentStatus::MajorOutage->value)) {
            $next = $statuses->contains(ComponentStatus::Operational->value)
                ? ComponentStatus::PartialOutage->value
                : ComponentStatus::MajorOutage->value;
        } elseif ($statuses->contains(ComponentStatus::PartialOutage->value)) {
            $next = ComponentStatus::PartialOutage->value;
        } elseif ($statuses->contains(ComponentStatus::Degraded->value)) {
            $next = ComponentStatus::Degraded->value;
        } else {
            $next = ComponentStatus::Operational->value;
        }

        if ($next === $previous) {
            return;
        }

        $component->update(['status' => $next, 'status_changed_at' => $at]);
        StatusInterval::query()->where('component_id', $component->id)->whereNull('ended_at')->update(['ended_at' => $at]);
        StatusInterval::query()->create([
            'component_id' => $component->id,
            'status' => $next,
            'started_at' => $at,
            'is_maintenance' => $inMaintenance,
        ]);

        if (! $inMaintenance) {
            $this->syncAutomaticIncident($component, $next, $at);
        }

        $this->outbox('component.status_changed', 'component', $component->id, [
            'component_id' => $component->id,
            'status_page_id' => $component->group->status_page_id,
            'from' => $previous,
            'to' => $next,
            'at' => CarbonImmutable::parse($at)->utc()->toIso8601String(),
        ]);
    }

    private function syncAutomaticIncident(Component $component, string $next, $at): void
    {
        $active = Incident::query()
            ->where('is_automatic', true)
            ->whereNull('resolved_at')
            ->whereHas('components', fn ($query) => $query->where('components.id', $component->id))
            ->latest('id')
            ->first();

        $impact = $this->impactForStatus($next);

        if ($impact && ! $active) {
            $incident = Incident::query()->create([
                'status_page_id' => $component->group->status_page_id,
                'title' => $component->name.' 服务异常',
                'status' => 'investigating',
                'impact' => $impact,
                'is_automatic' => true,
                'is_public' => true,
                'started_at' => $at,
            ]);
            $incident->components()->attach($component->id);
            $incident->updates()->create([
                'status' => 'investigating',
                'message' => '自动监控检测到服务异常，等待管理员处理。',
            ]);
            $this->outbox('incident.created', 'incident', $incident->id, $this->incidentPayload($incident, $component));

            return;
        }

        if (! $active) {
            return;
        }

        $active->load('components');
        $statuses = Component::query()->whereIn('id', $active->components->pluck('id'))->pluck('status');
        $faultImpact = $this->worstImpact($statuses->all());
        if ($faultImpact) {
            if ($active->impact !== $faultImpact) {
                $active->update(['impact' => $faultImpact]);
                $active->updates()->create([
                    'status' => $active->status,
                    'message' => '监控状态已更新为'.$this->impactLabel($faultImpact).'。',
                ]);
                $this->outbox('incident.updated', 'incident', $active->id, $this->incidentPayload($active, $component));
            }

            return;
        }

        $allOperational = $statuses->isNotEmpty() && $statuses->every(
            fn (string $status) => $status === ComponentStatus::Operational->value,
        );
        if ($allOperational) {
            $active->update(['status' => 'resolved', 'resolved_at' => $at]);
            $active->updates()->create(['status' => 'resolved', 'message' => '监控确认服务已经恢复。']);
            $this->outbox('incident.resolved', 'incident', $active->id, $this->incidentPayload($active, $component));
        }
    }

    private function impactForStatus(string $status): ?string
    {
        return match ($status) {
            ComponentStatus::MajorOutage->value => 'major_outage',
            ComponentStatus::PartialOutage->value => 'partial_outage',
            ComponentStatus::Degraded->value => 'degraded_performance',
            default => null,
        };
    }

    /** @param  list<string>  $statuses */
    private function worstImpact(array $statuses): ?string
    {
        $rank = ['degraded_performance' => 1, 'partial_outage' => 2, 'major_outage' => 3];
        $impact = null;
        foreach ($statuses as $status) {
            $candidate = $this->impactForStatus($status);
            if ($candidate && ($impact === null || $rank[$candidate] > $rank[$impact])) {
                $impact = $candidate;
            }
        }

        return $impact;
    }

    private function impactLabel(string $impact): string
    {
        return match ($impact) {
            'major_outage' => '严重中断',
            'partial_outage' => '部分中断',
            'degraded_performance' => '性能下降',
            default => '异常',
        };
    }

    private function inMaintenance(Component $component, $at): bool
    {
        return MaintenanceWindow::query()
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>=', $at)
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereHas('components', fn ($query) => $query->where('components.id', $component->id))
            ->exists();
    }

    private function incidentPayload(Incident $incident, Component $component): array
    {
        return [
            'incident_id' => $incident->id,
            'status_page_id' => $incident->status_page_id,
            'event_type' => 'incident.'.$incident->status,
            'status' => $incident->status,
            'severity' => $incident->impact,
            'title' => $incident->title,
            'component' => ['id' => $component->id, 'name' => $component->name],
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    private function outbox(string $type, string $aggregateType, int|string $aggregateId, array $payload): void
    {
        OutboxEvent::query()->create([
            'type' => $type,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'payload' => $payload,
            'available_at' => now(),
        ]);
    }
}
