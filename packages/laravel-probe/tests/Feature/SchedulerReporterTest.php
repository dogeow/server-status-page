<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Tests\Feature;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use StatusPage\LaravelProbe\Contracts\PushClient;
use StatusPage\LaravelProbe\StatusProbeManager;
use StatusPage\LaravelProbe\Support\SafeLogger;
use StatusPage\LaravelProbe\Tests\TestCase;

final class SchedulerReporterTest extends TestCase
{
    #[Test]
    public function it_registers_minute_heartbeat_and_queue_canary_schedules(): void
    {
        $events = app(Schedule::class)->events();
        $descriptions = array_map(static fn ($event): ?string => $event->description, $events);

        self::assertContains('status-probe:scheduler-heartbeat', $descriptions);
        self::assertContains('status-probe:queue-canaries', $descriptions);

        foreach ($events as $event) {
            if (in_array($event->description, [
                'status-probe:scheduler-heartbeat',
                'status-probe:queue-canaries',
            ], true)) {
                self::assertSame('* * * * *', $event->expression);
            }
        }
    }

    #[Test]
    public function it_reports_tick_and_critical_task_success(): void
    {
        $manager = app(StatusProbeManager::class);

        self::assertTrue($manager->schedulerTick(['region' => 'cn']));
        $result = $manager->trackScheduledTask('billing.close', static fn (): int => 42, [
            'batch' => 9,
            'api_token' => 'must-be-dropped',
        ]);

        self::assertSame(42, $result);
        self::assertCount(1, $this->pushClient->eventsNamed('scheduler.tick'));
        $success = $this->pushClient->eventsNamed('scheduler.task_succeeded')[0];
        self::assertSame('billing.close', $success['payload']['task']);
        self::assertArrayNotHasKey('api_token', $success['payload']['metadata']);
    }

    #[Test]
    public function it_reports_a_failure_and_preserves_the_tasks_exception_semantics(): void
    {
        $manager = app(StatusProbeManager::class);

        try {
            $manager->trackScheduledTask('billing.close', static function (): never {
                throw new RuntimeException('private application details');
            });
            self::fail('The task exception should have been rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('private application details', $exception->getMessage());
        }

        $failure = $this->pushClient->eventsNamed('scheduler.task_failed')[0];
        self::assertSame('task_failed', $failure['payload']['code']);
        self::assertStringNotContainsString('private application details', json_encode($failure, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_throwing_push_binding_cannot_change_a_successful_tasks_result(): void
    {
        $throwingClient = new class implements PushClient
        {
            public function send(string $event, array $payload = [], ?DateTimeInterface $occurredAt = null): bool
            {
                throw new RuntimeException('transport details');
            }
        };
        $manager = new StatusProbeManager(
            $throwingClient,
            app(Dispatcher::class),
            app(SafeLogger::class),
        );

        self::assertSame(42, $manager->trackScheduledTask('billing.close', static fn (): int => 42));
    }
}
