<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

final class SafeLogger
{
    /**
     * @param  array<string, bool|float|int|string|null>  $context
     */
    public function warning(string $code, array $context = []): void
    {
        if (! (bool) config('status-probe.logging.enabled', true)) {
            return;
        }

        $safeContext = ['code' => SafeIdentifier::make($code, 'log')];

        foreach ($context as $key => $value) {
            if (! in_array($key, ['event', 'target', 'status', 'exception'], true)) {
                continue;
            }

            $safeContext[$key] = is_string($value)
                ? SafeIdentifier::make($value, $key)
                : $value;
        }

        try {
            $channel = config('status-probe.logging.channel');
            $logger = is_string($channel) && $channel !== ''
                ? Log::channel($channel)
                : Log::getFacadeRoot();

            $logger?->warning('status-probe telemetry unavailable', $safeContext);
        } catch (Throwable) {
            // Monitoring must never turn a logging outage into a business outage.
        }
    }
}
