<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Http\Middleware;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StatusPage\LaravelProbe\Security\HmacSigner;
use StatusPage\LaravelProbe\Support\SafeLogger;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class VerifyProbeSignature
{
    public function __construct(
        private readonly HmacSigner $signer,
        private readonly CacheManager $cache,
        private readonly SafeLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->signer->hasSecrets()) {
            $this->logger->warning('hmac_unconfigured');

            return $this->failure('probe_auth_unavailable', 503);
        }

        $timestampHeader = $request->header(HmacSigner::TIMESTAMP_HEADER);
        $nonce = $request->header(HmacSigner::NONCE_HEADER);
        $bodyHash = $request->header(HmacSigner::BODY_HASH_HEADER);

        if (
            ! is_string($timestampHeader)
            || ! preg_match('/^[0-9]{1,13}$/', $timestampHeader)
            || ! is_string($nonce)
            || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)
            || ! is_string($bodyHash)
            || ! preg_match('/^[a-fA-F0-9]{64}$/', $bodyHash)
        ) {
            return $this->failure('invalid_signature', 401);
        }

        $timestamp = (int) $timestampHeader;
        $tolerance = max(1, (int) config('status-probe.security.timestamp_tolerance_seconds', 300));

        if (abs(time() - $timestamp) > $tolerance) {
            return $this->failure('expired_signature', 401);
        }

        if (! $this->signer->verify(
            $request->getContent(),
            $timestamp,
            $nonce,
            $bodyHash,
            [
                $request->header(HmacSigner::SIGNATURE_HEADER),
                $request->header(HmacSigner::NEXT_SIGNATURE_HEADER),
            ],
        )) {
            return $this->failure('invalid_signature', 401);
        }

        try {
            $configuredStore = config('status-probe.security.nonce_cache_store');
            $store = is_string($configuredStore) && $configuredStore !== '' ? $configuredStore : null;
            $reserved = $this->cache->store($store)->add(
                'status-probe:hmac-nonce:'.hash('sha256', $nonce),
                true,
                $tolerance * 2,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('nonce_store_unavailable', ['exception' => $exception::class]);

            return $this->failure('probe_auth_unavailable', 503);
        }

        if (! $reserved) {
            return $this->failure('replayed_nonce', 409);
        }

        return $next($request);
    }

    private function failure(string $code, int $status): JsonResponse
    {
        return response()->json([
            'status' => 'rejected',
            'code' => $code,
        ], $status)->withHeaders([
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }
}
