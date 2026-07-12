<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Agent;
use App\Models\Component;
use App\Models\MaintenanceWindow;
use App\Models\OutboxEvent;
use App\Models\StatusInterval;
use Illuminate\Support\Facades\DB;

class AgentStatusService
{
    public function markStaleAgents(): int
    {
        $cutoff = now()->subSeconds((int) config('status.agent_offline_after_seconds', 180));
        $marked = 0;
        $agents = Agent::query()
            ->where('status', 'online')
            ->where('id', '!=', PushResultRecorder::PUSH_AGENT_ID)
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
            ->get();

        foreach ($agents as $agent) {
            DB::transaction(function () use ($agent, $cutoff, &$marked): void {
                $lockedAgent = Agent::query()->lockForUpdate()->find($agent->id);
                if (! $lockedAgent || $lockedAgent->status !== 'online' || ($lockedAgent->last_seen_at && $lockedAgent->last_seen_at->gte($cutoff))) {
                    return;
                }

                $lockedAgent->update(['status' => 'offline']);
                $marked++;
                $componentIds = $lockedAgent->monitors()->where('enabled', true)->pluck('component_id')->unique();
                $components = Component::query()->with('group')->whereIn('id', $componentIds)->get();
                foreach ($components as $component) {
                    $component->monitors()->where('agent_id', $lockedAgent->id)->update([
                        'status' => ComponentStatus::Unknown->value,
                        'consecutive_failures' => 0,
                        'consecutive_successes' => 0,
                    ]);
                    $this->aggregateComponent($component);
                }
                $pageIds = $components->pluck('group.status_page_id')->filter()->unique();
                foreach ($pageIds as $pageId) {
                    OutboxEvent::query()->create([
                        'type' => 'agent.offline',
                        'aggregate_type' => 'agent',
                        'aggregate_id' => $lockedAgent->id,
                        'payload' => [
                            'status_page_id' => $pageId,
                            'agent_id' => $lockedAgent->id,
                            'name' => $lockedAgent->name,
                            'component_ids' => $components->where('group.status_page_id', $pageId)->pluck('id')->all(),
                            'last_seen_at' => optional($lockedAgent->last_seen_at)->toIso8601String(),
                        ],
                        'available_at' => now(),
                    ]);
                }
            });
        }

        return $marked;
    }

    private function aggregateComponent(Component $component): void
    {
        $statuses = $component->monitors()->where('enabled', true)->pluck('status');
        $maintenance = MaintenanceWindow::query()
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereHas('components', fn ($query) => $query->where('components.id', $component->id))
            ->exists();
        $next = match (true) {
            $maintenance => ComponentStatus::Maintenance->value,
            $statuses->isEmpty() || $statuses->every(fn (string $status) => $status === ComponentStatus::Unknown->value) => ComponentStatus::Unknown->value,
            $statuses->contains(ComponentStatus::MajorOutage->value) && $statuses->contains(ComponentStatus::Operational->value) => ComponentStatus::PartialOutage->value,
            $statuses->contains(ComponentStatus::MajorOutage->value) => ComponentStatus::MajorOutage->value,
            $statuses->contains(ComponentStatus::PartialOutage->value) => ComponentStatus::PartialOutage->value,
            $statuses->contains(ComponentStatus::Degraded->value) => ComponentStatus::Degraded->value,
            default => ComponentStatus::Operational->value,
        };

        if ($component->status === $next) {
            return;
        }
        $previous = $component->status;
        StatusInterval::query()->where('component_id', $component->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        $component->update(['status' => $next, 'status_changed_at' => now()]);
        StatusInterval::query()->create(['component_id' => $component->id, 'status' => $next, 'started_at' => now(), 'is_maintenance' => $maintenance]);
        OutboxEvent::query()->create([
            'type' => 'component.status_changed',
            'aggregate_type' => 'component',
            'aggregate_id' => (string) $component->id,
            'payload' => [
                'component_id' => $component->id,
                'status_page_id' => $component->group->status_page_id,
                'from' => $previous,
                'to' => $next,
                'at' => now()->toIso8601String(),
            ],
            'available_at' => now(),
        ]);
    }
}
