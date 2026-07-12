<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use StatusPage\LaravelProbe\HttpPushClient;
use StatusPage\LaravelProbe\Security\HmacSigner;
use StatusPage\LaravelProbe\Support\SafeLogger;
use StatusPage\LaravelProbe\Tests\TestCase;

final class HttpPushClientTest extends TestCase
{
    #[Test]
    public function it_signs_the_exact_body_with_current_and_next_secrets(): void
    {
        config()->set('status-probe.push.endpoint', 'https://control.example.test/v1/probe-events');
        Http::fake([
            'https://control.example.test/*' => Http::response([], 202),
        ]);
        $signer = new HmacSigner('current-test-secret', 'next-test-secret');
        $client = new HttpPushClient($signer, app(SafeLogger::class));

        self::assertTrue($client->send('scheduler.tick', ['tick_id' => 'tick-1']));

        Http::assertSent(function (Request $request) use ($signer): bool {
            $body = $request->body();
            $timestamp = (int) $request->header(HmacSigner::TIMESTAMP_HEADER)[0];
            $nonce = $request->header(HmacSigner::NONCE_HEADER)[0];
            $bodyHash = $request->header(HmacSigner::BODY_HASH_HEADER)[0];
            $signatures = [
                $request->header(HmacSigner::SIGNATURE_HEADER)[0],
                $request->header(HmacSigner::NEXT_SIGNATURE_HEADER)[0],
            ];
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return $request->url() === 'https://control.example.test/v1/probe-events'
                && $decoded['event'] === 'scheduler.tick'
                && hash_equals(hash('sha256', $body), $bodyHash)
                && $signer->verify($body, $timestamp, $nonce, $bodyHash, $signatures);
        });
    }

    #[Test]
    public function it_returns_false_instead_of_throwing_when_the_control_plane_rejects_the_event(): void
    {
        config()->set('status-probe.push.endpoint', 'https://control.example.test/v1/probe-events');
        Http::fake([
            'https://control.example.test/*' => Http::response([], 503),
        ]);
        $client = new HttpPushClient(
            new HmacSigner('current-test-secret', null),
            app(SafeLogger::class),
        );

        self::assertFalse($client->send('scheduler.tick'));
    }

    #[Test]
    public function it_is_a_noop_when_the_endpoint_or_secret_is_not_configured(): void
    {
        Http::fake();
        config()->set('status-probe.push.endpoint', null);
        $client = new HttpPushClient(new HmacSigner(null, null), app(SafeLogger::class));

        self::assertFalse($client->send('scheduler.tick'));
        Http::assertNothingSent();
    }
}
