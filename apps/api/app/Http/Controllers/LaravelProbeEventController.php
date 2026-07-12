<?php

namespace App\Http\Controllers;

use App\Models\LaravelIntegration;
use App\Models\Monitor;
use App\Models\QueueProbeRun;
use App\Models\UsedNonce;
use App\Services\PushResultRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaravelProbeEventController extends Controller
{
    public function __invoke(Request $request, LaravelIntegration $integration, PushResultRecorder $recorder): JsonResponse
    {
        if (! $integration->enabled || ! $this->authenticate($request, $integration)) {
            return response()->json(['message' => 'Probe authentication failed.', 'code' => 'invalid_probe_signature'], 401);
        }

        try {
            UsedNonce::query()->create([
                'scope' => 'laravel-integration:'.$integration->id,
                'nonce' => (string) $request->header('X-Status-Probe-Nonce'),
                'expires_at' => now()->addSeconds(config('status.agent_signature_ttl', 300) * 2),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Nonce already used.', 'code' => 'replayed_nonce'], 409);
        }

        $data = $request->validate([
            'event' => ['required', 'string', 'max:100'],
            'occurred_at' => ['required', 'date'],
            'application' => ['required', 'array'],
            'application.id' => ['required', 'string', 'max:100'],
            'application.environment' => ['nullable', 'string', 'max:100'],
            'application.instance_id' => ['nullable', 'string', 'max:255'],
            'payload' => ['present', 'array'],
        ]);
        if ($data['application']['id'] !== $integration->application_id) {
            return response()->json(['message' => 'Application id does not match this integration.', 'code' => 'application_mismatch'], 422);
        }

        $supported = ['queue.enqueued', 'queue.started', 'queue.completed', 'queue.failed', 'queue.dispatch_failed', 'scheduler.tick', 'scheduler.task_succeeded', 'scheduler.task_failed'];
        if (! in_array($data['event'], $supported, true)) {
            return response()->json(['accepted' => true, 'routed' => 0, 'ignored' => true], 202);
        }

        $occurredAt = CarbonImmutable::parse($data['occurred_at'])->utc();
        $monitors = $this->monitors($integration, $data['event'], $data['payload']);
        $routed = 0;
        foreach ($monitors as $monitor) {
            $this->routeEvent($integration, $monitor, $data['event'], $occurredAt, $data['payload'], $recorder);
            $routed++;
        }
        $integration->update(['last_seen_at' => now()]);

        return response()->json(['accepted' => true, 'routed' => $routed], 202);
    }

    private function authenticate(Request $request, LaravelIntegration $integration): bool
    {
        $timestamp = (string) $request->header('X-Status-Probe-Timestamp');
        $nonce = (string) $request->header('X-Status-Probe-Nonce');
        $bodyHash = strtolower((string) $request->header('X-Status-Probe-Content-SHA256'));
        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > config('status.agent_signature_ttl', 300) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce) || ! preg_match('/^[a-f0-9]{64}$/', $bodyHash)) {
            return false;
        }
        if (! hash_equals(hash('sha256', $request->getContent()), $bodyHash)) {
            return false;
        }
        $canonical = "STATUS-PROBE-HMAC-SHA256-V1\n{$timestamp}\n{$nonce}\n{$bodyHash}";
        $provided = array_filter([
            strtolower((string) $request->header('X-Status-Probe-Signature')),
            strtolower((string) $request->header('X-Status-Probe-Signature-Next')),
        ]);
        foreach (array_filter([$integration->secret_current, $integration->secret_next]) as $secret) {
            $expected = 'sha256='.hash_hmac('sha256', $canonical, $secret);
            foreach ($provided as $signature) {
                if (preg_match('/^sha256=[a-f0-9]{64}$/', $signature) && hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function monitors(LaravelIntegration $integration, string $event, array $payload)
    {
        $queueEvent = str_starts_with($event, 'queue.');
        $type = $queueEvent ? 'laravel_queue' : 'laravel_scheduler';
        $target = $queueEvent ? ($payload['target'] ?? null) : ($event === 'scheduler.tick' ? 'tick' : ($payload['task'] ?? null));

        return Monitor::query()
            ->where('type', $type)
            ->where('enabled', true)
            ->whereHas('component.group', fn ($query) => $query->where('status_page_id', $integration->status_page_id))
            ->get()
            ->filter(function (Monitor $monitor) use ($integration, $target, $event): bool {
                $config = $monitor->config ?: [];
                $integrationMatches = ($config['integration_id'] ?? null) === $integration->id
                    || ($config['application_id'] ?? null) === $integration->application_id;
                if (! $integrationMatches) {
                    return false;
                }
                $configuredTarget = $config['target'] ?? ($monitor->type === 'laravel_scheduler' ? 'tick' : null);

                return $configuredTarget === $target || ($event === 'scheduler.tick' && in_array($configuredTarget, [null, '', 'tick'], true));
            });
    }

    private function routeEvent(LaravelIntegration $integration, Monitor $monitor, string $event, CarbonImmutable $occurredAt, array $payload, PushResultRecorder $recorder): void
    {
        $probeId = $payload['probe_id'] ?? null;
        if (str_starts_with($event, 'queue.') && is_string($probeId)) {
            $monitor->update(['last_event_at' => $occurredAt]);
            $run = QueueProbeRun::query()->firstOrCreate(
                ['laravel_integration_id' => $integration->id, 'monitor_id' => $monitor->id, 'probe_id' => $probeId],
                ['target' => (string) ($payload['target'] ?? 'default'), 'enqueued_at' => CarbonImmutable::parse($payload['enqueued_at'] ?? $occurredAt)],
            );
            if ($event === 'queue.started') {
                $run->update(['started_at' => CarbonImmutable::parse($payload['started_at'] ?? $occurredAt)]);
            } elseif ($event === 'queue.completed') {
                $run->update(['started_at' => CarbonImmutable::parse($payload['started_at'] ?? $run->started_at ?? $occurredAt), 'completed_at' => $occurredAt, 'status' => 'completed']);
                $latency = (int) max(0, $run->enqueued_at->diffInMilliseconds($occurredAt));
                $recorder->record($monitor, 'ok', $occurredAt, $latency, null, ['event' => $event, 'target' => $run->target]);
            } elseif (in_array($event, ['queue.failed', 'queue.dispatch_failed'], true)) {
                $run->update(['completed_at' => $occurredAt, 'status' => 'failed']);
                $recorder->record($monitor, 'failed', $occurredAt, null, (string) ($payload['code'] ?? 'queue_probe_failed'), ['event' => $event, 'target' => $run->target]);
            }

            return;
        }

        if ($event === 'scheduler.tick' || $event === 'scheduler.task_succeeded') {
            $recorder->record($monitor, 'ok', $occurredAt, isset($payload['metadata']['duration_ms']) ? (int) round($payload['metadata']['duration_ms']) : null, null, ['event' => $event]);
        } elseif ($event === 'scheduler.task_failed') {
            $recorder->record($monitor, 'failed', $occurredAt, isset($payload['metadata']['duration_ms']) ? (int) round($payload['metadata']['duration_ms']) : null, (string) ($payload['code'] ?? 'scheduler_task_failed'), ['event' => $event]);
        }
    }
}
