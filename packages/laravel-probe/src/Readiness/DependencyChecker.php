<?php

declare(strict_types=1);

namespace StatusPage\LaravelProbe\Readiness;

use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;
use StatusPage\LaravelProbe\Support\SafeIdentifier;
use StatusPage\LaravelProbe\Support\SafeLogger;
use Throwable;

final class DependencyChecker
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly CacheManager $cache,
        private readonly SafeLogger $logger,
    ) {}

    /**
     * @return array{ready: bool, checks: array<int, array{type: string, id: string, status: string, code: string}>}
     */
    public function check(): array
    {
        $checks = [];

        foreach ((array) config('status-probe.readiness.databases', []) as $id => $definition) {
            [$publicId, $connection] = $this->databaseDefinition($id, $definition);
            $checks[] = $this->checkDatabase($publicId, $connection);
        }

        foreach ((array) config('status-probe.readiness.caches', []) as $id => $definition) {
            [$publicId, $store] = $this->cacheDefinition($id, $definition);
            $checks[] = $this->checkCache($publicId, $store);
        }

        return [
            'ready' => ! in_array('failed', array_column($checks, 'status'), true),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{type: string, id: string, status: string, code: string}
     */
    private function checkDatabase(string $publicId, ?string $connection): array
    {
        try {
            // A constant SELECT verifies the actual configured connection without
            // mutating application data.
            $this->database->connection($connection)->selectOne('SELECT 1 AS status_probe_ready');

            return $this->result('database', $publicId, true, 'ok');
        } catch (Throwable $exception) {
            $this->logger->warning('database_unavailable', [
                'target' => $publicId,
                'exception' => $exception::class,
            ]);

            return $this->result('database', $publicId, false, 'database_unavailable');
        }
    }

    /**
     * @return array{type: string, id: string, status: string, code: string}
     */
    private function checkCache(string $publicId, ?string $store): array
    {
        try {
            // Reading a guaranteed-missing, namespaced key exercises the selected
            // cache store without writing or deleting caller data.
            $key = 'status-probe:readiness:'.hash('sha256', $publicId);
            $this->cache->store($store)->get($key);

            return $this->result('cache', $publicId, true, 'ok');
        } catch (Throwable $exception) {
            $this->logger->warning('cache_unavailable', [
                'target' => $publicId,
                'exception' => $exception::class,
            ]);

            return $this->result('cache', $publicId, false, 'cache_unavailable');
        }
    }

    /**
     * @return array{string, string|null}
     */
    private function databaseDefinition(int|string $id, mixed $definition): array
    {
        if (is_array($definition)) {
            return [
                $this->publicId($id, 'database'),
                $this->nullableName($definition['connection'] ?? null),
            ];
        }

        return [
            $this->publicId($id, 'database'),
            $this->nullableName($definition),
        ];
    }

    /**
     * @return array{string, string|null}
     */
    private function cacheDefinition(int|string $id, mixed $definition): array
    {
        if (is_array($definition)) {
            return [
                $this->publicId($id, 'cache'),
                $this->nullableName($definition['store'] ?? null),
            ];
        }

        return [
            $this->publicId($id, 'cache'),
            $this->nullableName($definition),
        ];
    }

    private function publicId(int|string $id, string $type): string
    {
        return is_int($id)
            ? $type.'_'.$id
            : SafeIdentifier::make($id, $type);
    }

    private function nullableName(mixed $name): ?string
    {
        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * @return array{type: string, id: string, status: string, code: string}
     */
    private function result(string $type, string $id, bool $ok, string $code): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'status' => $ok ? 'ok' : 'failed',
            'code' => $code,
        ];
    }
}
