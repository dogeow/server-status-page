<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Agent;
use App\Models\Component;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;

class AgentStatusService
{
    public function __construct(private readonly StateEvaluator $evaluator) {}

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
                    $this->evaluator->reconcile($component, now());
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
}
