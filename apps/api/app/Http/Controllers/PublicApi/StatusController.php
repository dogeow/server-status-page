<?php

namespace App\Http\Controllers\PublicApi;

use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\DailyRollup;
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
        $timezone = $page->timezone ?: 'UTC';
        $from = CarbonImmutable::today($timezone)->subDays(89);
        $now = CarbonImmutable::now('UTC');
        $page->load([
            'groups.components' => fn ($query) => $query->where('is_hidden', false)->with([
                'rollups' => fn ($rollups) => $rollups->where('date', '>=', $from->toDateString())->orderBy('date'),
                'intervals' => fn ($intervals) => $intervals
                    ->where('started_at', '<', $now)
                    ->where(fn ($nested) => $nested->whereNull('ended_at')->orWhere('ended_at', '>', $from->utc()))
                    ->orderBy('started_at'),
                'incidents' => fn ($componentIncidents) => $componentIncidents
                    ->where('is_public', true)
                    ->where('started_at', '<', $now)
                    ->where(fn ($nested) => $nested->whereNull('resolved_at')->orWhere('resolved_at', '>', $from->utc()))
                    ->with('updates')
                    ->latest('started_at'),
            ]),
            'incidents' => fn ($query) => $query->where('is_public', true)->whereNull('resolved_at')->with(['updates', 'components:id,name,slug'])->latest('started_at'),
            'maintenanceWindows' => fn ($query) => $query->where('ends_at', '>=', now())->whereNotIn('status', ['cancelled', 'completed'])->with('components:id,name,slug')->orderBy('starts_at'),
        ]);

        $groups = $page->groups->map(function ($group) use ($from, $now, $timezone) {
            $components = $group->components->map(fn (Component $component) => $this->componentPayload($component, $from, $timezone, $now));
            $dailyHistory = collect(range(0, 89))->map(function (int $offset) use ($group, $from, $now, $timezone) {
                $date = $from->addDays($offset)->toDateString();
                $componentsForDate = $this->componentsExistingOnDate($group->components, $date, $timezone);
                $rollups = $componentsForDate->map(fn (Component $component) => $component->rollups->first(fn ($rollup) => $rollup->date->toDateString() === $date));
                $present = $rollups->filter();
                $latencies = $present->pluck('average_latency_ms')->filter(fn ($value) => $value !== null);

                return [
                    'date' => $date,
                    'status' => $this->worstStatus($rollups->map(fn ($rollup) => $rollup?->worst_status ?? ComponentStatus::Unknown->value)->all()),
                    'uptime_percent' => $this->rollupUptime($present),
                    'latency_ms' => $latencies->isEmpty() ? null : (int) round($latencies->avg()),
                    'maintenance' => $present->contains(fn ($rollup) => $rollup->maintenance_seconds > 0),
                    'status_periods' => $this->groupStatusPeriods($componentsForDate, $date, $timezone, $now),
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
            'history_available_from' => $this->historyAvailableFrom($page),
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

        $fromInstant = $from->utc();
        $toExclusive = $to->addDay()->utc();
        $now = CarbonImmutable::now('UTC');
        $page->load(['groups.components' => fn ($query) => $query->where('is_hidden', false)->with([
            'rollups' => fn ($rollups) => $rollups->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderBy('date'),
            'intervals' => fn ($intervals) => $intervals
                ->where('started_at', '<', $toExclusive)
                ->where(fn ($nested) => $nested->whereNull('ended_at')->orWhere('ended_at', '>', $fromInstant))
                ->orderBy('started_at'),
            'incidents' => fn ($componentIncidents) => $componentIncidents
                ->where('is_public', true)
                ->where('started_at', '<', $toExclusive)
                ->where(fn ($nested) => $nested->whereNull('resolved_at')->orWhere('resolved_at', '>', $fromInstant))
                ->with('updates')
                ->latest('started_at'),
        ])]);
        $incidents = $page->incidents()
            ->where('is_public', true)
            ->where('started_at', '<', $toExclusive)
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $fromInstant))
            ->with(['updates', 'components:id,name,slug'])
            ->latest('started_at')
            ->get();

        $groups = $page->groups->map(function ($group) use ($dates, $now, $timezone) {
            $components = $group->components->map(function (Component $component) use ($dates, $now, $timezone) {
                $rollups = $component->rollups->keyBy(fn ($rollup) => $rollup->date->toDateString());
                $dailyHistory = $dates->map(function (string $date) use ($component, $rollups, $now, $timezone): array {
                    $rollup = $rollups->get($date);
                    $payload = $rollup ? $this->rollupPayload($rollup) : $this->emptyRollupPayload($date);
                    $payload['status_periods'] = $this->componentStatusPeriods($component, $date, $timezone, $now);

                    return $payload;
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
            $dailyHistory = $dates->map(function (string $date) use ($group, $now, $timezone): array {
                $componentsForDate = $this->componentsExistingOnDate($group->components, $date, $timezone);
                $rollups = $componentsForDate->map(fn (Component $component) => $component->rollups->first(fn ($rollup) => $rollup->date->toDateString() === $date));
                $present = $rollups->filter();
                $latencies = $present->pluck('average_latency_ms')->filter(fn ($value) => $value !== null);

                return [
                    'date' => $date,
                    'status' => $this->worstStatus($rollups->map(fn ($rollup) => $rollup?->worst_status ?? ComponentStatus::Unknown->value)->all()),
                    'uptime_percent' => $this->rollupUptime($present),
                    'latency_ms' => $latencies->isEmpty() ? null : (int) round($latencies->avg()),
                    'maintenance' => $present->contains(fn ($rollup) => $rollup->maintenance_seconds > 0),
                    'status_periods' => $this->groupStatusPeriods($componentsForDate, $date, $timezone, $now),
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
            'history_available_from' => $this->historyAvailableFrom($page),
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

    private function componentPayload(Component $component, CarbonImmutable $from, string $timezone, CarbonImmutable $now): array
    {
        $rollups = $component->rollups->keyBy(fn ($rollup) => $rollup->date->toDateString());
        $history = collect(range(0, 89))->map(function (int $offset) use ($component, $rollups, $from, $now, $timezone) {
            $date = $from->addDays($offset)->toDateString();
            $rollup = $rollups->get($date);
            $payload = $rollup ? $this->rollupPayload($rollup) : $this->emptyRollupPayload($date);
            $payload['status_periods'] = $this->componentStatusPeriods($component, $date, $timezone, $now);

            return $payload;
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

    private function historyAvailableFrom(StatusPage $page): ?string
    {
        $date = DailyRollup::query()
            ->where('observed_seconds', '>', 0)
            ->whereHas('component', fn ($component) => $component
                ->where('is_hidden', false)
                ->whereHas('group', fn ($group) => $group->where('status_page_id', $page->id)))
            ->min('date');

        return $date === null ? null : (string) $date;
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

    private function componentStatusPeriods(Component $component, string $date, string $timezone, CarbonImmutable $now, bool $includeComponentName = false): array
    {
        $localDay = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $dayStart = $localDay->utc();
        $dayEnd = $localDay->addDay()->utc();
        $periodEnd = $dayEnd->lessThan($now) ? $dayEnd : $now;

        if (! $dayStart->lessThan($periodEnd)) {
            return [];
        }

        return $component->intervals
            ->filter(fn ($interval) => $interval->status !== ComponentStatus::Operational->value
                && $interval->started_at->lessThan($periodEnd)
                && ($interval->ended_at === null || $interval->ended_at->greaterThan($dayStart)))
            ->map(function ($interval) use ($component, $dayStart, $dayEnd, $periodEnd, $now, $includeComponentName): ?array {
                $startedAt = CarbonImmutable::instance($interval->started_at->greaterThan($dayStart) ? $interval->started_at : $dayStart);
                $intervalEnd = $interval->ended_at ? CarbonImmutable::instance($interval->ended_at) : $periodEnd;
                $endedAt = $intervalEnd->lessThan($periodEnd) ? $intervalEnd : $periodEnd;

                if (! $startedAt->lessThan($endedAt)) {
                    return null;
                }

                $ongoing = $interval->ended_at === null && $dayEnd->greaterThan($now);
                $payload = [
                    'status' => $interval->status,
                    'started_at' => $startedAt->toIso8601String(),
                    'ended_at' => $ongoing ? null : $endedAt->toIso8601String(),
                    'duration_seconds' => (int) $startedAt->diffInSeconds($endedAt),
                    'ongoing' => $ongoing,
                ];
                if ($includeComponentName) {
                    $payload['component_name'] = $component->name;
                }

                $incident = $component->incidents->first(fn (Incident $candidate) => $candidate->started_at->lessThan($endedAt)
                    && ($candidate->resolved_at === null || $candidate->resolved_at->greaterThan($startedAt)));
                if ($incident) {
                    $latestUpdate = $incident->updates->sortByDesc('created_at')->first();
                    $payload['incident_id'] = $incident->id;
                    $payload['incident_title'] = $incident->title;
                    $payload['incident_message'] = $latestUpdate?->message;
                }

                return $payload;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function groupStatusPeriods($components, string $date, string $timezone, CarbonImmutable $now): array
    {
        return $components
            ->flatMap(fn (Component $component) => $this->componentStatusPeriods($component, $date, $timezone, $now, true))
            ->sortBy('started_at')
            ->values()
            ->all();
    }

    private function componentsExistingOnDate($components, string $date, string $timezone)
    {
        $dayEnd = CarbonImmutable::parse($date, $timezone)->startOfDay()->addDay()->utc();

        return $components->filter(fn (Component $component) => $component->created_at === null || $component->created_at->lessThan($dayEnd));
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
