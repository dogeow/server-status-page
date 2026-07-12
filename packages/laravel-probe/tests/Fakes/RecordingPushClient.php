<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Tests\Fakes;

use DateTimeInterface;
use StatusPage\LaravelProbe\Contracts\PushClient;

final class RecordingPushClient implements PushClient
{
    /** @var list<array{event: string, payload: array<string, mixed>, occurred_at: DateTimeInterface|null}> */
    public array $events = [];

    public bool $result = true;

    public function send(string $event, array $payload = [], ?DateTimeInterface $occurredAt = null): bool
    {
        $this->events[] = [
            'event' => $event,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
        ];

        return $this->result;
    }

    /**
     * @return list<array{event: string, payload: array<string, mixed>, occurred_at: DateTimeInterface|null}>
     */
    public function eventsNamed(string $event): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (array $record): bool => $record['event'] === $event,
        ));
    }
}
