<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\CheckResult;
use App\Models\Monitor;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PushResultRecorder
{
    public const PUSH_AGENT_ID = '00000000-0000-4000-8000-000000000002';

    public function __construct(private readonly StateEvaluator $evaluator) {}

    public function record(Monitor $monitor, string $status, CarbonInterface $scheduledAt, ?int $latencyMs = null, ?string $errorCode = null, array $metrics = []): bool
    {
        $agent = Agent::query()->firstOrCreate(
            ['id' => self::PUSH_AGENT_ID],
            ['name' => 'signed-push-gateway', 'status' => 'online', 'capabilities' => ['heartbeat', 'laravel_probe']],
        );
        $agent->update(['status' => 'online', 'last_seen_at' => now()]);
        $inserted = DB::table('check_results')->insertOrIgnore([
            'monitor_id' => $monitor->id,
            'agent_id' => $agent->id,
            'scheduled_at' => $scheduledAt->utc(),
            'config_version' => $monitor->config_version,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'error_code' => $errorCode,
            'message' => null,
            'metrics' => $metrics === [] ? null : json_encode($metrics, JSON_THROW_ON_ERROR),
            'received_at' => now(),
        ]);

        if ($inserted) {
            $result = CheckResult::query()
                ->where('monitor_id', $monitor->id)
                ->where('agent_id', $agent->id)
                ->where('scheduled_at', $scheduledAt->utc())
                ->where('config_version', $monitor->config_version)
                ->firstOrFail();
            $this->evaluator->evaluate($result);
        }

        return (bool) $inserted;
    }
}
