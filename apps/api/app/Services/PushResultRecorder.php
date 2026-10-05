<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Monitor;
use Carbon\CarbonInterface;

class PushResultRecorder
{
    public const PUSH_AGENT_ID = '00000000-0000-4000-8000-000000000002';

    public function __construct(private readonly CheckIngestor $ingestor) {}

    public function record(Monitor $monitor, string $status, CarbonInterface $scheduledAt, ?int $latencyMs = null, ?string $errorCode = null, array $metrics = []): bool
    {
        $agent = Agent::query()->firstOrCreate(
            ['id' => self::PUSH_AGENT_ID],
            ['name' => 'signed-push-gateway', 'status' => 'online', 'capabilities' => ['heartbeat', 'laravel_probe']],
        );
        $agent->update(['status' => 'online', 'last_seen_at' => now()]);
        $outcome = $this->ingestor->ingest(
            $monitor,
            $agent->id,
            $scheduledAt,
            (string) $monitor->config_version,
            $status,
            $latencyMs,
            $errorCode,
            null,
            $metrics === [] ? null : $metrics,
        );

        return $outcome['outcome'] === 'accepted' || $outcome['outcome'] === 'skipped';
    }
}
