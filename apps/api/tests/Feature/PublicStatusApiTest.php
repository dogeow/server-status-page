<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\DailyRollup;
use App\Models\Incident;
use App\Models\StatusInterval;
use App\Models\StatusPage;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_status_contains_real_grouped_ninety_day_history(): void
    {
        $this->seed(DemoSeeder::class);

        $response = $this->getJson('/api/public/v1/status');

        $response->assertOk()
            ->assertJsonPath('status_page.slug', 'main')
            ->assertJsonPath('overall_status', 'operational')
            ->assertJsonCount(3, 'groups')
            ->assertJsonCount(90, 'groups.0.daily_history')
            ->assertJsonCount(90, 'groups.0.components.0.daily_history')
            ->assertJsonStructure(['groups' => [['daily_history' => [['status_periods']], 'components' => [['uptime_percent', 'latency_ms', 'daily_history' => [['status_periods']]]]]], 'incidents', 'maintenances']);

        $this->assertStringContainsString('stale-if-error=86400', $response->headers->get('Cache-Control'));

        $this->assertArrayNotHasKey('secret', $response->json());
        $etag = $response->headers->get('ETag');
        $this->travel(1)->minute();
        $this->withHeader('If-None-Match', $etag)->getJson('/api/public/v1/status')->assertStatus(304);
        $this->travelBack();
    }

    public function test_public_status_uses_page_timezone_and_does_not_backfill_missing_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-11 16:30:00 UTC'));
        $page = StatusPage::query()->create([
            'name' => 'Status',
            'slug' => 'main',
            'timezone' => 'Asia/Shanghai',
            'is_public' => true,
        ]);
        $group = ComponentGroup::query()->create([
            'status_page_id' => $page->id,
            'name' => 'API',
            'slug' => 'api',
        ]);
        $component = Component::query()->create([
            'component_group_id' => $group->id,
            'name' => 'API',
            'slug' => 'api',
            'status' => 'operational',
        ]);
        DailyRollup::query()->create([
            'component_id' => $component->id,
            'date' => '2026-07-12',
            'uptime_percentage' => 100,
            'observed_seconds' => 1800,
            'available_seconds' => 1800,
            'worst_status' => 'operational',
        ]);

        $response = $this->getJson('/api/public/v1/status')->assertOk();
        $history = $response->json('groups.0.components.0.daily_history');

        $this->assertCount(90, $history);
        $this->assertSame('2026-04-14', $history[0]['date']);
        $this->assertSame('unknown', $history[0]['status']);
        $this->assertNull($history[0]['uptime_percent']);
        $this->assertSame('2026-07-12', $history[89]['date']);
        $this->assertSame('operational', $history[89]['status']);
        $this->assertEquals(100.0, $history[89]['uptime_percent']);

        $this->travelBack();
    }

    public function test_new_component_does_not_make_previous_group_history_unknown(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-16 01:00:00 UTC'));
        $page = StatusPage::query()->create([
            'name' => 'Status',
            'slug' => 'main',
            'timezone' => 'Asia/Shanghai',
            'is_public' => true,
        ]);
        $group = ComponentGroup::query()->create([
            'status_page_id' => $page->id,
            'name' => 'API',
            'slug' => 'api',
        ]);
        $existingComponent = Component::query()->create([
            'component_group_id' => $group->id,
            'name' => 'Existing API',
            'slug' => 'existing-api',
            'status' => 'operational',
            'created_at' => CarbonImmutable::parse('2026-07-14 16:00:00 UTC'),
        ]);
        $newComponent = Component::query()->create([
            'component_group_id' => $group->id,
            'name' => 'New API',
            'slug' => 'new-api',
            'status' => 'operational',
            'created_at' => CarbonImmutable::parse('2026-07-15 16:26:00 UTC'),
        ]);
        DailyRollup::query()->create([
            'component_id' => $existingComponent->id,
            'date' => '2026-07-15',
            'uptime_percentage' => 100,
            'observed_seconds' => 86400,
            'available_seconds' => 86400,
            'worst_status' => 'operational',
        ]);
        foreach ([$existingComponent, $newComponent] as $component) {
            DailyRollup::query()->create([
                'component_id' => $component->id,
                'date' => '2026-07-16',
                'uptime_percentage' => 100,
                'observed_seconds' => 3600,
                'available_seconds' => 3600,
                'worst_status' => 'operational',
            ]);
        }

        $status = $this->getJson('/api/public/v1/status')->assertOk();
        $this->assertSame('2026-07-15', $status->json('groups.0.daily_history.88.date'));
        $this->assertSame('operational', $status->json('groups.0.daily_history.88.status'));
        $this->assertEquals(100.0, $status->json('groups.0.daily_history.88.uptime_percent'));

        $history = $this->getJson('/api/public/v1/history?from=2026-07-15&to=2026-07-16')->assertOk();
        $this->assertSame('operational', $history->json('groups.0.daily_history.0.status'));
        $this->assertEquals(100.0, $history->json('groups.0.daily_history.0.uptime_percent'));

        $this->travelBack();
    }

    public function test_public_history_exposes_clipped_status_periods_with_duration(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 09:00:00 UTC'));
        $page = StatusPage::query()->create([
            'name' => 'Status',
            'slug' => 'main',
            'timezone' => 'Asia/Shanghai',
            'is_public' => true,
        ]);
        $group = ComponentGroup::query()->create([
            'status_page_id' => $page->id,
            'name' => 'API',
            'slug' => 'api',
        ]);
        $component = Component::query()->create([
            'component_group_id' => $group->id,
            'name' => 'Game',
            'slug' => 'game',
            'status' => 'major_outage',
        ]);
        DailyRollup::query()->create([
            'component_id' => $component->id,
            'date' => '2026-07-15',
            'uptime_percentage' => 11.7647,
            'observed_seconds' => 61200,
            'available_seconds' => 7200,
            'worst_status' => 'major_outage',
        ]);
        StatusInterval::query()->create([
            'component_id' => $component->id,
            'status' => 'degraded',
            'started_at' => CarbonImmutable::parse('2026-07-14 18:00:00 UTC'),
            'ended_at' => CarbonImmutable::parse('2026-07-14 20:30:00 UTC'),
        ]);
        StatusInterval::query()->create([
            'component_id' => $component->id,
            'status' => 'major_outage',
            'started_at' => CarbonImmutable::parse('2026-07-14 20:30:00 UTC'),
            'ended_at' => null,
        ]);
        $incident = Incident::query()->create([
            'status_page_id' => $page->id,
            'title' => 'Game 登录异常',
            'status' => 'resolved',
            'impact' => 'major_outage',
            'is_public' => true,
            'started_at' => CarbonImmutable::parse('2026-07-14 18:00:00 UTC'),
            'resolved_at' => CarbonImmutable::parse('2026-07-15 09:00:00 UTC'),
        ]);
        $incident->components()->attach($component->id);
        $incident->updates()->create(['status' => 'resolved', 'message' => '登录服务已经恢复。']);

        foreach (['/api/public/v1/status', '/api/public/v1/history?from=2026-07-15&to=2026-07-15'] as $url) {
            $response = $this->getJson($url)->assertOk();
            $componentPeriod = $response->json('groups.0.components.0.daily_history.0.status_periods.0');
            if (str_contains($url, '/status')) {
                $componentPeriod = $response->json('groups.0.components.0.daily_history.89.status_periods.0');
            }

            $this->assertSame('degraded', $componentPeriod['status']);
            $this->assertSame('2026-07-14T18:00:00+00:00', $componentPeriod['started_at']);
            $this->assertSame('2026-07-14T20:30:00+00:00', $componentPeriod['ended_at']);
            $this->assertSame(9000, $componentPeriod['duration_seconds']);
            $this->assertFalse($componentPeriod['ongoing']);
            $this->assertSame($incident->id, $componentPeriod['incident_id']);
            $this->assertSame('Game 登录异常', $componentPeriod['incident_title']);
            $this->assertSame('登录服务已经恢复。', $componentPeriod['incident_message']);

            $componentPath = str_contains($url, '/status')
                ? 'groups.0.components.0.daily_history.89.status_periods.1'
                : 'groups.0.components.0.daily_history.0.status_periods.1';
            $ongoingPeriod = $response->json($componentPath);
            $this->assertSame('major_outage', $ongoingPeriod['status']);
            $this->assertSame('2026-07-14T20:30:00+00:00', $ongoingPeriod['started_at']);
            $this->assertNull($ongoingPeriod['ended_at']);
            $this->assertSame(45000, $ongoingPeriod['duration_seconds']);
            $this->assertTrue($ongoingPeriod['ongoing']);

            $groupPath = str_contains($url, '/status')
                ? 'groups.0.daily_history.89.status_periods.0.component_name'
                : 'groups.0.daily_history.0.status_periods.0.component_name';
            $this->assertSame('Game', $response->json($groupPath));
        }

        $this->travelBack();
    }

    public function test_public_subscription_endpoints_are_disabled(): void
    {
        $this->postJson('/api/public/v1/subscriptions', ['email' => 'ops@example.com', 'page' => 'main'])
            ->assertNotFound();
        $this->getJson('/api/public/v1/subscriptions/confirm/disabled')->assertNotFound();
    }

    public function test_history_contract_contains_month_navigation_aggregates_and_stable_etag(): void
    {
        $this->seed(DemoSeeder::class);
        $to = CarbonImmutable::today('UTC');
        $from = $to->subDays(30);

        $response = $this->getJson('/api/public/v1/history?from='.$from->toDateString().'&to='.$to->toDateString())
            ->assertOk()
            ->assertJsonPath('overall_status', 'operational')
            ->assertJsonCount(31, 'groups.0.daily_history')
            ->assertJsonCount(31, 'groups.0.components.0.daily_history')
            ->assertJsonStructure([
                'status_page', 'from', 'to', 'overall_status', 'generated_at',
                'groups' => [[
                    'id', 'name', 'slug', 'status', 'component_count', 'uptime_percent', 'latency_ms', 'daily_history',
                    'components' => [['id', 'name', 'slug', 'status', 'uptime_percent', 'latency_ms', 'daily_history']],
                ]],
                'components', 'incidents',
            ]);
        $this->assertNotNull($response->json('groups.0.uptime_percent'));
        $this->assertNotNull($response->json('groups.0.components.0.uptime_percent'));
        $this->assertStringContainsString('stale-if-error=86400', $response->headers->get('Cache-Control'));

        $etag = $response->headers->get('ETag');
        $this->travel(1)->minute();
        $this->withHeader('If-None-Match', $etag)
            ->getJson('/api/public/v1/history?from='.$from->toDateString().'&to='.$to->toDateString())
            ->assertStatus(304);
        $this->travelBack();
    }

    public function test_readiness_echoes_nonce_and_forbids_caching(): void
    {
        $response = $this->getJson('/api/readiness?nonce=probe-123')
            ->assertOk()
            ->assertJson(['ok' => true, 'nonce' => 'probe-123']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_history_rejects_invalid_or_reversed_ranges(): void
    {
        $this->seed(DemoSeeder::class);

        $this->getJson('/api/public/v1/history?from=not-a-date')->assertUnprocessable()->assertJsonValidationErrors(['from']);
        $this->getJson('/api/public/v1/history?from=2026-07-10&to=2026-07-01')->assertUnprocessable();
    }
}
