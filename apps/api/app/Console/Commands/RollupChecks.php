<?php

namespace App\Console\Commands;

use App\Enums\ComponentStatus;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\DailyRollup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class RollupChecks extends Command
{
    protected $signature = 'status:rollup {--date= : UTC date, defaults to yesterday}';

    protected $description = 'Build time-weighted daily component availability from status intervals';

    public function handle(): int
    {
        $requestedDate = $this->option('date') ?: 'yesterday';
        $now = CarbonImmutable::now('UTC');

        foreach (Component::query()->with(['group.statusPage', 'monitors:id,component_id'])->get() as $component) {
            $timezone = $component->group?->statusPage?->timezone ?: 'UTC';
            $localDay = CarbonImmutable::parse($requestedDate, $timezone)->startOfDay();
            $day = $localDay->utc();
            $nextDay = $localDay->addDay()->utc();
            $periodEnd = $nextDay->lessThan($now) ? $nextDay : $now;

            if (! $day->lessThan($periodEnd)) {
                continue;
            }

            $component->load([
                'intervals' => fn ($query) => $query->where('started_at', '<', $periodEnd)->where(fn ($nested) => $nested->whereNull('ended_at')->orWhere('ended_at', '>', $day))->orderBy('started_at'),
                'maintenanceWindows' => fn ($query) => $query->where('exclude_from_uptime', true)->where('starts_at', '<', $periodEnd)->where('ends_at', '>', $day),
            ]);

            $results = CheckResult::query()
                ->whereIn('monitor_id', $component->monitors->pluck('id'))
                ->where('scheduled_at', '>=', $day)
                ->where('scheduled_at', '<', $periodEnd)
                ->get();

            $maintenanceRanges = $component->maintenanceWindows->map(fn ($window) => [
                $this->later($window->starts_at, $day),
                $this->earlier($window->ends_at, $periodEnd),
            ])->all();
            foreach ($component->intervals as $interval) {
                if ($interval->is_maintenance || $interval->status === ComponentStatus::Maintenance->value) {
                    $maintenanceRanges[] = [
                        $this->later($interval->started_at, $day),
                        $this->earlier($interval->ended_at ?: $periodEnd, $periodEnd),
                    ];
                }
            }
            $maintenanceRanges = $this->mergeRanges($maintenanceRanges);
            $maintenanceSeconds = $this->rangeSeconds($maintenanceRanges);

            $secondsByStatus = [];
            foreach ($component->intervals as $interval) {
                if ($interval->is_maintenance || $interval->status === ComponentStatus::Maintenance->value) {
                    continue;
                }
                $start = $this->later($interval->started_at, $day);
                $end = $this->earlier($interval->ended_at ?: $periodEnd, $periodEnd);
                $seconds = max(0, (int) $start->diffInSeconds($end) - $this->overlapSeconds($start, $end, $maintenanceRanges));
                $secondsByStatus[$interval->status] = ($secondsByStatus[$interval->status] ?? 0) + $seconds;
            }

            if ($secondsByStatus === [] && $results->isEmpty() && $maintenanceSeconds === 0) {
                continue;
            }
            $observedSeconds = array_sum(array_filter(
                $secondsByStatus,
                fn (int $seconds, string $status) => $status !== ComponentStatus::Unknown->value,
                ARRAY_FILTER_USE_BOTH,
            ));
            $availableSeconds = ($secondsByStatus[ComponentStatus::Operational->value] ?? 0)
                + ($secondsByStatus[ComponentStatus::Degraded->value] ?? 0);
            $uptime = $observedSeconds === 0 ? 100.0 : round($availableSeconds / $observedSeconds * 100, 4);
            $effectiveStatuses = array_keys(array_filter($secondsByStatus, fn (int $seconds) => $seconds > 0));
            $worst = $effectiveStatuses === []
                ? ($maintenanceSeconds > 0 ? ComponentStatus::Maintenance->value : ComponentStatus::Unknown->value)
                : collect($effectiveStatuses)->sortByDesc(fn (string $status) => ComponentStatus::tryFrom($status)?->weight() ?? 1)->first();
            $failedChecks = $results->reject(fn ($result) => in_array($result->status, ['ok', 'success', 'operational', 'pass'], true))->count();
            $latency = $results->whereNotNull('latency_ms')->avg('latency_ms');

            DailyRollup::query()->updateOrCreate(
                ['component_id' => $component->id, 'date' => $localDay->toDateString()],
                [
                    'uptime_percentage' => $uptime,
                    'average_latency_ms' => $latency === null ? null : (int) round($latency),
                    'checks_total' => $results->count(),
                    'checks_failed' => $failedChecks,
                    'maintenance_seconds' => $maintenanceSeconds,
                    'observed_seconds' => $observedSeconds,
                    'available_seconds' => $availableSeconds,
                    'worst_status' => $worst,
                ],
            );
        }

        return self::SUCCESS;
    }

    private function later(CarbonInterface $left, CarbonInterface $right): CarbonImmutable
    {
        return CarbonImmutable::instance($left->greaterThan($right) ? $left : $right);
    }

    private function earlier(CarbonInterface $left, CarbonInterface $right): CarbonImmutable
    {
        return CarbonImmutable::instance($left->lessThan($right) ? $left : $right);
    }

    /** @param array<int, array{0: CarbonInterface, 1: CarbonInterface}> $ranges */
    private function mergeRanges(array $ranges): array
    {
        $ranges = array_values(array_filter($ranges, fn (array $range) => $range[0]->lessThan($range[1])));
        usort($ranges, fn (array $left, array $right) => $left[0]->getTimestamp() <=> $right[0]->getTimestamp());
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            $start = CarbonImmutable::instance($start);
            $end = CarbonImmutable::instance($end);
            $last = array_key_last($merged);
            if ($last !== null && $start->lessThanOrEqualTo($merged[$last][1])) {
                if ($end->greaterThan($merged[$last][1])) {
                    $merged[$last][1] = $end;
                }
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    private function rangeSeconds(array $ranges): int
    {
        return (int) collect($ranges)->sum(fn (array $range) => $range[0]->diffInSeconds($range[1]));
    }

    private function overlapSeconds(CarbonInterface $start, CarbonInterface $end, array $ranges): int
    {
        $seconds = 0;
        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            $overlapStart = $this->later($start, $rangeStart);
            $overlapEnd = $this->earlier($end, $rangeEnd);
            if ($overlapStart->lessThan($overlapEnd)) {
                $seconds += (int) $overlapStart->diffInSeconds($overlapEnd);
            }
        }

        return $seconds;
    }
}
