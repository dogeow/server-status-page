<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Agent;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\LaravelIntegration;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Services\AgentStatusService;
use App\Services\PushMonitorHealthService;
use App\Services\PushResultRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaravelProbeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_package_envelope_current_and_next_signatures_and_replay_protection(): void
    {
        CarbonImmutable::setTestNow('2026-07-11 12:00:00 UTC');
        [$integration, $monitor] = $this->fixture('laravel_scheduler', 'tick');
        $body = $this->envelope('scheduler.tick', []);

        $this->signed($integration, $body, 'current-secret')->assertAccepted()->assertJsonPath('routed', 1);
        $this->assertNotNull($monitor->fresh()->last_success_at);
        CarbonImmutable::setTestNow('2026-07-11 12:10:00 UTC');
        app(AgentStatusService::class)->markStaleAgents();
        $this->assertSame('online', Agent::query()->findOrFail(PushResultRecorder::PUSH_AGENT_ID)->status);
        $this->assertDatabaseMissing('outbox_events', ['type' => 'agent.offline', 'aggregate_id' => PushResultRecorder::PUSH_AGENT_ID]);
        CarbonImmutable::setTestNow('2026-07-11 12:00:00 UTC');

        $nonce = Str::random(24);
        $this->signed($integration, $body, 'next-secret', $nonce, true)->assertAccepted();
        $this->signed($integration, $body, 'next-secret', $nonce, true)->assertStatus(409)->assertJsonPath('code', 'replayed_nonce');
    }

    public function test_stopped_queue_worker_degrades_at_150_seconds_and_goes_down_at_210_seconds(): void
    {
        $start = CarbonImmutable::parse('2026-07-11 12:00:00 UTC');
        CarbonImmutable::setTestNow($start);
        [$integration, $monitor, $component] = $this->fixture('laravel_queue', 'default');
        $probeId = (string) Str::uuid();
        $body = $this->envelope('queue.enqueued', ['probe_id' => $probeId, 'target' => 'default', 'enqueued_at' => $start->toISOString()]);
        $this->signed($integration, $body, 'current-secret')->assertAccepted()->assertJsonPath('routed', 1);

        CarbonImmutable::setTestNow($start->addSeconds(151));
        $this->assertSame(1, app(PushMonitorHealthService::class)->evaluate());
        $this->assertSame(ComponentStatus::Degraded->value, $component->fresh()->status);

        CarbonImmutable::setTestNow($start->addSeconds(211));
        $this->assertSame(1, app(PushMonitorHealthService::class)->evaluate());
        $this->assertSame(ComponentStatus::MajorOutage->value, $component->fresh()->status);
    }

    public function test_missing_queue_enqueued_event_also_degrades_and_goes_down(): void
    {
        $start = CarbonImmutable::parse('2026-07-11 12:00:00 UTC');
        CarbonImmutable::setTestNow($start);
        [, , $component] = $this->fixture('laravel_queue', 'default');

        CarbonImmutable::setTestNow($start->addSeconds(151));
        app(PushMonitorHealthService::class)->evaluate();
        $this->assertSame(ComponentStatus::Degraded->value, $component->fresh()->status);

        CarbonImmutable::setTestNow($start->addSeconds(211));
        app(PushMonitorHealthService::class)->evaluate();
        $this->assertSame(ComponentStatus::MajorOutage->value, $component->fresh()->status);
    }

    public function test_missing_scheduler_tick_uses_the_same_150_and_210_second_thresholds(): void
    {
        $start = CarbonImmutable::parse('2026-07-11 12:00:00 UTC');
        CarbonImmutable::setTestNow($start);
        [, , $component] = $this->fixture('laravel_scheduler', 'tick');

        CarbonImmutable::setTestNow($start->addSeconds(151));
        app(PushMonitorHealthService::class)->evaluate();
        $this->assertSame(ComponentStatus::Degraded->value, $component->fresh()->status);

        CarbonImmutable::setTestNow($start->addSeconds(211));
        app(PushMonitorHealthService::class)->evaluate();
        $this->assertSame(ComponentStatus::MajorOutage->value, $component->fresh()->status);
    }

    private function fixture(string $type, string $target): array
    {
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main']);
        $group = ComponentGroup::query()->create(['status_page_id' => $page->id, 'name' => 'Laravel', 'slug' => 'laravel']);
        $component = Component::query()->create(['component_group_id' => $group->id, 'name' => $target, 'slug' => $target]);
        $integration = LaravelIntegration::query()->create([
            'status_page_id' => $page->id,
            'name' => 'Orders API',
            'application_id' => 'orders-api',
            'secret_current' => 'current-secret',
            'secret_next' => 'next-secret',
        ]);
        $monitor = Monitor::query()->create([
            'component_id' => $component->id,
            'name' => $target,
            'type' => $type,
            'config' => ['integration_id' => $integration->id, 'application_id' => 'orders-api', 'target' => $target],
        ]);

        return [$integration, $monitor, $component];
    }

    private function envelope(string $event, array $payload): string
    {
        return json_encode([
            'event' => $event,
            'occurred_at' => CarbonImmutable::now()->format(DATE_RFC3339_EXTENDED),
            'application' => ['id' => 'orders-api', 'environment' => 'testing', 'instance_id' => 'worker-1'],
            'payload' => $payload === [] ? new \stdClass : $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function signed(LaravelIntegration $integration, string $body, string $secret, ?string $nonce = null, bool $nextHeader = false)
    {
        $timestamp = CarbonImmutable::now()->timestamp;
        $nonce ??= Str::random(24);
        $hash = hash('sha256', $body);
        $canonical = "STATUS-PROBE-HMAC-SHA256-V1\n{$timestamp}\n{$nonce}\n{$hash}";
        $signature = 'sha256='.hash_hmac('sha256', $canonical, $secret);
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_STATUS_PROBE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_STATUS_PROBE_NONCE' => $nonce,
            'HTTP_X_STATUS_PROBE_CONTENT_SHA256' => $hash,
            $nextHeader ? 'HTTP_X_STATUS_PROBE_SIGNATURE_NEXT' : 'HTTP_X_STATUS_PROBE_SIGNATURE' => $signature,
        ];

        return $this->call('POST', '/api/probe/v1/integrations/'.$integration->id.'/events', [], [], [], $server, $body);
    }
}
