<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\DailyRollup;
use App\Models\OutboxEvent;
use App\Models\StatusPage;
use App\Models\Subscriber;
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
            ->assertJsonStructure(['groups' => [['components' => [['uptime_percent', 'latency_ms', 'daily_history']]]], 'incidents', 'maintenances']);

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

    public function test_subscription_requires_double_opt_in_and_can_be_confirmed(): void
    {
        $this->seed(DemoSeeder::class);

        $this->postJson('/api/public/v1/subscriptions', ['email' => 'ops@example.com', 'page' => 'main'])
            ->assertAccepted();

        $subscriber = Subscriber::query()->firstOrFail();
        $this->assertNull($subscriber->confirmed_at);
        $event = OutboxEvent::query()->where('type', 'subscriber.confirmation_requested')->firstOrFail();
        $token = basename($event->payload['confirmation_url']);

        $this->getJson('/api/public/v1/subscriptions/confirm/'.$token)->assertOk();
        $this->assertNotNull($subscriber->fresh()->confirmed_at);
        $this->assertNull($subscriber->fresh()->confirmation_token_hash);
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
