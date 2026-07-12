<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

final class StatusProbeBroadcast implements ShouldBroadcastNow
{
    public const CHANNEL = 'status-probe.public';

    public const EVENT = 'status-probe.nonce';

    public function __construct(
        public readonly string $nonce,
        public readonly string $sentAt,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel(self::CHANNEL);
    }

    public function broadcastAs(): string
    {
        return self::EVENT;
    }

    /**
     * @return array{nonce: string, sent_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'nonce' => $this->nonce,
            'sent_at' => $this->sentAt,
        ];
    }
}
