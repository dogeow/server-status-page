<?php

declare(strict_types=1);

use StatusPage\LaravelProbe\StatusProbeManager;

if (! function_exists('status_probe')) {
    function status_probe(): StatusProbeManager
    {
        return app(StatusProbeManager::class);
    }
}

if (! function_exists('status_probe_task')) {
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  array<string, mixed>  $metadata
     * @return T
     */
    function status_probe_task(string $task, callable $callback, array $metadata = []): mixed
    {
        return status_probe()->trackScheduledTask($task, $callback, $metadata);
    }
}
