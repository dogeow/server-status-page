<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Models\UsedNonce;
use App\Services\StateEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProbeHeartbeatController extends Controller
{
    private const PUSH_AGENT_ID = '00000000-0000-4000-8000-000000000002';

    public function __invoke(Request $request, Monitor $monitor, StateEvaluator $evaluator): JsonResponse
    {
        abort_unless($monitor->enabled && $monitor->type === 'heartbeat', 404);

        $timestamp = (string) $request->header('X-Timestamp');
        $nonce = (string) $request->header('X-Nonce');
        $signature = strtolower((string) $request->header('X-Signature'));
        $secret = (string) ($monitor->secret_config['heartbeat_secret'] ?? '');
        if ($secret === '' || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > config('status.agent_signature_ttl', 300) || strlen($nonce) < 8 || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return response()->json(['message' => 'Heartbeat authentication failed.', 'code' => 'invalid_heartbeat_signature'], 401);
        }
        $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n".hash('sha256', $request->getContent()), $secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Heartbeat authentication failed.', 'code' => 'invalid_heartbeat_signature'], 401);
        }
        try {
            UsedNonce::query()->create([
                'scope' => 'monitor:'.$monitor->id,
                'monitor_id' => $monitor->id,
                'nonce' => $nonce,
                'expires_at' => now()->addSeconds(config('status.agent_signature_ttl', 300)),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Nonce already used.', 'code' => 'replayed_nonce'], 409);
        }

        $data = $request->validate([
            'status' => ['sometimes', 'in:ok,success,operational,pass,failed,timeout,config_error,auth_error,unknown'],
            'observed_at' => ['nullable', 'date'],
            'latency_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'error_code' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:2000'],
            'metrics' => ['nullable', 'array'],
        ]);
        $agent = Agent::query()->firstOrCreate(
            ['id' => self::PUSH_AGENT_ID],
            ['name' => 'signed-push-gateway', 'status' => 'online', 'capabilities' => ['heartbeat']],
        );
        $agent->update(['status' => 'online', 'last_seen_at' => now()]);
        $scheduledAt = CarbonImmutable::parse($data['observed_at'] ?? CarbonImmutable::createFromTimestampUTC((int) $timestamp))->utc();
        $inserted = DB::table('check_results')->insertOrIgnore([
            'monitor_id' => $monitor->id,
            'agent_id' => $agent->id,
            'scheduled_at' => $scheduledAt,
            'config_version' => $monitor->config_version,
            'status' => $data['status'] ?? 'ok',
            'latency_ms' => $data['latency_ms'] ?? null,
            'error_code' => $data['error_code'] ?? null,
            'message' => isset($data['message']) ? Str::limit($data['message'], 2000, '') : null,
            'metrics' => isset($data['metrics']) ? json_encode($data['metrics'], JSON_THROW_ON_ERROR) : null,
            'received_at' => now(),
        ]);
        if ($inserted) {
            $result = CheckResult::query()
                ->where('monitor_id', $monitor->id)
                ->where('agent_id', $agent->id)
                ->where('scheduled_at', $scheduledAt)
                ->where('config_version', $monitor->config_version)
                ->firstOrFail();
            $evaluator->evaluate($result);
        }

        return response()->json(['accepted' => (bool) $inserted, 'duplicate' => ! $inserted], 202);
    }
}
