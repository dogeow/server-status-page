<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StatusPage\LaravelProbe\Readiness\DependencyChecker;
use StatusPage\LaravelProbe\Security\HmacSigner;

final class ReadinessController
{
    public function __invoke(Request $request, DependencyChecker $checker): JsonResponse
    {
        $nonce = $this->nonce($request->header(HmacSigner::NONCE_HEADER));
        $result = $checker->check();

        return response()->json([
            'status' => $result['ready'] ? 'ready' : 'not_ready',
            'code' => $result['ready'] ? 'ready' : 'dependency_unavailable',
            'probe_nonce' => $nonce,
            'checked_at' => now()->toISOString(),
            'checks' => $result['checks'],
        ], $result['ready'] ? 200 : 503)->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Vary' => HmacSigner::NONCE_HEADER,
            HmacSigner::NONCE_HEADER => $nonce,
        ]);
    }

    private function nonce(?string $requested): string
    {
        if (is_string($requested) && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $requested)) {
            return $requested;
        }

        return bin2hex(random_bytes(16));
    }
}
