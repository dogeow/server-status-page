<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Agent;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\OutboxEvent;
use App\Models\StatusPage;
use App\Services\AgentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_failures_degrade_then_outage_and_two_successes_resolve_single_incident(): void
    {
        [$agent, $monitor, $component] = $this->fixture();

        $this->submitResult($agent, $monitor, 'failed', 0)->assertAccepted();
        $this->assertSame(ComponentStatus::Unknown->value, $monitor->fresh()->status);
        $this->submitResult($agent, $monitor, 'failed', 1)->assertAccepted();
        $this->assertSame(ComponentStatus::Degraded->value, $component->fresh()->status);
        $this->assertDatabaseCount('incidents', 1);
        $this->submitResult($agent, $monitor, 'failed', 2)->assertAccepted();
        $this->assertSame(ComponentStatus::MajorOutage->value, $component->fresh()->status);
        $this->assertDatabaseCount('incidents', 1);

        $this->submitResult($agent, $monitor, 'ok', 3)->assertAccepted();
        $this->assertSame(ComponentStatus::MajorOutage->value, $component->fresh()->status);
        $this->submitResult($agent, $monitor, 'ok', 4)->assertAccepted();
        $this->assertSame(ComponentStatus::Operational->value, $component->fresh()->status);
        $this->assertSame('resolved', Incident::query()->sole()->status);
        $this->assertNotNull(Incident::query()->sole()->resolved_at);
    }

    public function test_result_idempotency_does_not_advance_failure_counter_twice(): void
    {
        [$agent, $monitor] = $this->fixture();
        $scheduledAt = now()->startOfSecond()->toIso8601String();
        $payload = $this->payload($monitor, 'failed', $scheduledAt);

        $this->signed($agent, $payload)->assertAccepted()->assertJsonPath('accepted', 1);
        $this->signed($agent, $payload)->assertAccepted()->assertJsonPath('duplicates', 1);

        $this->assertSame(1, $monitor->fresh()->consecutive_failures);
        $this->assertDatabaseCount('check_results', 1);
    }

    public function test_configuration_error_is_unknown_and_not_a_public_incident(): void
    {
        [$agent, $monitor, $component] = $this->fixture();

        $this->submitResult($agent, $monitor, 'auth_error', 0)->assertAccepted();
        $this->submitResult($agent, $monitor, 'auth_error', 1)->assertAccepted();

        $this->assertSame(ComponentStatus::Unknown->value, $component->fresh()->status);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertTrue(OutboxEvent::query()->where('type', 'monitor.configuration_error')->exists());
        $this->assertSame(1, OutboxEvent::query()->where('type', 'monitor.configuration_error')->count());
    }

    public function test_unknown_observation_never_accumulates_outage_failures(): void
    {
        [$agent, $monitor, $component] = $this->fixture();
        for ($offset = 0; $offset < 4; $offset++) {
            $this->submitResult($agent, $monitor, 'unknown', $offset)->assertAccepted();
        }

        $monitor->refresh();
        $this->assertSame(ComponentStatus::Unknown->value, $monitor->status);
        $this->assertSame(0, $monitor->consecutive_failures);
        $this->assertSame(ComponentStatus::Unknown->value, $component->fresh()->status);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_slow_degradation_requires_two_fast_successes_to_recover(): void
    {
        [$agent, $monitor, $component] = $this->fixture();
        $monitor->update(['status' => ComponentStatus::Operational->value, 'slow_threshold_ms' => 50]);
        $component->update(['status' => ComponentStatus::Operational->value]);

        for ($offset = 0; $offset < 3; $offset++) {
            $this->submitResult($agent, $monitor, 'ok', $offset)->assertAccepted();
        }
        $this->assertSame(ComponentStatus::Degraded->value, $component->fresh()->status);

        $this->submitResult($agent, $monitor, 'ok', 3, 25)->assertAccepted();
        $this->assertSame(ComponentStatus::Degraded->value, $component->fresh()->status);
        $this->submitResult($agent, $monitor, 'ok', 4, 25)->assertAccepted();
        $this->assertSame(ComponentStatus::Operational->value, $component->fresh()->status);
    }

    public function test_active_maintenance_suppresses_incident_while_sampling_continues(): void
    {
        [$agent, $monitor, $component] = $this->fixture();
        $window = MaintenanceWindow::query()->create([
            'status_page_id' => $component->group->status_page_id,
            'name' => 'Deploy',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'status' => 'in_progress',
        ]);
        $window->components()->attach($component->id);

        $this->submitResult($agent, $monitor, 'failed', 0)->assertAccepted();
        $this->submitResult($agent, $monitor, 'failed', 1)->assertAccepted();
        $this->submitResult($agent, $monitor, 'failed', 2)->assertAccepted();

        $this->assertSame(ComponentStatus::Maintenance->value, $component->fresh()->status);
        $this->assertDatabaseCount('check_results', 3);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_offline_agent_marks_status_unknown_and_routes_admin_event_to_page(): void
    {
        [$agent, $monitor, $component] = $this->fixture();
        $agent->update(['last_seen_at' => now()->subMinutes(10), 'status' => 'online']);
        $monitor->update(['status' => ComponentStatus::Operational->value]);
        $component->update(['status' => ComponentStatus::Operational->value]);

        $this->assertSame(1, app(AgentStatusService::class)->markStaleAgents());

        $this->assertSame(ComponentStatus::Unknown->value, $component->fresh()->status);
        $this->assertDatabaseCount('incidents', 0);
        $event = OutboxEvent::query()->where('type', 'agent.offline')->sole();
        $this->assertSame($component->group->status_page_id, $event->payload['status_page_id']);
    }

    public function test_offline_agent_reaggregates_component_using_other_operational_observer(): void
    {
        [$agent, $monitor, $component] = $this->fixture();
        $agent->update(['last_seen_at' => now()->subMinutes(10), 'status' => 'online']);
        $monitor->update(['status' => ComponentStatus::Operational->value]);
        $other = Agent::query()->create(['id' => (string) Str::uuid(), 'name' => 'other', 'status' => 'online', 'secret' => 'other-secret', 'last_seen_at' => now()]);
        Monitor::query()->create(['component_id' => $component->id, 'agent_id' => $other->id, 'name' => 'HTTP other', 'type' => 'http', 'status' => ComponentStatus::Operational->value]);
        $component->update(['status' => ComponentStatus::Degraded->value]);

        app(AgentStatusService::class)->markStaleAgents();

        $this->assertSame(ComponentStatus::Unknown->value, $monitor->fresh()->status);
        $this->assertSame(ComponentStatus::Operational->value, $component->fresh()->status);
        $this->assertDatabaseHas('outbox_events', ['type' => 'component.status_changed', 'aggregate_id' => (string) $component->id]);
    }

    private function fixture(): array
    {
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main']);
        $group = ComponentGroup::query()->create(['status_page_id' => $page->id, 'name' => 'API', 'slug' => 'api']);
        $component = Component::query()->create(['component_group_id' => $group->id, 'name' => 'Web', 'slug' => 'web']);
        $agent = Agent::query()->create(['id' => (string) Str::uuid(), 'name' => 'edge', 'status' => 'online', 'secret' => 'test-secret', 'last_seen_at' => now()]);
        $monitor = Monitor::query()->create(['component_id' => $component->id, 'agent_id' => $agent->id, 'name' => 'HTTP', 'type' => 'http', 'config_version' => 1]);

        return [$agent, $monitor, $component];
    }

    private function submitResult(Agent $agent, Monitor $monitor, string $status, int $offset, int $latencyMs = 100)
    {
        $payload = $this->payload($monitor, $status, now()->startOfSecond()->addSeconds($offset)->toIso8601String());
        $payload['results'][0]['latency_ms'] = $latencyMs;

        return $this->signed($agent, $payload);
    }

    private function payload(Monitor $monitor, string $status, string $scheduledAt): array
    {
        return ['results' => [[
            'monitor_id' => $monitor->id,
            'scheduled_at' => $scheduledAt,
            'config_version' => 1,
            'status' => $status,
            'latency_ms' => 100,
            'error_code' => $status === 'ok' ? null : $status,
        ]]];
    }

    private function signed(Agent $agent, array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp;
        $nonce = Str::random(20);
        $signature = hash_hmac('sha256', $timestamp."\n".$nonce."\n".hash('sha256', $body), 'test-secret');

        return $this->call('POST', '/api/agent/v1/results/batch', [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AGENT_ID' => $agent->id, 'HTTP_X_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_NONCE' => $nonce, 'HTTP_X_SIGNATURE' => $signature,
        ], $body);
    }
}
