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
use Illuminate\Support\Facades\DB;

class StateEvaluator
{
    public function evaluate(CheckResult $result): void
    {
        DB::transaction(function () use ($result): void {
            $monitor = Monitor::query()->lockForUpdate()->findOrFail($result->monitor_id);
            $component = Component::query()->with('group')->lockForUpdate()->findOrFail($monitor->component_id);
            $inMaintenance = $this->inMaintenance($component, $result->scheduled_at);
            $this->applyResult($monitor, $result, $inMaintenance);
            $this->aggregateComponent($component, $result, $inMaintenance);
        });
    }

    private function applyResult(Monitor $monitor, CheckResult $result, bool $suppressAlerts): void
    {
        $previous = $monitor->status;
        $status = strtolower($result->status);
        $isCertificateExpiryWarning = $result->error_code === 'tls_certificate_expiring';
        $isSuccess = $isCertificateExpiryWarning || in_array($status, ['ok', 'success', 'operational', 'pass'], true);
        $isConfigurationError = in_array($status, ['config_error', 'auth_error'], true);
        $isUnknown = $status === 'unknown';
        $isSlow = ! $isCertificateExpiryWarning && $isSuccess && $monitor->slow_threshold_ms && $result->latency_ms > $monitor->slow_threshold_ms;

        if ($isUnknown) {
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

    private function aggregateComponent(Component $component, CheckResult $result, bool $inMaintenance): void
    {
        $component->load('group');
        $previous = $component->status;
        $statuses = $component->monitors()->where('enabled', true)->pluck('status');

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

        $component->update(['status' => $next, 'status_changed_at' => $result->scheduled_at]);
        StatusInterval::query()->where('component_id', $component->id)->whereNull('ended_at')->update(['ended_at' => $result->scheduled_at]);
        StatusInterval::query()->create([
            'component_id' => $component->id,
            'status' => $next,
            'started_at' => $result->scheduled_at,
            'is_maintenance' => $inMaintenance,
        ]);

        if (! $inMaintenance) {
            $this->syncAutomaticIncident($component, $previous, $next, $result->scheduled_at);
        }

        $this->outbox('component.status_changed', 'component', $component->id, [
            'component_id' => $component->id,
            'status_page_id' => $component->group->status_page_id,
            'from' => $previous,
            'to' => $next,
            'at' => $result->scheduled_at->toIso8601String(),
        ]);
    }

    private function syncAutomaticIncident(Component $component, string $previous, string $next, $at): void
    {
        $active = Incident::query()
            ->where('is_automatic', true)
            ->whereNull('resolved_at')
            ->whereHas('components', fn ($query) => $query->where('components.id', $component->id))
            ->latest('id')
            ->first();

        $impact = match ($next) {
            ComponentStatus::MajorOutage->value => 'major_outage',
            ComponentStatus::PartialOutage->value => 'partial_outage',
            ComponentStatus::Degraded->value => 'degraded_performance',
            default => null,
        };

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
        } elseif ($impact && $active && $active->impact !== $impact) {
            $active->update(['impact' => $impact]);
            $active->updates()->create(['status' => $active->status, 'message' => '监控状态已更新为 '.$impact.'。']);
            $this->outbox('incident.updated', 'incident', $active->id, $this->incidentPayload($active, $component));
        } elseif ($next === ComponentStatus::Operational->value && $active) {
            $active->update(['status' => 'resolved', 'resolved_at' => $at]);
            $active->updates()->create(['status' => 'resolved', 'message' => '监控确认服务已经恢复。']);
            $this->outbox('incident.resolved', 'incident', $active->id, $this->incidentPayload($active, $component));
        }
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
