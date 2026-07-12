<?php

namespace Tests\Feature;

use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_read_but_only_admin_can_mutate_and_mutations_are_audited(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/admin/v1/overview')->assertOk()->assertJsonStructure([
            'components', 'monitors', 'agents', 'active_incidents', 'uptime_percent', 'recent_checks', 'recent_events', 'pending_outbox',
        ]);
        $this->getJson('/api/admin/v1/status-pages')->assertOk();
        $this->postJson('/api/admin/v1/status-pages', ['name' => 'Status', 'slug' => 'main'])->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $page = $this->postJson('/api/admin/v1/status-pages', ['name' => 'Status', 'slug' => 'main'])
            ->assertCreated()->json('data');

        $this->assertSame('main', $page['slug']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'create']);
    }

    public function test_monitor_frequency_is_limited_to_fifteen_seconds_through_twenty_four_hours(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'owner']));

        $this->postJson('/api/admin/v1/monitors', [
            'component_id' => 999,
            'name' => 'Too fast',
            'type' => 'http',
            'interval_seconds' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors(['component_id', 'interval_seconds']);
    }

    public function test_external_probe_url_uses_app_url_and_secrets_are_only_returned_once(): void
    {
        config(['app.url' => 'https://status.example.test']);
        Sanctum::actingAs(User::factory()->create(['role' => 'owner']));
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main']);

        $integration = $this->withHeader('Host', 'api:8000')->postJson('/api/admin/v1/laravel-integrations', [
            'status_page_id' => $page->id,
            'name' => 'Orders',
            'application_id' => 'orders-api',
        ])->assertCreated();
        $integration->assertJsonPath('endpoint', 'https://status.example.test/api/probe/v1/integrations/'.$integration->json('data.id').'/events');
        $this->assertNotEmpty($integration->json('secret_current'));

        $channel = $this->postJson('/api/admin/v1/notification-channels', [
            'status_page_id' => $page->id,
            'name' => 'Ops webhook',
            'type' => 'webhook',
            'config' => ['url' => 'https://hooks.example.test/status', 'secret' => 'client-must-not-control-this'],
        ])->assertCreated();
        $secret = $channel->json('webhook_secret');
        $this->assertNotEmpty($secret);
        $this->assertNotSame('client-must-not-control-this', $secret);

        $this->getJson('/api/admin/v1/notification-channels/'.$channel->json('data.id'))
            ->assertOk()
            ->assertJsonMissingPath('webhook_secret')
            ->assertJsonMissingPath('data.config');

        $email = $this->postJson('/api/admin/v1/notification-channels', [
            'status_page_id' => $page->id,
            'name' => 'Ops email',
            'type' => 'email',
            'config' => ['to' => ['ops@example.test']],
        ])->assertCreated();
        $this->patchJson('/api/admin/v1/notification-channels/'.$email->json('data.id'), [
            'type' => 'webhook',
            'config' => ['url' => 'https://hooks.example.test/converted'],
        ])->assertOk()->assertJsonStructure(['webhook_secret']);
    }
}
