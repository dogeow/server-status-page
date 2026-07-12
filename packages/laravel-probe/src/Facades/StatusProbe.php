<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Facades;

use Illuminate\Support\Facades\Facade;
use StatusPage\LaravelProbe\StatusProbeManager;

/**
 * @method static bool push(string $event, array<string, mixed> $payload = [])
 * @method static bool schedulerTick(array<string, mixed> $metadata = [])
 * @method static bool scheduledTaskSucceeded(string $task, array<string, mixed> $metadata = [])
 * @method static bool scheduledTaskFailed(string $task, string $code = 'task_failed', array<string, mixed> $metadata = [])
 * @method static mixed trackScheduledTask(string $task, callable $callback, array<string, mixed> $metadata = [])
 * @method static list<string> dispatchQueueProbes()
 *
 * @see StatusProbeManager
 */
final class StatusProbe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'status-probe';
    }
}
