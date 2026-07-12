<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Contracts;

use DateTimeInterface;

interface PushClient
{
    /**
     * Send a telemetry event without allowing transport failures to escape.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $event, array $payload = [], ?DateTimeInterface $occurredAt = null): bool;
}
