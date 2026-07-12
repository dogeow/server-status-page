<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use StatusPage\LaravelProbe\Security\HmacSigner;
use StatusPage\LaravelProbe\Tests\TestCase;

final class HmacSignerTest extends TestCase
{
    #[Test]
    public function signatures_cover_the_timestamp_nonce_and_exact_body_hash(): void
    {
        $signer = new HmacSigner('current-secret', 'next-secret');
        $body = '{"ok":true}';
        $headers = $signer->headers($body, 1_700_000_000, 'nonce_1234567890123456');

        self::assertSame(implode("\n", [
            'STATUS-PROBE-HMAC-SHA256-V1',
            '1700000000',
            'nonce_1234567890123456',
            hash('sha256', $body),
        ]), $signer->canonical(
            1_700_000_000,
            'nonce_1234567890123456',
            hash('sha256', $body),
        ));

        self::assertTrue($signer->verify(
            $body,
            1_700_000_000,
            $headers[HmacSigner::NONCE_HEADER],
            $headers[HmacSigner::BODY_HASH_HEADER],
            [
                $headers[HmacSigner::SIGNATURE_HEADER],
                $headers[HmacSigner::NEXT_SIGNATURE_HEADER],
            ],
        ));
        self::assertFalse($signer->verify(
            '{"ok":false}',
            1_700_000_000,
            $headers[HmacSigner::NONCE_HEADER],
            $headers[HmacSigner::BODY_HASH_HEADER],
            [$headers[HmacSigner::SIGNATURE_HEADER]],
        ));
    }
}
