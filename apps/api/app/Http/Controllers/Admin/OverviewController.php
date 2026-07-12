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
            'recent_events' => OutboxEvent::query()->latest('created_at')->limit(10)->get()->map(fn (OutboxEvent $event) => [
                'id' => $event->id,
                'event' => $event->type,
                'message' => $event->payload['title'] ?? $event->payload['error_code'] ?? null,
                'created_at' => $event->created_at?->toIso8601String(),
            ]),
            'pending_outbox' => OutboxEvent::query()->whereNull('processed_at')->count(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
