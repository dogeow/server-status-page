<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Jobs;

use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use StatusPage\LaravelProbe\Contracts\PushClient;
use Throwable;

final class StatusProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 10;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $probeId,
        public readonly string $target,
        public readonly string $enqueuedAt,
    ) {}

    public function handle(PushClient $client): void
    {
        $startedAt = new DateTimeImmutable;
        $startedNs = hrtime(true);

        $this->send($client, 'queue.started', [
            'probe_id' => $this->probeId,
            'target' => $this->target,
            'enqueued_at' => $this->enqueuedAt,
            'started_at' => $startedAt->format(DATE_RFC3339_EXTENDED),
            'queue_wait_ms' => $this->queueWaitMilliseconds($startedAt),
        ], $startedAt);

        // The canary deliberately performs no application, database, or cache
        // work. Being dequeued and reaching this point is the probe.
        $completedAt = new DateTimeImmutable;
        $this->send($client, 'queue.completed', [
            'probe_id' => $this->probeId,
            'target' => $this->target,
            'enqueued_at' => $this->enqueuedAt,
            'started_at' => $startedAt->format(DATE_RFC3339_EXTENDED),
            'completed_at' => $completedAt->format(DATE_RFC3339_EXTENDED),
            'duration_ms' => round((hrtime(true) - $startedNs) / 1_000_000, 3),
        ], $completedAt);
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(PushClient::class)->send('queue.failed', [
                'probe_id' => $this->probeId,
                'target' => $this->target,
                'code' => 'queue_probe_failed',
            ]);
        } catch (Throwable) {
            // The integration is fail-open even when Laravel invokes failed().
        }
    }

    private function queueWaitMilliseconds(DateTimeImmutable $startedAt): ?float
    {
        try {
            $enqueuedAt = new DateTimeImmutable($this->enqueuedAt);

            return round(max(0.0, (float) $startedAt->format('U.u') - (float) $enqueuedAt->format('U.u')) * 1000, 3);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(PushClient $client, string $event, array $payload, DateTimeImmutable $occurredAt): void
    {
        try {
            $client->send($event, $payload, $occurredAt);
        } catch (Throwable) {
            // A third-party client binding must not be able to fail this canary.
        }
    }
}
