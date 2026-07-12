<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use StatusPage\LaravelProbe\Events\StatusProbeBroadcast;
use StatusPage\LaravelProbe\Security\HmacSigner;
use StatusPage\LaravelProbe\Tests\TestCase;

final class ReverbProbeTest extends TestCase
{
    #[Test]
    public function it_authenticates_and_dispatches_a_random_nonce_on_the_fixed_public_channel(): void
    {
        Event::fake([StatusProbeBroadcast::class]);
        $body = '{}';
        $headers = app(HmacSigner::class)->headers($body, time(), 'reverb_nonce_1234567890');

        $response = $this->call(
            'POST',
            '/health/reverb/probe',
            [],
            [],
            [],
            $this->serverHeaders($headers),
            $body,
        );

        $response
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('code', 'broadcast_triggered')
            ->assertJsonPath('channel', StatusProbeBroadcast::CHANNEL)
            ->assertJsonPath('event', StatusProbeBroadcast::EVENT);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $response->json('nonce'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        Event::assertDispatched(StatusProbeBroadcast::class, static function (StatusProbeBroadcast $event) use ($response): bool {
            return $event->nonce === $response->json('nonce')
                && $event->broadcastOn()->name === StatusProbeBroadcast::CHANNEL;
        });
    }

    #[Test]
    public function it_accepts_the_next_secret_during_rotation(): void
    {
        Event::fake([StatusProbeBroadcast::class]);
        $body = '{}';
        $nextOnly = new HmacSigner(null, 'next-test-secret');
        $headers = $nextOnly->headers($body, time(), 'rotation_nonce_123456789');

        $this->call(
            'POST',
            '/health/reverb/probe',
            [],
            [],
            [],
            $this->serverHeaders($headers),
            $body,
        )->assertOk();
    }

    #[Test]
    public function it_rejects_replayed_nonces(): void
    {
        Event::fake([StatusProbeBroadcast::class]);
        $body = '{}';
        $headers = app(HmacSigner::class)->headers($body, time(), 'replayed_nonce_123456789');
        $server = $this->serverHeaders($headers);

        $this->call('POST', '/health/reverb/probe', [], [], [], $server, $body)->assertOk();
        $this->call('POST', '/health/reverb/probe', [], [], [], $server, $body)
            ->assertStatus(409)
            ->assertJsonPath('code', 'replayed_nonce');
    }

    #[Test]
    public function it_rejects_expired_or_tampered_signatures(): void
    {
        $body = '{}';
        $expired = app(HmacSigner::class)->headers($body, time() - 301, 'expired_nonce_123456789');

        $this->call('POST', '/health/reverb/probe', [], [], [], $this->serverHeaders($expired), $body)
            ->assertStatus(401)
            ->assertJsonPath('code', 'expired_signature');

        $tampered = app(HmacSigner::class)->headers($body, time(), 'tampered_nonce_12345678');
        $this->call('POST', '/health/reverb/probe', [], [], [], $this->serverHeaders($tampered), '{"changed":true}')
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_signature');
    }

    #[Test]
    public function it_fails_closed_when_the_replay_store_is_unavailable(): void
    {
        Event::fake([StatusProbeBroadcast::class]);
        config()->set('status-probe.security.nonce_cache_store', 'missing-store');
        $body = '{}';
        $headers = app(HmacSigner::class)->headers($body, time(), 'cache_failure_nonce_12345');

        $this->call(
            'POST',
            '/health/reverb/probe',
            [],
            [],
            [],
            $this->serverHeaders($headers),
            $body,
        )
            ->assertStatus(503)
            ->assertJsonPath('code', 'probe_auth_unavailable');

        Event::assertNotDispatched(StatusProbeBroadcast::class);
    }
}
