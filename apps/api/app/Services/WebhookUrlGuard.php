<?php

namespace App\Services;

use RuntimeException;

class WebhookUrlGuard
{
    /**
     * Resolve a webhook URL and pin delivery to a public address.
     *
     * @return array{url: string, resolve: string}
     */
    public function pin(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Webhook URL must use http or https.');
        }
        if (in_array(strtolower($host), ['localhost', 'metadata.google.internal'], true)) {
            throw new RuntimeException('Webhook URL host is not allowed.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $addresses = $this->addresses($host);
        foreach ($addresses as $address) {
            if ($this->blocked($address)) {
                throw new RuntimeException('Webhook URL resolves to a blocked address.');
            }
        }

        $pinned = $addresses[0];
        $literal = str_contains($pinned, ':') ? "[{$pinned}]" : $pinned;

        return [
            'url' => $url,
            'resolve' => "{$host}:{$port}:{$literal}",
        ];
    }

    /** @return list<string> */
    private function addresses(string $host): array
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            return [$literal];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        $addresses = [];
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        if ($addresses === []) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                $addresses = $fallback;
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('Webhook URL host could not be resolved.');
        }

        return array_values(array_unique($addresses));
    }

    private function blocked(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
