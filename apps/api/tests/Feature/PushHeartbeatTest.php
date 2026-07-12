<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_heartbeat_is_idempotent_and_replay_protected(): void
    {
        $monitor = $this->monitor('heartbeat');
        $secret = 'heartbeat-test-secret';
        $monitor->update(['secret_config' => ['heartbeat_secret' => $secret]]);
        $payload = ['status' => 'ok', 'observed_at' => now()->startOfSecond()->toIso8601String(), 'latency_ms' => 12];
        $nonce = 'push-nonce-12345678';

        $this->signed($monitor, $secret, $payload, $nonce)->assertAccepted()->assertJsonPath('accepted', true);
        $this->assertDatabaseHas('check_results', ['monitor_id' => $monitor->id, 'status' => 'ok']);
        $this->signed($monitor, $secret, $payload, $nonce)->assertStatus(409)->assertJsonPath('code', 'replayed_nonce');
    }

    public function test_heartbeat_rejects_expired_signatures_and_non_heartbeat_monitors(): void
    {
        $heartbeat = $this->monitor('heartbeat');
        $heartbeat->update(['secret_config' => ['heartbeat_secret' => 'secret']]);
        $this->signed($heartbeat, 'secret', ['status' => 'ok'], null, now()->subMinutes(10)->timestamp)->assertUnauthorized();

        $http = $this->monitor('http', 'http-monitor');
        $http->update(['secret_config' => ['heartbeat_secret' => 'secret']]);
        $this->signed($http, 'secret', ['status' => 'ok'])->assertNotFound();
    }

    public function test_admin_rotation_only_returns_a_heartbeat_secret_once(): void
    {
        config(['app.url' => 'https://status.example.test']);
        Sanctum::actingAs(User::factory()->create(['role' => 'owner']));
        $heartbeat = $this->monitor('heartbeat');

        $this->postJson('/api/admin/v1/monitors/'.$heartbeat->id.'/rotate-heartbeat-secret')
            ->assertOk()
            ->assertJsonPath('heartbeat_url', 'https://status.example.test/api/probe/v1/heartbeat/'.$heartbeat->id)
            ->assertJsonStructure(['heartbeat_secret']);

        $http = $this->monitor('http', 'http-monitor');
        $this->postJson('/api/admin/v1/monitors/'.$http->id.'/rotate-heartbeat-secret')->assertUnprocessable();
    }

    private function monitor(string $type, string $slug = 'heartbeat-monitor'): Monitor
    {
        $page = StatusPage::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Status']);
        $group = ComponentGroup::query()->firstOrCreate(['status_page_id' => $page->id, 'slug' => 'api'], ['name' => 'API']);
        $component = Component::query()->firstOrCreate(['component_group_id' => $group->id, 'slug' => $slug], ['name' => $slug]);

        return Monitor::query()->create([
            'component_id' => $component->id,
            'name' => $slug,
            'type' => $type,
            'enabled' => true,
            'config_version' => 1,
        ]);
    }

    private function signed(Monitor $monitor, string $secret, array $payload, ?string $nonce = null, ?int $timestamp = null)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp ??= now()->timestamp;
        $nonce ??= Str::random(20);
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".hash('sha256', $body), $secret);

        return $this->call('POST', '/api/probe/v1/heartbeat/'.$monitor->id, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => $signature,
        ], $body);
    }
}
