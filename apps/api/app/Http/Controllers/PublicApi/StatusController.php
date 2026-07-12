<?php

namespace App\Http\Controllers\PublicApi;

use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\Incident;
use App\Models\StatusPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $page = $this->page($request->query('page'));
        $from = CarbonImmutable::today($page->timezone ?: 'UTC')->subDays(89);
        $page->load([
            'groups.components' => fn ($query) => $query->where('is_hidden', false)->with(['rollups' => fn ($rollups) => $rollups->where('date', '>=', $from->toDateString())->orderBy('date')]),
            'incidents' => fn ($query) => $query->where('is_public', true)->whereNull('resolved_at')->with(['updates', 'components:id,name,slug'])->latest('started_at'),
            'maintenanceWindows' => fn ($query) => $query->where('ends_at', '>=', now())->whereNotIn('status', ['cancelled', 'completed'])->with('components:id,name,slug')->orderBy('starts_at'),
        ]);

        $groups = $page->groups->map(function ($group) use ($from) {
            $components = $group->components->map(fn (Component $component) => $this->componentPayload($component, $from));
            $dailyHistory = collect(range(0, 89))->map(function (int $offset) use ($group, $from) {
                $date = $from->addDays($offset)->toDateString();
                $rollups = $group->components->map(fn (Component $component) => $component->rollups->first(fn ($rollup) => $rollup->date->toDateString() === $date));
                $present = $rollups->filter();
                $latencies = $present->pluck('average_latency_ms')->filter(fn ($value) => $value !== null);

                return [
                    'date' => $date,
                    'status' => $this->worstStatus($rollups->map(fn ($rollup) => $rollup?->worst_status ?? ComponentStatus::Unknown->value)->all()),
                    'uptime_percent' => $this->rollupUptime($present),
                    'latency_ms' => $latencies->isEmpty() ? null : (int) round($latencies->avg()),
                    'maintenance' => $present->contains(fn ($rollup) => $rollup->maintenance_seconds > 0),
                ];
            });

            return [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'status' => $this->worstStatus($components->pluck('status')->all()),
                'component_count' => $components->count(),
                'uptime_percent' => $this->rollupUptime($group->components->flatMap->rollups),
                'daily_history' => $dailyHistory,
                'components' => $components,
            ];
        });

        $payload = [
            'status_page' => [
                'id' => $page->id,
                'name' => $page->name,
                'slug' => $page->slug,
                'description' => $page->description,
                'timezone' => $page->timezone,
                'locale' => $page->locale,
            ],
            'overall_status' => $this->worstStatus($groups->pluck('status')->all()),
            'generated_at' => now()->toIso8601String(),
            'groups' => $groups,
            'incidents' => $page->incidents->map(fn (Incident $incident) => $this->incidentPayload($incident)),
            'maintenances' => $page->maintenanceWindows->map(fn ($window) => [
                'id' => $window->id,
                'title' => $window->name,
                'name' => $window->name,
                'message' => $window->message,
                'status' => $window->status,
                'starts_at' => $window->starts_at->toIso8601String(),
                'ends_at' => $window->ends_at->toIso8601String(),
                'components' => $window->components,
                'component_names' => $window->components->pluck('name')->values(),
            ]),
        ];

        $etagPayload = $payload;
        unset($etagPayload['generated_at']);
        $etag = '"'.hash('sha256', json_encode($etagPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=15, stale-if-error=86400']);
        }

        return response()->json($payload)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=15, stale-if-error=86400');
    }

    public function history(Request $request): JsonResponse
    {
        $page = $this->page($request->query('page'));
        $timezone = $page->timezone ?: 'UTC';
        $range = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $to = CarbonImmutable::parse($range['to'] ?? 'today', $timezone)->startOfDay();
        $from = CarbonImmutable::parse($range['from'] ?? $to->subDays(89)->toDateString(), $timezone)->startOfDay();
        abort_if($from->greaterThan($to), 422, 'History start must not be after its end.');
        abort_if($from->diffInDays($to) > 366, 422, 'History range may not exceed 366 days.');
        $dates = collect(range(0, (int) $from->diffInDays($to)))
            ->map(fn (int $offset) => $from->addDays($offset)->toDateString());

        $page->load(['groups.components' => fn ($query) => $query->where('is_hidden', false)->with(['rollups' => fn ($rollups) => $rollups->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date')])]);
        $fromInstant = $from->utc();
        $toExclusive = $to->addDay()->utc();
        $incidents = $page->incidents()
            ->where('is_public', true)
            ->where('started_at', '<', $toExclusive)
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $fromInstant))
            ->with(['updates', 'components:id,name,slug'])
            ->latest('started_at')
            ->get();

        $groups = $page->groups->map(function ($group) use ($dates) {
            $components = $group->components->map(function (Component $component) use ($dates) {
                $rollups = $component->rollups->keyBy(fn ($rollup) => $rollup->date->toDateString());
                $dailyHistory = $dates->map(function (string $date) use ($rollups): array {
                    $rollup = $rollups->get($date);

                    return $rollup ? $this->rollupPayload($rollup) : $this->emptyRollupPayload($date);
                });
                $latencies = $component->rollups->pluck('average_latency_ms')->filter(fn ($value) => $value !== null);

                return [
                    'id' => $component->id,
                    'name' => $component->name,
                    'slug' => $component->slug,
                    'description' => $component->description,
                    'status' => $component->status,
                    'uptime_percent' => $this->rollupUptime($component->rollups),
                    'latency_ms' => $latencies->isEmpty() ? null : (int) round($latencies->avg()),
                    'daily_history' => $dailyHistory,
                ];
            });
            $dailyHistory = $dates->map(function (string $date) use ($group): array {
                $rollups = $group->components->map(fn (Component $component) => $component->rollups->first(fn ($rollup) => $rollup->date->toDateString() === $date));
                $present = $rollups->filter();
                $latencies = $present->pluck('average_latency_ms')->filter(fn ($value) => $value !== null);

                return [
                    'date' => $date,
                    'status' => $this->worstStatus($rollups->map(fn ($rollup) => $rollup?->worst_status ?? ComponentStatus::Unknown->value)->all()),
                    'uptime_percent' => $this->rollupUptime($present),
                    'latency_ms' => $latencies->isEmpty() ? null : (int) round($latencies->avg()),
                    'maintenance' => $present->contains(fn ($rollup) => $rollup->maintenance_seconds > 0),
                ];
            });
            $latencies = $components->pluck('latency_ms')->filter(fn ($value) => $value !== null);

            return [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'status' => $this->worstStatus($components->pluck('status')->all()),
                'component_count' => $components->count(),
                'uptime_percent' => $this->rollupUptime($group->components->flatMap->rollups),
                'latency_ms' => $latencies->isEmpty() ? null : (int) round($latencies->avg()),
                'daily_history' => $dailyHistory,
                'components' => $components,
            ];
        })->values();

        $payload = [
            'status_page' => ['id' => $page->id, 'name' => $page->name, 'slug' => $page->slug],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'overall_status' => $this->worstStatus($groups->pluck('status')->all()),
            'generated_at' => now()->toIso8601String(),
            'groups' => $groups,
            'components' => $groups->flatMap(fn (array $group) => $group['components'])->values(),
            'incidents' => $incidents->map(fn (Incident $incident) => $this->incidentPayload($incident)),
        ];
        $etagPayload = $payload;
        unset($etagPayload['generated_at']);
        $etag = '"'.hash('sha256', json_encode($etagPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=60, stale-if-error=86400']);
        }

        return response()->json($payload)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=60, stale-if-error=86400');
    }

    public function incident(Request $request, int $incident): JsonResponse
    {
        $item = Incident::query()
            ->whereKey($incident)
            ->where('is_public', true)
            ->whereHas('statusPage', fn ($query) => $query->where('is_public', true))
            ->with(['statusPage:id,name,slug', 'updates', 'components:id,name,slug'])
            ->firstOrFail();

        $payload = $this->incidentPayload($item);

        return response()->json(['data' => $payload, 'incident' => $payload])
            ->header('Cache-Control', $item->resolved_at ? 'public, max-age=300' : 'public, max-age=15, stale-if-error=86400');
    }

    private function componentPayload(Component $component, CarbonImmutable $from): array
    {
        $rollups = $component->rollups->keyBy(fn ($rollup) => $rollup->date->toDateString());
        $history = collect(range(0, 89))->map(function (int $offset) use ($rollups, $from) {
            $date = $from->addDays($offset)->toDateString();
            $rollup = $rollups->get($date);

            return $rollup ? $this->rollupPayload($rollup) : [
                'date' => $date,
                'status' => ComponentStatus::Unknown->value,
                'uptime_percent' => null,
                'latency_ms' => null,
                'maintenance' => false,
            ];
        });
        $uptime = $this->rollupUptime($component->rollups);
        $monitorIds = $component->monitors()->pluck('id');
        $latency = CheckResult::query()->whereIn('monitor_id', $monitorIds)->whereNotNull('latency_ms')->latest('scheduled_at')->value('latency_ms');

        return [
            'id' => $component->id,
            'name' => $component->name,
            'slug' => $component->slug,
            'description' => $component->description,
            'status' => $component->status,
            'uptime_percent' => $uptime,
            'latency_ms' => $latency,
            'daily_history' => $history,
        ];
    }

    private function rollupPayload($rollup): array
    {
        return [
            'date' => $rollup->date->toDateString(),
            'status' => $rollup->worst_status,
            'uptime_percent' => (int) $rollup->observed_seconds === 0 ? null : (float) $rollup->uptime_percentage,
            'latency_ms' => $rollup->average_latency_ms,
            'maintenance' => $rollup->maintenance_seconds > 0,
        ];
    }

    private function emptyRollupPayload(string $date): array
    {
        return [
            'date' => $date,
            'status' => ComponentStatus::Unknown->value,
            'uptime_percent' => null,
            'latency_ms' => null,
            'maintenance' => false,
        ];
    }

    private function incidentPayload(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'title' => $incident->title,
            'status' => $incident->status,
            'impact' => $incident->impact,
            'started_at' => $incident->started_at->toIso8601String(),
            'resolved_at' => optional($incident->resolved_at)->toIso8601String(),
            'components' => $incident->components,
            'updates' => $incident->updates->sortBy('created_at')->values()->map(fn ($update) => [
                'id' => $update->id,
                'status' => $update->status,
                'message' => $update->message,
                'created_at' => $update->created_at->toIso8601String(),
            ]),
        ];
    }

    private function worstStatus(array $statuses): string
    {
        if ($statuses === []) {
            return ComponentStatus::Unknown->value;
        }

        return collect($statuses)->sortByDesc(fn (string $status) => ComponentStatus::tryFrom($status)?->weight() ?? 1)->first();
    }

    private function rollupUptime($rollups): ?float
    {
        $observed = (int) $rollups->sum('observed_seconds');

        return $observed === 0
            ? null
            : round((int) $rollups->sum('available_seconds') / $observed * 100, 4);
    }

    private function page(?string $slug): StatusPage
    {
        return StatusPage::query()->where('is_public', true)->when($slug, fn ($query) => $query->where('slug', $slug))->firstOrFail();
    }
}
