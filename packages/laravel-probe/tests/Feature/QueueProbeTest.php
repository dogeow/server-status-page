<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Tests\Feature;

use DateTimeInterface;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use StatusPage\LaravelProbe\Contracts\PushClient;
use StatusPage\LaravelProbe\Jobs\StatusProbeJob;
use StatusPage\LaravelProbe\StatusProbeManager;
use StatusPage\LaravelProbe\Tests\TestCase;

final class QueueProbeTest extends TestCase
{
    #[Test]
    public function it_dispatches_one_single_try_canary_to_each_real_connection_and_queue_pair(): void
    {
        Queue::fake();
        config()->set('status-probe.queues', [
            'mail' => ['connection' => 'sync', 'queue' => 'mail-critical'],
            'reports' => ['connection' => 'sync', 'queue' => 'reports-low'],
        ]);

        $probeIds = app(StatusProbeManager::class)->dispatchQueueProbes();

        self::assertCount(2, $probeIds);
        Queue::assertPushed(StatusProbeJob::class, 2);
        Queue::assertPushedOn('mail-critical', static function (StatusProbeJob $job): bool {
            return $job->target === 'mail' && $job->connection === 'sync' && $job->tries === 1;
        });
        Queue::assertPushedOn('reports-low', static function (StatusProbeJob $job): bool {
            return $job->target === 'reports' && $job->connection === 'sync' && $job->tries === 1;
        });

        self::assertCount(2, $this->pushClient->eventsNamed('queue.enqueued'));
    }

    #[Test]
    public function the_canary_reports_started_and_completed_without_business_side_effects(): void
    {
        $job = new StatusProbeJob(
            '8f802a15-43ad-4a68-a7ab-d710ed4e30b8',
            'default',
            now()->subSecond()->toISOString(),
        );

        $job->handle($this->pushClient);

        self::assertSame(1, $job->tries);
        self::assertCount(1, $this->pushClient->eventsNamed('queue.started'));
        self::assertCount(1, $this->pushClient->eventsNamed('queue.completed'));
        self::assertSame('default', $this->pushClient->eventsNamed('queue.completed')[0]['payload']['target']);
    }

    #[Test]
    public function telemetry_failure_does_not_fail_a_canary_or_the_failed_callback(): void
    {
        $this->pushClient->result = false;
        $job = new StatusProbeJob(
            '8f802a15-43ad-4a68-a7ab-d710ed4e30b8',
            'default',
            now()->toISOString(),
        );

        $job->handle($this->pushClient);
        $job->failed(new RuntimeException('must never be logged or rethrown'));

        self::assertCount(1, $this->pushClient->eventsNamed('queue.failed'));
    }

    #[Test]
    public function even_an_unexpected_client_exception_cannot_fail_the_canary(): void
    {
        $throwingClient = new class implements PushClient
        {
            public int $calls = 0;

            public function send(string $event, array $payload = [], ?DateTimeInterface $occurredAt = null): bool
            {
                $this->calls++;

                throw new RuntimeException('transport details');
            }
        };
        $job = new StatusProbeJob(
            '8f802a15-43ad-4a68-a7ab-d710ed4e30b8',
            'default',
            now()->toISOString(),
        );

        $job->handle($throwingClient);

        self::assertSame(2, $throwingClient->calls);
    }
}
