<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Monitor;
use App\Models\StatusPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollment_token_is_one_time_and_plan_supports_etag(): void
    {
        $rawToken = Str::random(64);
        AgentEnrollmentToken::query()->create(['token_hash' => hash('sha256', $rawToken), 'expires_at' => now()->addHour()]);

        $enrollment = $this->postJson('/api/agent/v1/enroll', [
            'token' => $rawToken,
            'name' => 'edge-1',
            'version' => '1.0.0',
            'capabilities' => ['http'],
        ])->assertCreated()->assertJsonStructure(['agent_id', 'secret', 'plan_url', 'heartbeat_url', 'results_url']);

        $this->postJson('/api/agent/v1/enroll', ['token' => $rawToken, 'name' => 'replay'])->assertUnprocessable();
        $agent = Agent::query()->findOrFail($enrollment->json('agent_id'));
        $this->monitor($agent);

        $plan = $this->signed($agent, $enrollment->json('secret'), 'GET', '/api/agent/v1/plan')
            ->assertOk()
            ->assertJsonPath('version', '2')
            ->assertJsonCount(1, 'monitors');
        $etag = $plan->headers->get('ETag');

        $this->signed($agent, $enrollment->json('secret'), 'GET', '/api/agent/v1/plan', null, ['HTTP_IF_NONE_MATCH' => $etag])->assertStatus(304);
    }

    public function test_signature_rejects_expired_timestamp_and_replayed_nonce(): void
    {
        $agent = Agent::query()->create(['id' => (string) Str::uuid(), 'name' => 'edge', 'status' => 'online', 'secret' => 'test-secret']);
        $nonce = 'nonce-12345678';

        $this->signed($agent, 'test-secret', 'POST', '/api/agent/v1/heartbeat', [], [], now()->subMinutes(10)->timestamp, $nonce)
            ->assertUnauthorized()->assertJsonPath('code', 'timestamp_expired');

        $this->signed($agent, 'test-secret', 'POST', '/api/agent/v1/heartbeat', [], [], null, $nonce)->assertOk();
        $this->signed($agent, 'test-secret', 'POST', '/api/agent/v1/heartbeat', [], [], null, $nonce)
            ->assertStatus(409)->assertJsonPath('code', 'replayed_nonce');
    }

    public function test_go_agent_string_versions_are_accepted_for_heartbeat_and_results(): void
    {
        $agent = Agent::query()->create(['id' => (string) Str::uuid(), 'name' => 'edge', 'status' => 'online', 'secret' => 'test-secret']);
        $monitor = $this->monitor($agent);

        $this->signed($agent, 'test-secret', 'POST', '/api/agent/v1/heartbeat', [
            'version' => '1.0.0',
            'plan_version' => '2',
            'observed_at' => now()->toIso8601String(),
            'active_checks' => 1,
            'spool_depth' => 0,
            'spool_dropped' => 0,
        ])->assertOk()->assertJsonPath('plan_version', '2');
        $this->assertSame('2', $agent->fresh()->metadata['reported_plan_version']);

        $this->signed($agent, 'test-secret', 'POST', '/api/agent/v1/results/batch', [
            'results' => [[
                'monitor_id' => (string) $monitor->id,
                'agent_id' => $agent->id,
                'scheduled_at' => now()->startOfSecond()->toIso8601String(),
                'config_version' => '1',
                'status' => 'ok',
                'latency_ms' => 25,
            ]],
        ])->assertAccepted()->assertJsonPath('accepted', 1)->assertJsonPath('skipped', 0);
        $this->assertDatabaseHas('check_results', ['monitor_id' => $monitor->id, 'config_version' => 1]);
    }

    private function monitor(Agent $agent): Monitor
    {
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main']);
        $group = ComponentGroup::query()->create(['status_page_id' => $page->id, 'name' => 'API', 'slug' => 'api']);
        $component = Component::query()->create(['component_group_id' => $group->id, 'name' => 'Web', 'slug' => 'web']);
        $monitor = Monitor::query()->create(['component_id' => $component->id, 'agent_id' => $agent->id, 'name' => 'HTTP', 'type' => 'http', 'config' => ['url' => 'https://example.test'], 'config_version' => 1]);
        $agent->increment('plan_version');

        return $monitor;
    }

    private function signed(Agent $agent, string $secret, string $method, string $uri, ?array $payload = null, array $extraServer = [], ?int $timestamp = null, ?string $nonce = null)
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = $timestamp ?? now()->timestamp;
        $nonce = $nonce ?? Str::random(20);
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".hash('sha256', $body), $secret);
        $server = array_merge([
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AGENT_ID' => $agent->id,
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
        ], $extraServer);

        return $this->call($method, $uri, [], [], [], $server, $body);
    }
}
