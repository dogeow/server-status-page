<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\CheckResult;
use App\Models\Monitor;
use App\Services\StateEvaluator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentController extends Controller
{
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:24'],
            'name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:64'],
            'capabilities' => ['nullable', 'array'],
        ]);

        return DB::transaction(function () use ($data): JsonResponse {
            $token = AgentEnrollmentToken::query()->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
            if (! $token || $token->used_at || ($token->expires_at && $token->expires_at->isPast())) {
                return response()->json(['message' => 'Enrollment token is invalid or expired.', 'code' => 'invalid_enrollment_token'], 422);
            }

            $secret = bin2hex(random_bytes(32));
            $agent = Agent::query()->create([
                'id' => (string) Str::uuid(),
                'name' => $data['name'],
                'version' => $data['version'] ?? null,
                'status' => 'online',
                'secret' => $secret,
                'capabilities' => $data['capabilities'] ?? [],
                'plan_version' => 1,
                'last_seen_at' => now(),
                'enrolled_at' => now(),
            ]);
            $token->update(['used_at' => now(), 'agent_id' => $agent->id]);

            return response()->json([
                'agent_id' => $agent->id,
                'secret' => $secret,
                'plan_url' => '/api/agent/v1/plan',
                'heartbeat_url' => '/api/agent/v1/heartbeat',
                'results_url' => '/api/agent/v1/results/batch',
            ], 201);
        });
    }

    public function plan(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->attributes->get('agent');
        $agent->update(['last_seen_at' => now(), 'status' => 'online']);

        $monitors = $agent->monitors()->where('enabled', true)->with('component:id,name')->orderBy('id')->get()->map(fn (Monitor $monitor) => [
            'id' => $monitor->id,
            'monitor_id' => $monitor->id,
            'name' => $monitor->name,
            'component_id' => $monitor->component_id,
            'component_name' => $monitor->component?->name,
            'type' => $monitor->type,
            'enabled' => (bool) $monitor->enabled,
            'interval_seconds' => $monitor->interval_seconds,
            'timeout_seconds' => $monitor->timeout_seconds,
            'timeout_ms' => $monitor->timeout_seconds * 1000,
            'connect_timeout_ms' => (int) (($monitor->config['connect_timeout_ms'] ?? null) ?: min(2000, $monitor->timeout_seconds * 1000)),
            'slow_threshold_ms' => $monitor->slow_threshold_ms,
            'config_version' => (string) $monitor->config_version,
            'config' => ($monitor->secret_config || $monitor->config)
                ? array_merge($monitor->config ?: [], $monitor->secret_config ?: [])
                : new \stdClass,
        ])->values();

        $payload = ['version' => (string) $agent->plan_version, 'monitors' => $monitors];
        $etag = '"'.hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return response()->json($payload)->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:64'],
            'capabilities' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'plan_version' => ['nullable', $this->versionScalarRule(true)],
            'observed_at' => ['nullable', 'date'],
            'active_checks' => ['nullable', 'integer', 'min:0'],
            'spool_depth' => ['nullable', 'integer', 'min:0'],
            'spool_dropped' => ['nullable', 'integer', 'min:0'],
        ]);
        /** @var Agent $agent */
        $agent = $request->attributes->get('agent');
        $agent->update(array_filter([
            'status' => 'online',
            'last_seen_at' => now(),
            'version' => $data['version'] ?? null,
            'capabilities' => $data['capabilities'] ?? null,
            'metadata' => array_filter([
                ...($data['metadata'] ?? []),
                'reported_plan_version' => isset($data['plan_version']) ? (string) $data['plan_version'] : null,
                'observed_at' => $data['observed_at'] ?? null,
                'active_checks' => $data['active_checks'] ?? null,
                'spool_depth' => $data['spool_depth'] ?? null,
                'spool_dropped' => $data['spool_dropped'] ?? null,
            ], fn ($value) => $value !== null),
        ], fn ($value) => $value !== null));

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String(), 'plan_version' => (string) $agent->plan_version]);
    }

    public function results(Request $request, StateEvaluator $evaluator): JsonResponse
    {
        $data = $request->validate([
            'results' => ['required', 'array', 'min:1', 'max:500'],
            'results.*.monitor_id' => ['required', 'integer'],
            'results.*.agent_id' => ['nullable', 'uuid'],
            'results.*.scheduled_at' => ['required', 'date'],
            'results.*.config_version' => ['required', $this->versionScalarRule(false)],
            'results.*.status' => ['required', 'in:ok,success,operational,pass,failed,timeout,config_error,auth_error,unknown'],
            'results.*.latency_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'results.*.error_code' => ['nullable', 'string', 'max:64'],
            'results.*.message' => ['nullable', 'string', 'max:2000'],
            'results.*.metrics' => ['nullable', 'array'],
        ]);
        /** @var Agent $agent */
        $agent = $request->attributes->get('agent');
        $agent->update(['last_seen_at' => now(), 'status' => 'online']);

        $accepted = 0;
        $duplicates = 0;
        $skipped = 0;
        $errors = [];

        foreach ($data['results'] as $index => $item) {
            $configVersion = (string) $item['config_version'];
            $monitor = Monitor::query()->whereKey($item['monitor_id'])->where('agent_id', $agent->id)->first();
            if (! $monitor) {
                $errors[] = ['index' => $index, 'code' => 'monitor_not_assigned'];

                continue;
            }
            if (isset($item['agent_id']) && $item['agent_id'] !== $agent->id) {
                $errors[] = ['index' => $index, 'code' => 'agent_id_mismatch'];

                continue;
            }

            $scheduledAt = CarbonImmutable::parse($item['scheduled_at'])->utc();
            $inserted = DB::table('check_results')->insertOrIgnore([
                'monitor_id' => $monitor->id,
                'agent_id' => $agent->id,
                'scheduled_at' => $scheduledAt,
                'config_version' => $configVersion,
                'status' => $item['status'],
                'latency_ms' => $item['latency_ms'] ?? null,
                'error_code' => $item['error_code'] ?? null,
                'message' => isset($item['message']) ? Str::limit($item['message'], 2000, '') : null,
                'metrics' => isset($item['metrics']) ? json_encode($item['metrics'], JSON_THROW_ON_ERROR) : null,
                'received_at' => now(),
            ]);

            if (! $inserted) {
                $duplicates++;

                continue;
            }
            $accepted++;

            if ($configVersion !== (string) $monitor->config_version) {
                $skipped++;

                continue;
            }

            $result = CheckResult::query()
                ->where('monitor_id', $monitor->id)
                ->where('agent_id', $agent->id)
                ->where('scheduled_at', $scheduledAt)
                ->where('config_version', $configVersion)
                ->firstOrFail();
            $evaluator->evaluate($result);
        }

        return response()->json(compact('accepted', 'duplicates', 'skipped', 'errors'), $errors && $accepted === 0 ? 422 : 202);
    }

    private function versionScalarRule(bool $allowZero): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($allowZero): void {
            if (! is_int($value) && ! is_string($value)) {
                $fail("The {$attribute} field must be an integer or decimal string.");

                return;
            }

            $version = (string) $value;
            $pattern = $allowZero ? '/^(0|[1-9][0-9]{0,18})$/' : '/^[1-9][0-9]{0,18}$/';
            if (! preg_match($pattern, $version) || (strlen($version) === 19 && strcmp($version, '9223372036854775807') > 0)) {
                $fail("The {$attribute} field must be a valid 64-bit version.");
            }
        };
    }
}
