<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe;

use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use StatusPage\LaravelProbe\Contracts\PushClient;
use StatusPage\LaravelProbe\Jobs\StatusProbeJob;
use StatusPage\LaravelProbe\Support\SafeIdentifier;
use StatusPage\LaravelProbe\Support\SafeLogger;
use StatusPage\LaravelProbe\Support\TelemetryPayload;
use Throwable;

final class StatusProbeManager
{
    public function __construct(
        private readonly PushClient $client,
        private readonly Dispatcher $bus,
        private readonly SafeLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function push(string $event, array $payload = []): bool
    {
        return $this->send($event, $payload);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function schedulerTick(array $metadata = []): bool
    {
        return $this->send('scheduler.tick', [
            'tick_id' => (string) Str::uuid(),
            'metadata' => TelemetryPayload::sanitize($metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function scheduledTaskSucceeded(string $task, array $metadata = []): bool
    {
        return $this->send('scheduler.task_succeeded', [
            'task' => SafeIdentifier::make($task, 'task'),
            'metadata' => TelemetryPayload::sanitize($metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function scheduledTaskFailed(string $task, string $code = 'task_failed', array $metadata = []): bool
    {
        return $this->send('scheduler.task_failed', [
            'task' => SafeIdentifier::make($task, 'task'),
            'code' => SafeIdentifier::make($code, 'failure'),
            'metadata' => TelemetryPayload::sanitize($metadata),
        ]);
    }

    /**
     * Execute a critical scheduled task and report its terminal state. A task
     * exception is always rethrown so the application's own failure semantics
     * remain unchanged.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  array<string, mixed>  $metadata
     * @return T
     */
    public function trackScheduledTask(string $task, callable $callback, array $metadata = []): mixed
    {
        $startedNs = hrtime(true);

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $this->scheduledTaskFailed($task, 'task_failed', array_merge($metadata, [
                'duration_ms' => round((hrtime(true) - $startedNs) / 1_000_000, 3),
            ]));

            throw $exception;
        }

        $this->scheduledTaskSucceeded($task, array_merge($metadata, [
            'duration_ms' => round((hrtime(true) - $startedNs) / 1_000_000, 3),
        ]));

        return $result;
    }

    /**
     * Dispatch one no-op canary to every configured connection/queue pair.
     *
     * @return list<string> Probe UUIDs successfully handed to Laravel's bus.
     */
    public function dispatchQueueProbes(): array
    {
        if (! (bool) config('status-probe.enabled', true)) {
            return [];
        }

        $probeIds = [];

        foreach ((array) config('status-probe.queues', []) as $id => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $target = is_int($id)
                ? 'queue_'.$id
                : SafeIdentifier::make((string) $id, 'queue');
            $connection = $this->nullableString($definition['connection'] ?? null);
            $queue = $this->nullableString($definition['queue'] ?? null);
            $probeId = (string) Str::uuid();
            $enqueuedAt = new DateTimeImmutable;
            $job = new StatusProbeJob(
                $probeId,
                $target,
                $enqueuedAt->format(DATE_RFC3339_EXTENDED),
            );

            if ($connection !== null) {
                $job->onConnection($connection);
            }

            if ($queue !== null) {
                $job->onQueue($queue);
            }

            try {
                $this->bus->dispatch($job);
                $probeIds[] = $probeId;
            } catch (Throwable $exception) {
                $this->logger->warning('queue_dispatch_failed', [
                    'target' => $target,
                    'exception' => $exception::class,
                ]);
                $this->send('queue.dispatch_failed', [
                    'probe_id' => $probeId,
                    'target' => $target,
                    'code' => 'queue_dispatch_failed',
                ]);

                continue;
            }

            $this->send('queue.enqueued', [
                'probe_id' => $probeId,
                'target' => $target,
                'enqueued_at' => $enqueuedAt->format(DATE_RFC3339_EXTENDED),
            ], $enqueuedAt);
        }

        return $probeIds;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $event, array $payload = [], ?DateTimeImmutable $occurredAt = null): bool
    {
        try {
            return $this->client->send($event, $payload, $occurredAt);
        } catch (Throwable $exception) {
            $this->logger->warning('push_client_failed', [
                'event' => SafeIdentifier::make($event, 'event'),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
