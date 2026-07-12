<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Support;

final class TelemetryPayload
{
    /**
     * Keep optional metadata useful while preventing accidental credential or
     * connection-string exfiltration through the monitoring integration.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, bool|float|int|string|null>
     */
    public static function sanitize(array $metadata): array
    {
        $safe = [];

        foreach (array_slice($metadata, 0, 20, true) as $key => $value) {
            if (! is_string($key) || preg_match('/password|passwd|secret|token|authorization|cookie|dsn|credential/i', $key)) {
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $safeKey = SafeIdentifier::make($key, 'meta');
            $safe[$safeKey] = is_string($value) ? substr($value, 0, 256) : $value;
        }

        return $safe;
    }
}
