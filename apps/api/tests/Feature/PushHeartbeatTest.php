<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\PushMonitorHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

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

    public function test_missing_heartbeat_degrades_and_goes_down_without_repeated_results_or_incidents(): void
    {
        $start = CarbonImmutable::parse('2026-07-14 12:00:00 UTC');
        CarbonImmutable::setTestNow($start);
        $monitor = $this->monitor('heartbeat');
        $component = $monitor->component;

        CarbonImmutable::setTestNow($start->addSeconds(151));
        $this->assertSame(1, app(PushMonitorHealthService::class)->evaluate());
        $this->assertSame(ComponentStatus::Degraded->value, $component->fresh()->status);
        $this->assertDatabaseCount('check_results', 1);
        $this->assertSame(1, Incident::query()->count());

        CarbonImmutable::setTestNow($start->addSeconds(180));
        $this->assertSame(0, app(PushMonitorHealthService::class)->evaluate());
        $this->assertDatabaseCount('check_results', 1);
        $this->assertSame(1, Incident::query()->count());

        CarbonImmutable::setTestNow($start->addSeconds(211));
        $this->assertSame(1, app(PushMonitorHealthService::class)->evaluate());
        $this->assertSame(ComponentStatus::MajorOutage->value, $component->fresh()->status);
        $this->assertDatabaseCount('check_results', 2);
        $this->assertSame(1, Incident::query()->count());

        CarbonImmutable::setTestNow($start->addSeconds(270));
        $this->assertSame(0, app(PushMonitorHealthService::class)->evaluate());
        $this->assertDatabaseCount('check_results', 2);
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_custom_heartbeat_timeouts_require_two_real_heartbeats_to_recover(): void
    {
        $start = CarbonImmutable::parse('2026-07-14 12:00:00 UTC');
        CarbonImmutable::setTestNow($start);
        $monitor = $this->monitor('heartbeat');
        $secret = 'heartbeat-test-secret';
        $monitor->update([
            'config' => ['degraded_after_seconds' => 60, 'down_after_seconds' => 90],
            'secret_config' => ['heartbeat_secret' => $secret],
        ]);

        CarbonImmutable::setTestNow($start->addSeconds(61));
        app(PushMonitorHealthService::class)->evaluate();
        $this->assertSame(ComponentStatus::Degraded->value, $monitor->component->fresh()->status);

        CarbonImmutable::setTestNow($start->addSeconds(91));
        app(PushMonitorHealthService::class)->evaluate();
        $this->assertSame(ComponentStatus::MajorOutage->value, $monitor->component->fresh()->status);

        CarbonImmutable::setTestNow($start->addSeconds(92));
        $this->signed($monitor, $secret, [
            'status' => 'ok',
            'observed_at' => CarbonImmutable::now()->toIso8601String(),
        ], 'recovery-heartbeat-one')->assertAccepted();
        $this->assertSame(ComponentStatus::MajorOutage->value, $monitor->component->fresh()->status);

        CarbonImmutable::setTestNow($start->addSeconds(93));
        $this->signed($monitor, $secret, [
            'status' => 'ok',
            'observed_at' => CarbonImmutable::now()->toIso8601String(),
        ], 'recovery-heartbeat-two')->assertAccepted();
        $this->assertSame(ComponentStatus::Operational->value, $monitor->component->fresh()->status);
        $this->assertSame(1, Incident::query()->whereNotNull('resolved_at')->count());
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
