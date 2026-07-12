<?php

namespace App\Services;

use App\Events\PublicStatusChanged;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\NotificationPolicy;
use App\Models\OutboxEvent;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class OutboxProcessor
{
    public function process(int $limit = 50): int
    {
        $claimToken = (string) Str::uuid();
        $events = DB::transaction(function () use ($limit, $claimToken) {
            $query = OutboxEvent::query()
                ->whereNull('processed_at')
                ->where('available_at', '<=', now())
                ->where(fn ($nested) => $nested->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(5)))
                ->orderBy('created_at')
                ->limit($limit);
            $query = DB::connection()->getDriverName() === 'pgsql'
                ? $query->lock('FOR UPDATE SKIP LOCKED')
                : $query->lockForUpdate();
            $ids = $query->pluck('id');
            if ($ids->isEmpty()) {
                return collect();
            }
            OutboxEvent::query()->whereIn('id', $ids)->update(['claimed_at' => now(), 'claim_token' => $claimToken]);

            return OutboxEvent::query()->where('claim_token', $claimToken)->orderBy('created_at')->get();
        });

        foreach ($events as $event) {
            try {
                $this->deliver($event);
                $event->update(['processed_at' => now(), 'last_error' => null, 'claimed_at' => null, 'claim_token' => null]);
            } catch (Throwable $exception) {
                report($exception);
                $attempts = $event->attempts + 1;
                $event->update([
                    'attempts' => $attempts,
                    'last_error' => Str::limit($exception->getMessage(), 2000, ''),
                    'available_at' => now()->addSeconds(min(3600, 2 ** min(10, $attempts))),
                    'processed_at' => $attempts >= 10 ? now() : null,
                    'claimed_at' => null,
                    'claim_token' => null,
                ]);
            }
        }

        return $events->count();
    }

    private function deliver(OutboxEvent $event): void
    {
        if ($event->type === 'subscriber.confirmation_requested') {
            $this->sendMail(
                (string) $event->payload['email'],
                (string) $event->payload['subject'],
                "请打开以下链接确认状态通知订阅：\n\n".$event->payload['confirmation_url']."\n\n如非本人操作，可忽略本邮件。退订：\n".$event->payload['unsubscribe_url'],
            );

            return;
        }

        $incident = $event->aggregate_type === 'incident' ? Incident::query()->with('components')->find($event->aggregate_id) : null;
        $pageId = $incident?->status_page_id ?: ($event->payload['status_page_id'] ?? null);
        if (! $pageId) {
            return;
        }

        if (str_starts_with($event->type, 'incident.') || str_starts_with($event->type, 'maintenance.') || in_array($event->type, ['component.status_changed', 'agent.offline'], true)) {
            $this->broadcastBestEffort((int) $pageId, $event);
        }
        if ($event->type === 'component.status_changed') {
            return;
        }

        $policies = NotificationPolicy::query()->where('status_page_id', $pageId)->where('enabled', true)->with('channel')->get();
        $componentIds = $incident?->components->pluck('id')
            ?: collect($event->payload['component_ids'] ?? array_filter([$event->payload['component_id'] ?? null]));
        foreach ($policies as $policy) {
            if (! $policy->channel?->enabled || ! $this->policyAllows($policy, $event)) {
                continue;
            }
            if ($policy->component_ids && $componentIds->intersect($policy->component_ids)->isEmpty()) {
                continue;
            }
            $this->deliverChannel($event, $policy->channel);
        }

        if (in_array($event->type, ['incident.created', 'incident.updated', 'incident.resolved', 'maintenance.scheduled', 'maintenance.updated'], true)) {
            $this->deliverSubscribers($event, (int) $pageId, $componentIds, $incident?->title ?: ($event->payload['title'] ?? '计划维护更新'));
        }
    }

    private function deliverChannel(OutboxEvent $event, NotificationChannel $channel): void
    {
        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['outbox_event_id' => $event->id, 'notification_channel_id' => $channel->id],
            [
                'id' => (string) Str::uuid(),
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => $event->aggregate_id,
                'event_type' => $event->type,
                'payload' => $event->payload,
                'status' => 'pending',
            ],
        );
        if ($delivery->status === 'delivered') {
            return;
        }

        try {
            if ($channel->type === 'email') {
                $recipients = ArrWrap::strings($channel->config['to'] ?? []);
                foreach ($recipients as $recipient) {
                    $this->sendMail($recipient, (string) ($event->payload['title'] ?? '状态更新'), $this->message($event));
                }
            } elseif ($channel->type === 'webhook') {
                $body = json_encode($this->webhookPayload($event, $delivery->id), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $secret = (string) ($channel->config['secret'] ?? '');
                $response = Http::timeout((int) config('status.webhook_timeout_seconds', 10))
                    ->withHeaders([
                        'X-Status-Delivery' => $delivery->id,
                        'X-Status-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
                    ])->withBody($body, 'application/json')
                    ->send('POST', (string) $channel->config['url']);
                $response->throw();
            }
            $delivery->update(['status' => 'delivered', 'delivered_at' => now(), 'attempts' => $delivery->attempts + 1, 'last_error' => null]);
        } catch (Throwable $exception) {
            $delivery->update(['status' => 'failed', 'attempts' => $delivery->attempts + 1, 'last_error' => Str::limit($exception->getMessage(), 2000, ''), 'next_attempt_at' => now()->addMinutes(1)]);
            throw $exception;
        }
    }

    private function deliverSubscribers(OutboxEvent $event, int $pageId, $componentIds, string $subject): void
    {
        $subscribers = Subscriber::query()
            ->where('status_page_id', $pageId)
            ->whereNotNull('confirmed_at')
            ->whereNull('unsubscribed_at')
            ->with('components:id')
            ->get();

        foreach ($subscribers as $subscriber) {
            if ($subscriber->components->isNotEmpty() && $subscriber->components->pluck('id')->intersect($componentIds)->isEmpty()) {
                continue;
            }
            $delivery = NotificationDelivery::query()->firstOrCreate(
                ['outbox_event_id' => $event->id, 'notification_channel_id' => null, 'recipient' => $subscriber->email],
                [
                    'id' => (string) Str::uuid(),
                    'aggregate_type' => $event->aggregate_type,
                    'aggregate_id' => $event->aggregate_id,
                    'event_type' => $event->type,
                    'payload' => $event->payload,
                    'status' => 'pending',
                ],
            );
            if ($delivery->status !== 'delivered') {
                $this->sendMail($subscriber->email, $subject, $this->message($event));
                $delivery->update(['status' => 'delivered', 'delivered_at' => now(), 'attempts' => $delivery->attempts + 1]);
            }
        }
    }

    private function sendMail(string $recipient, string $subject, string $message): void
    {
        Mail::raw($message, fn ($mail) => $mail->to($recipient)->subject($subject));
    }

    private function broadcastBestEffort(int $pageId, OutboxEvent $event): void
    {
        $publicPayload = array_filter([
            'status_page_id' => $pageId,
            'component_id' => $event->payload['component_id'] ?? null,
            'component_ids' => $event->payload['component_ids'] ?? null,
            'incident_id' => $event->payload['incident_id'] ?? null,
            'maintenance_id' => $event->payload['maintenance_id'] ?? null,
            'severity' => $event->payload['severity'] ?? null,
            'status' => $event->payload['status'] ?? null,
            'from' => $event->payload['from'] ?? null,
            'to' => $event->payload['to'] ?? null,
            'occurred_at' => $event->payload['occurred_at'] ?? $event->payload['at'] ?? now()->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null);

        try {
            event(new PublicStatusChanged($pageId, $event->type, $publicPayload));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function webhookPayload(OutboxEvent $event, string $deliveryId): array
    {
        $components = collect($event->payload['components'] ?? []);
        if ($components->isEmpty() && $event->aggregate_type === 'incident') {
            $components = Incident::query()->with('components:id,name,slug')->find($event->aggregate_id)?->components ?? collect();
        } elseif ($components->isEmpty() && $event->aggregate_type === 'maintenance_window') {
            $components = MaintenanceWindow::query()->with('components:id,name,slug')->find($event->aggregate_id)?->components ?? collect();
        }

        return [
            ...$event->payload,
            'delivery_id' => $deliveryId,
            'event_type' => $event->type,
            'severity' => $event->payload['severity'] ?? null,
            'components' => $components->map(fn ($component) => is_array($component)
                ? $component
                : ['id' => $component->id, 'name' => $component->name, 'slug' => $component->slug])->values()->all(),
            'summary' => $event->payload['summary'] ?? $event->payload['title'] ?? '系统状态更新',
            'occurred_at' => $event->payload['occurred_at'] ?? $event->created_at->toIso8601String(),
        ];
    }

    private function policyAllows(NotificationPolicy $policy, OutboxEvent $event): bool
    {
        $events = $policy->events ?: [];
        $subscribed = $events === [] || in_array($event->type, $events, true);
        if (in_array($event->type, ['incident.reminder', 'incident.resolved'], true)) {
            $subscribed = $subscribed || collect($events)->contains(fn (string $type) => str_starts_with($type, 'incident.'));
        }
        if (! $subscribed) {
            return false;
        }

        // Recovery is always delivered, even during quiet hours.
        if ($event->type === 'incident.resolved') {
            return true;
        }
        if ($this->isQuietTime($policy)) {
            return false;
        }
        if ($event->type === 'incident.reminder') {
            if ($policy->repeat_minutes <= 0) {
                return false;
            }
            $lastDelivered = NotificationDelivery::query()
                ->where('notification_channel_id', $policy->notification_channel_id)
                ->where('aggregate_type', 'incident')
                ->where('aggregate_id', $event->aggregate_id)
                ->where('status', 'delivered')
                ->latest('delivered_at')
                ->value('delivered_at');
            if ($lastDelivered && CarbonImmutable::parse($lastDelivered)->diffInMinutes(now(), true) < $policy->repeat_minutes) {
                return false;
            }
        }

        return true;
    }

    private function isQuietTime(NotificationPolicy $policy): bool
    {
        $quiet = $policy->quiet_hours;
        if (! is_array($quiet) || empty($quiet['start']) || empty($quiet['end'])) {
            return false;
        }
        $timezone = (string) ($quiet['timezone'] ?? 'Asia/Shanghai');
        $now = now($timezone);
        $minutes = $now->hour * 60 + $now->minute;
        [$startHour, $startMinute] = array_pad(array_map('intval', explode(':', (string) $quiet['start'], 2)), 2, 0);
        [$endHour, $endMinute] = array_pad(array_map('intval', explode(':', (string) $quiet['end'], 2)), 2, 0);
        $start = $startHour * 60 + $startMinute;
        $end = $endHour * 60 + $endMinute;

        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? $minutes >= $start && $minutes < $end
            : $minutes >= $start || $minutes < $end;
    }

    private function message(OutboxEvent $event): string
    {
        return implode("\n", array_filter([
            $event->payload['title'] ?? '系统状态更新',
            '事件：'.$event->type,
            isset($event->payload['severity']) ? '级别：'.$event->payload['severity'] : null,
            isset($event->payload['occurred_at']) ? '时间：'.$event->payload['occurred_at'] : null,
        ]));
    }
}

final class ArrWrap
{
    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        return array_values(array_filter(array_map('strval', is_array($value) ? $value : [$value])));
    }
}
