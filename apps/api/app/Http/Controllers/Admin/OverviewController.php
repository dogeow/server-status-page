<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\DailyRollup;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\OutboxEvent;
use Illuminate\Http\JsonResponse;

class OverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $components = Component::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $availability = DailyRollup::query()
            ->where('date', '>=', now('UTC')->subDays(29)->toDateString())
            ->selectRaw('COALESCE(SUM(observed_seconds), 0) as observed, COALESCE(SUM(available_seconds), 0) as available')
            ->first();
        $observed = (int) ($availability?->observed ?? 0);
        $recentEvents = OutboxEvent::query()->latest('created_at')->limit(10)->get();
        $componentNames = Component::query()
            ->whereIn('id', $recentEvents->map(fn (OutboxEvent $event) => $event->payload['component_id'] ?? ($event->aggregate_type === 'component' ? $event->aggregate_id : null))->filter())
            ->pluck('name', 'id');
        $monitorNames = Monitor::query()
            ->whereIn('id', $recentEvents->pluck('payload')->pluck('monitor_id')->filter())
            ->pluck('name', 'id');

        return response()->json([
            'components' => [
                'total' => $components->sum(),
                'by_status' => $components,
                'healthy' => (int) ($components[ComponentStatus::Operational->value] ?? 0),
            ],
            'monitors' => ['total' => Monitor::query()->count(), 'enabled' => Monitor::query()->where('enabled', true)->count()],
            'agents' => ['total' => Agent::query()->count(), 'online' => Agent::query()->where('status', 'online')->count(), 'offline' => Agent::query()->where('status', 'offline')->count()],
            'active_incidents' => Incident::query()->whereNull('resolved_at')->with('components:id,name')->latest('started_at')->limit(10)->get(),
            'uptime_percent' => $observed > 0 ? round((int) $availability->available / $observed * 100, 4) : null,
            'recent_checks' => CheckResult::query()->with('monitor:id,name,type')->latest('scheduled_at')->limit(10)->get()->map(fn (CheckResult $result) => [
                'id' => $result->id,
                'name' => $result->monitor?->name ?? 'Deleted monitor',
                'type' => $result->monitor?->type,
                'status' => $result->status,
                'latency_ms' => $result->latency_ms,
                'updated_at' => $result->scheduled_at?->toIso8601String(),
            ]),
            'recent_events' => $recentEvents->map(function (OutboxEvent $event) use ($componentNames, $monitorNames): array {
                $payload = $event->payload ?? [];
                $componentId = $payload['component_id'] ?? ($event->aggregate_type === 'component' ? $event->aggregate_id : null);
                $monitorId = $payload['monitor_id'] ?? ($event->aggregate_type === 'monitor' ? $event->aggregate_id : null);
                $targetName = match ($event->aggregate_type) {
                    'component' => $componentNames->get((int) $componentId),
                    'monitor' => $monitorNames->get((int) $monitorId),
                    'agent' => $payload['name'] ?? null,
                    'incident', 'maintenance_window' => $payload['title'] ?? null,
                    default => $payload['title'] ?? $componentNames->get((int) $componentId) ?? $monitorNames->get((int) $monitorId),
                };

                return [
                    'id' => $event->id,
                    'event' => $event->type,
                    'target_name' => $targetName,
                    'from_status' => $payload['from'] ?? null,
                    'to_status' => $payload['to'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'severity' => $payload['severity'] ?? null,
                    'error_code' => $payload['error_code'] ?? null,
                    'expires_at' => $payload['expires_at'] ?? null,
                    'last_seen_at' => $payload['last_seen_at'] ?? null,
                    'starts_at' => $payload['starts_at'] ?? null,
                    'ends_at' => $payload['ends_at'] ?? null,
                    'created_at' => $event->created_at?->toIso8601String(),
                ];
            }),
            'pending_outbox' => OutboxEvent::query()->whereNull('processed_at')->count(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
