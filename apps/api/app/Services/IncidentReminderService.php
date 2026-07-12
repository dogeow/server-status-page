<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\NotificationDelivery;
use App\Models\NotificationPolicy;
use App\Models\OutboxEvent;
use Carbon\CarbonImmutable;

class IncidentReminderService
{
    public function schedule(): int
    {
        $created = 0;
        $incidents = Incident::query()->whereNull('resolved_at')->with('components')->get();

        foreach ($incidents as $incident) {
            $componentIds = $incident->components->pluck('id');
            $inMaintenance = MaintenanceWindow::query()
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now())
                ->whereNotIn('status', ['cancelled', 'completed'])
                ->whereHas('components', fn ($query) => $query->whereIn('components.id', $componentIds))
                ->exists();
            if ($inMaintenance || OutboxEvent::query()->where('type', 'incident.reminder')->where('aggregate_type', 'incident')->where('aggregate_id', (string) $incident->id)->whereNull('processed_at')->exists()) {
                continue;
            }

            $due = NotificationPolicy::query()
                ->where('status_page_id', $incident->status_page_id)
                ->where('enabled', true)
                ->where('repeat_minutes', '>', 0)
                ->with('channel')
                ->get()
                ->contains(function (NotificationPolicy $policy) use ($incident, $componentIds): bool {
                    if (! $policy->channel?->enabled || ($policy->component_ids && $componentIds->intersect($policy->component_ids)->isEmpty()) || $this->isQuietTime($policy)) {
                        return false;
                    }
                    $events = $policy->events ?: [];
                    if ($events !== [] && ! collect($events)->contains(fn (string $type) => str_starts_with($type, 'incident.'))) {
                        return false;
                    }
                    $last = NotificationDelivery::query()
                        ->where('notification_channel_id', $policy->notification_channel_id)
                        ->where('aggregate_type', 'incident')
                        ->where('aggregate_id', (string) $incident->id)
                        ->where('status', 'delivered')
                        ->latest('delivered_at')
                        ->value('delivered_at');
                    $reference = $last ? CarbonImmutable::parse($last) : $incident->started_at;

                    return $reference && $reference->diffInMinutes(now(), true) >= $policy->repeat_minutes;
                });
            if (! $due) {
                continue;
            }

            OutboxEvent::query()->create([
                'type' => 'incident.reminder',
                'aggregate_type' => 'incident',
                'aggregate_id' => (string) $incident->id,
                'payload' => [
                    'incident_id' => $incident->id,
                    'status_page_id' => $incident->status_page_id,
                    'title' => $incident->title,
                    'status' => $incident->status,
                    'severity' => $incident->impact,
                    'component_ids' => $componentIds->all(),
                    'occurred_at' => now()->toIso8601String(),
                ],
                'available_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }

    private function isQuietTime(NotificationPolicy $policy): bool
    {
        $quiet = $policy->quiet_hours;
        if (! is_array($quiet) || empty($quiet['start']) || empty($quiet['end'])) {
            return false;
        }
        $now = now((string) ($quiet['timezone'] ?? 'Asia/Shanghai'));
        $minutes = $now->hour * 60 + $now->minute;
        [$startHour, $startMinute] = array_pad(array_map('intval', explode(':', (string) $quiet['start'], 2)), 2, 0);
        [$endHour, $endMinute] = array_pad(array_map('intval', explode(':', (string) $quiet['end'], 2)), 2, 0);
        $start = $startHour * 60 + $startMinute;
        $end = $endHour * 60 + $endMinute;

        return $start !== $end && ($start < $end
            ? $minutes >= $start && $minutes < $end
            : $minutes >= $start || $minutes < $end);
    }
}
