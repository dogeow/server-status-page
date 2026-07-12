<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Security;

final class HmacSigner
{
    public const TIMESTAMP_HEADER = 'X-Status-Probe-Timestamp';

    public const NONCE_HEADER = 'X-Status-Probe-Nonce';

    public const BODY_HASH_HEADER = 'X-Status-Probe-Content-SHA256';

    public const SIGNATURE_HEADER = 'X-Status-Probe-Signature';

    public const NEXT_SIGNATURE_HEADER = 'X-Status-Probe-Signature-Next';

    private ?string $currentSecret;

    private ?string $nextSecret;

    public function __construct(?string $currentSecret, ?string $nextSecret = null)
    {
        $this->currentSecret = $this->normalizeSecret($currentSecret);
        $this->nextSecret = $this->normalizeSecret($nextSecret);
    }

    public function hasSecrets(): bool
    {
        return $this->currentSecret !== null || $this->nextSecret !== null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $body, ?int $timestamp = null, ?string $nonce = null): array
    {
        if (! $this->hasSecrets()) {
            return [];
        }

        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', $body);
        $headers = [
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::NONCE_HEADER => $nonce,
            self::BODY_HASH_HEADER => $bodyHash,
        ];

        if ($this->currentSecret !== null) {
            $headers[self::SIGNATURE_HEADER] = $this->sign(
                $this->currentSecret,
                $timestamp,
                $nonce,
                $bodyHash,
            );

            if ($this->nextSecret !== null) {
                $headers[self::NEXT_SIGNATURE_HEADER] = $this->sign(
                    $this->nextSecret,
                    $timestamp,
                    $nonce,
                    $bodyHash,
                );
            }

            return $headers;
        }

        // During the final rotation step, the next secret can temporarily be
        // the only configured value and therefore becomes the primary header.
        $headers[self::SIGNATURE_HEADER] = $this->sign(
            (string) $this->nextSecret,
            $timestamp,
            $nonce,
            $bodyHash,
        );

        return $headers;
    }

    /**
     * @param  array<int, string|null>  $providedSignatures
     */
    public function verify(
        string $body,
        int $timestamp,
        string $nonce,
        string $bodyHash,
        array $providedSignatures,
    ): bool {
        $calculatedHash = hash('sha256', $body);

        if (! hash_equals($calculatedHash, strtolower($bodyHash))) {
            return false;
        }

        $secrets = array_values(array_filter([
            $this->currentSecret,
            $this->nextSecret,
        ], static fn (?string $secret): bool => $secret !== null));

        foreach ($providedSignatures as $provided) {
            $normalized = $this->normalizeSignature($provided);

            if ($normalized === null) {
                continue;
            }

            foreach ($secrets as $secret) {
                if (hash_equals($this->sign($secret, $timestamp, $nonce, $calculatedHash), $normalized)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function sign(string $secret, int $timestamp, string $nonce, string $bodyHash): string
    {
        return 'sha256='.hash_hmac(
            'sha256',
            $this->canonical($timestamp, $nonce, strtolower($bodyHash)),
            $secret,
        );
    }

    public function canonical(int $timestamp, string $nonce, string $bodyHash): string
    {
        return implode("\n", [
            'STATUS-PROBE-HMAC-SHA256-V1',
            (string) $timestamp,
            $nonce,
            strtolower($bodyHash),
        ]);
    }

    private function normalizeSecret(?string $secret): ?string
    {
        if ($secret === null || trim($secret) === '') {
            return null;
        }

        return $secret;
    }

    private function normalizeSignature(?string $signature): ?string
    {
        if ($signature === null) {
            return null;
        }

        $signature = strtolower(trim($signature));

        if (! preg_match('/^sha256=[a-f0-9]{64}$/', $signature)) {
            return null;
        }

        return $signature;
    }
}
