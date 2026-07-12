<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\NotificationChannel;
use App\Models\NotificationPolicy;
use App\Models\OutboxEvent;
use App\Models\StatusPage;
use App\Services\IncidentReminderService;
use App\Services\OutboxProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_repeat_interval_quiet_hours_and_recovery_bypass_are_enforced(): void
    {
        CarbonImmutable::setTestNow('2026-07-11 12:00:00 UTC');
        config(['broadcasting.default' => 'null']);
        Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true])]);
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main']);
        $group = ComponentGroup::query()->create(['status_page_id' => $page->id, 'name' => 'API', 'slug' => 'api']);
        $component = Component::query()->create(['component_group_id' => $group->id, 'name' => 'API', 'slug' => 'api']);
        $incident = Incident::query()->create(['status_page_id' => $page->id, 'title' => 'API down', 'status' => 'investigating', 'impact' => 'major_outage', 'started_at' => now()->subMinutes(61), 'is_public' => true]);
        $incident->components()->attach($component->id);
        $channel = NotificationChannel::query()->create(['status_page_id' => $page->id, 'name' => 'Ops', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/status', 'secret' => 'secret']]);
        $policy = NotificationPolicy::query()->create([
            'status_page_id' => $page->id,
            'notification_channel_id' => $channel->id,
            'name' => 'Repeat',
            'events' => ['incident.created'],
            'repeat_minutes' => 60,
        ]);

        $this->assertSame(1, app(IncidentReminderService::class)->schedule());
        app(OutboxProcessor::class)->process();
        Http::assertSentCount(1);
        $this->assertSame(0, app(IncidentReminderService::class)->schedule(), 'A fresh delivery must enforce repeat_minutes.');

        $policy->update(['quiet_hours' => ['start' => '11:00', 'end' => '13:00', 'timezone' => 'UTC']]);
        CarbonImmutable::setTestNow('2026-07-11 13:01:00 UTC');
        $this->assertSame(1, app(IncidentReminderService::class)->schedule());
        OutboxEvent::query()->where('type', 'incident.reminder')->whereNull('processed_at')->delete();
        CarbonImmutable::setTestNow('2026-07-12 12:00:00 UTC');
        $this->assertSame(0, app(IncidentReminderService::class)->schedule(), 'Quiet hours suppress reminders.');

        OutboxEvent::query()->create([
            'type' => 'incident.resolved',
            'aggregate_type' => 'incident',
            'aggregate_id' => (string) $incident->id,
            'payload' => ['status_page_id' => $page->id, 'incident_id' => $incident->id, 'title' => $incident->title, 'component_ids' => [$component->id]],
            'available_at' => now(),
        ]);
        app(OutboxProcessor::class)->process();
        Http::assertSentCount(2);
    }

    public function test_active_maintenance_suppresses_repeat_notifications_for_existing_incident(): void
    {
        CarbonImmutable::setTestNow('2026-07-11 12:00:00 UTC');
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main']);
        $group = ComponentGroup::query()->create(['status_page_id' => $page->id, 'name' => 'API', 'slug' => 'api']);
        $component = Component::query()->create(['component_group_id' => $group->id, 'name' => 'API', 'slug' => 'api']);
        $incident = Incident::query()->create(['status_page_id' => $page->id, 'title' => 'API down', 'status' => 'investigating', 'impact' => 'major_outage', 'started_at' => now()->subHours(2), 'is_public' => true]);
        $incident->components()->attach($component->id);
        $window = MaintenanceWindow::query()->create(['status_page_id' => $page->id, 'name' => 'Deploy', 'status' => 'in_progress', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]);
        $window->components()->attach($component->id);
        $channel = NotificationChannel::query()->create(['status_page_id' => $page->id, 'name' => 'Ops', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/status', 'secret' => 'secret']]);
        NotificationPolicy::query()->create(['status_page_id' => $page->id, 'notification_channel_id' => $channel->id, 'name' => 'Repeat', 'events' => ['incident.created'], 'repeat_minutes' => 60]);

        $this->assertSame(0, app(IncidentReminderService::class)->schedule());
        $this->assertDatabaseMissing('outbox_events', ['type' => 'incident.reminder']);
    }
}
