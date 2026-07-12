<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReadinessTest extends TestCase
{
    public function test_readiness_echoes_query_nonce_without_cache(): void
    {
        $response = $this->getJson('/api/readiness?nonce=query-nonce');

        $response
            ->assertOk()
            ->assertHeader('X-Status-Nonce', 'query-nonce')
            ->assertJsonPath('nonce', 'query-nonce');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_readiness_accepts_agent_nonce_header(): void
    {
        $this->getJson('/api/readiness', ['X-Status-Probe-Nonce' => 'header-nonce'])
            ->assertOk()
            ->assertHeader('X-Status-Nonce', 'header-nonce')
            ->assertJsonPath('nonce', 'header-nonce');
    }
}
