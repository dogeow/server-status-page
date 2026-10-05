<?php

namespace App\Services;

use App\Models\CheckResult;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckIngestor
{
    public function __construct(private readonly StateEvaluator $evaluator) {}

    /**
     * Insert a sample and evaluate it in one transaction.
     *
     * A future sample beyond the skew window is rejected before insert, so the
     * idempotency key stays free for a corrected retry. A duplicate is stored
     * once and is not evaluated again. A stale config version is stored and
     * skipped. An evaluation exception rolls the inserted row back.
     *
     * @param  array<string, mixed>|null  $metrics
     * @return array{outcome: 'accepted'|'duplicate'|'skipped'|'rejected', code?: string}
     */
    public function ingest(
        Monitor $monitor,
        string $agentId,
        CarbonInterface $scheduledAt,
        string $configVersion,
        string $status,
        ?int $latencyMs = null,
        ?string $errorCode = null,
        ?string $message = null,
        ?array $metrics = null,
    ): array {
        $scheduledAt = CarbonImmutable::parse($scheduledAt)->utc();
        if ($this->tooFarInTheFuture($scheduledAt)) {
            return ['outcome' => 'rejected', 'code' => 'scheduled_at_out_of_range'];
        }

        return DB::transaction(function () use ($monitor, $agentId, $scheduledAt, $configVersion, $status, $latencyMs, $errorCode, $message, $metrics): array {
            $inserted = DB::table('check_results')->insertOrIgnore([
                'monitor_id' => $monitor->id,
                'agent_id' => $agentId,
                'scheduled_at' => $scheduledAt,
                'config_version' => $configVersion,
                'status' => $status,
                'latency_ms' => $latencyMs,
                'error_code' => $errorCode,
                'message' => $message !== null ? Str::limit($message, 2000, '') : null,
                'metrics' => $metrics === null ? null : json_encode($metrics, JSON_THROW_ON_ERROR),
                'received_at' => now(),
            ]);

            if (! $inserted) {
                return ['outcome' => 'duplicate'];
            }

            if ($configVersion !== (string) $monitor->config_version) {
                return ['outcome' => 'skipped'];
            }

            $result = CheckResult::query()
                ->where('monitor_id', $monitor->id)
                ->where('agent_id', $agentId)
                ->where('scheduled_at', $scheduledAt)
                ->where('config_version', $configVersion)
                ->firstOrFail();
            $this->evaluator->evaluate($result);

            return ['outcome' => 'accepted'];
        });
    }

    public function tooFarInTheFuture(CarbonInterface $scheduledAt): bool
    {
        $skew = max(0, (int) config('status.sample_future_skew_seconds', config('status.agent_signature_ttl', 300)));

        return CarbonImmutable::parse($scheduledAt)->utc()->greaterThan(now()->addSeconds($skew));
    }
}
