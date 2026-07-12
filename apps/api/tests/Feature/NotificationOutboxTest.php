<?php

namespace Tests\Feature;

use App\Events\PublicStatusChanged;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\NotificationChannel;
use App\Models\NotificationPolicy;
use App\Models\OutboxEvent;
use App\Models\StatusPage;
use App\Services\OutboxProcessor;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_delivery_has_stable_id_and_hmac_signature(): void
    {
        $broadcasting = \Mockery::mock(BroadcastingFactory::class);
        $broadcasting->shouldReceive('queue')->once()->andThrow(new \RuntimeException('Reverb unavailable'));
        $this->app->instance(BroadcastingFactory::class, $broadcasting);
        Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true])]);
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main']);
        $group = ComponentGroup::query()->create(['status_page_id' => $page->id, 'name' => 'API', 'slug' => 'api']);
        $component = Component::query()->create(['component_group_id' => $group->id, 'name' => 'Web', 'slug' => 'web']);
        $incident = Incident::query()->create(['status_page_id' => $page->id, 'title' => 'API unavailable', 'impact' => 'major_outage', 'started_at' => now(), 'is_public' => true]);
        $incident->components()->attach($component->id);
        $channel = NotificationChannel::query()->create([
            'status_page_id' => $page->id,
            'name' => 'Ops webhook',
            'type' => 'webhook',
            'config' => ['url' => 'https://hooks.example.test/status', 'secret' => 'webhook-secret'],
        ]);
        NotificationPolicy::query()->create([
            'status_page_id' => $page->id,
            'notification_channel_id' => $channel->id,
            'name' => 'Incidents',
            'events' => ['incident.created'],
        ]);
        OutboxEvent::query()->create([
            'type' => 'incident.created',
            'aggregate_type' => 'incident',
            'aggregate_id' => (string) $incident->id,
            'payload' => ['status_page_id' => $page->id, 'incident_id' => $incident->id, 'title' => $incident->title, 'severity' => $incident->impact],
            'available_at' => now(),
        ]);

        $this->assertSame(1, app(OutboxProcessor::class)->process());
        $this->assertSame(0, app(OutboxProcessor::class)->process());

        Http::assertSent(function (Request $request) use ($component): bool {
            $signature = $request->header('X-Status-Signature')[0] ?? '';
            $delivery = $request->header('X-Status-Delivery')[0] ?? '';
            $payload = $request->data();

            return $request->url() === 'https://hooks.example.test/status'
                && $delivery !== ''
                && $payload['delivery_id'] === $delivery
                && $payload['event_type'] === 'incident.created'
                && $payload['severity'] === 'major_outage'
                && $payload['summary'] === 'API unavailable'
                && $payload['components'][0]['id'] === $component->id
                && isset($payload['occurred_at'])
                && hash_equals('sha256='.hash_hmac('sha256', $request->body(), 'webhook-secret'), $signature);
        });
        Http::assertSentCount(1);
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'delivered', 'notification_channel_id' => $channel->id]);
        $this->assertNotNull(OutboxEvent::query()->sole()->processed_at);
    }

    public function test_public_broadcast_does_not_expose_agent_identity(): void
    {
        Event::fake([PublicStatusChanged::class]);
        $page = StatusPage::query()->create(['name' => 'Status', 'slug' => 'main']);
        OutboxEvent::query()->create([
            'type' => 'agent.offline',
            'aggregate_type' => 'agent',
            'aggregate_id' => (string) Str::uuid(),
            'payload' => [
                'status_page_id' => $page->id,
                'agent_id' => (string) Str::uuid(),
                'name' => 'private-edge-hostname',
                'last_seen_at' => now()->toIso8601String(),
                'component_ids' => [10, 11],
            ],
            'available_at' => now(),
        ]);

        $this->assertSame(1, app(OutboxProcessor::class)->process());
        Event::assertDispatched(PublicStatusChanged::class, fn (PublicStatusChanged $event): bool => $event->eventType === 'agent.offline'
            && $event->payload['component_ids'] === [10, 11]
            && ! isset($event->payload['agent_id'])
            && ! isset($event->payload['name'])
            && ! isset($event->payload['last_seen_at']));
    }
}
