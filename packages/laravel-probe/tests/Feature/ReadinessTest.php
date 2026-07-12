<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use StatusPage\LaravelProbe\Security\HmacSigner;
use StatusPage\LaravelProbe\Tests\TestCase;

final class ReadinessTest extends TestCase
{
    #[Test]
    public function it_checks_configured_dependencies_and_echoes_a_valid_probe_nonce(): void
    {
        config()->set('status-probe.readiness.databases', [
            'primary-db' => ['connection' => 'testing'],
        ]);
        config()->set('status-probe.readiness.caches', [
            'primary-cache' => ['store' => 'array'],
        ]);

        $nonce = 'readiness_nonce_123456';
        $response = $this->getJson('/health/ready', [HmacSigner::NONCE_HEADER => $nonce]);

        $response
            ->assertOk()
            ->assertHeader(HmacSigner::NONCE_HEADER, $nonce)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('code', 'ready')
            ->assertJsonPath('probe_nonce', $nonce)
            ->assertJsonPath('checks.0.code', 'ok')
            ->assertJsonPath('checks.1.code', 'ok');

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString(HmacSigner::NONCE_HEADER, (string) $response->headers->get('Vary'));
    }

    #[Test]
    public function it_returns_only_sanitized_codes_when_a_dependency_fails(): void
    {
        config()->set('status-probe.readiness.databases', [
            'primary-db' => ['connection' => 'very-sensitive-connection-name'],
        ]);

        $response = $this->getJson('/health/ready');

        $response
            ->assertStatus(503)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('code', 'dependency_unavailable')
            ->assertJsonPath('checks.0.id', 'primary-db')
            ->assertJsonPath('checks.0.code', 'database_unavailable');

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('very-sensitive-connection-name', $response->getContent());
        self::assertStringNotContainsString('exception', strtolower($response->getContent()));
    }

    #[Test]
    public function it_generates_a_fresh_nonce_instead_of_echoing_an_invalid_value(): void
    {
        $response = $this->getJson('/health/ready', [HmacSigner::NONCE_HEADER => 'bad']);

        $response->assertOk();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $response->json('probe_nonce'));
        self::assertNotSame('bad', $response->json('probe_nonce'));
    }
}
