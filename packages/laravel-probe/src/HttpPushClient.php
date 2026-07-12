<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Http;
use StatusPage\LaravelProbe\Contracts\PushClient;
use StatusPage\LaravelProbe\Security\HmacSigner;
use StatusPage\LaravelProbe\Support\SafeIdentifier;
use StatusPage\LaravelProbe\Support\SafeLogger;
use Throwable;

final class HttpPushClient implements PushClient
{
    public function __construct(
        private readonly HmacSigner $signer,
        private readonly SafeLogger $logger,
    ) {}

    public function send(string $event, array $payload = [], ?DateTimeInterface $occurredAt = null): bool
    {
        if (! (bool) config('status-probe.enabled', true)) {
            return false;
        }

        $endpoint = config('status-probe.push.endpoint');

        if (! is_string($endpoint) || trim($endpoint) === '' || ! $this->signer->hasSecrets()) {
            return false;
        }

        $safeEvent = SafeIdentifier::make($event, 'event');

        try {
            $body = json_encode([
                'event' => $safeEvent,
                'occurred_at' => ($occurredAt ?? new DateTimeImmutable)->format(DateTimeInterface::RFC3339_EXTENDED),
                'application' => [
                    'id' => SafeIdentifier::make((string) config('status-probe.application.id', 'laravel'), 'app'),
                    'environment' => SafeIdentifier::make((string) config('status-probe.application.environment', 'production'), 'env'),
                    'instance_id' => $this->instanceId(),
                ],
                'payload' => $payload === [] ? (object) [] : $payload,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            $this->logger->warning('serialization_failed', [
                'event' => $safeEvent,
                'exception' => $exception::class,
            ]);

            return false;
        }

        try {
            $headers = array_merge([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'status-page-laravel-probe/1',
            ], $this->signer->headers($body));
        } catch (Throwable $exception) {
            $this->logger->warning('signing_failed', [
                'event' => $safeEvent,
                'exception' => $exception::class,
            ]);

            return false;
        }

        try {
            $response = Http::withHeaders($headers)
                // Laravel 10 accepts integer timeouts; later versions also accept
                // floats. Whole seconds keep one implementation compatible with
                // every supported major.
                ->connectTimeout(max(1, (int) ceil((float) config('status-probe.push.connect_timeout_seconds', 1.0))))
                ->timeout(max(1, (int) ceil((float) config('status-probe.push.timeout_seconds', 2.0))))
                ->withOptions(['verify' => (bool) config('status-probe.push.verify_tls', true)])
                ->withBody($body, 'application/json')
                ->post($endpoint);

            if ($response->successful()) {
                return true;
            }

            $this->logger->warning('http_rejected', [
                'event' => $safeEvent,
                'status' => $response->status(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('transport_failed', [
                'event' => $safeEvent,
                'exception' => $exception::class,
            ]);
        }

        return false;
    }

    private function instanceId(): ?string
    {
        $configured = config('status-probe.application.instance_id');

        if (is_string($configured) && trim($configured) !== '') {
            return SafeIdentifier::make($configured, 'instance');
        }

        $hostname = gethostname();

        return is_string($hostname) && $hostname !== ''
            ? SafeIdentifier::make($hostname, 'instance')
            : null;
    }
}
