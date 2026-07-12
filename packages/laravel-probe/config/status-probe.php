<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('STATUS_PROBE_ENABLED', true),

    'application' => [
        'id' => env('STATUS_PROBE_APP_ID', env('APP_NAME', 'laravel')),
        'environment' => env('STATUS_PROBE_ENVIRONMENT', env('APP_ENV', 'production')),
        'instance_id' => env('STATUS_PROBE_INSTANCE_ID', null),
    ],

    'push' => [
        'endpoint' => env('STATUS_PROBE_PUSH_URL', null),
        'timeout_seconds' => (float) env('STATUS_PROBE_PUSH_TIMEOUT', 2.0),
        'connect_timeout_seconds' => (float) env('STATUS_PROBE_CONNECT_TIMEOUT', 1.0),
        'verify_tls' => (bool) env('STATUS_PROBE_VERIFY_TLS', true),
    ],

    // Both secrets are used during a zero-downtime rotation. Outbound requests
    // carry a signature for each configured secret; inbound requests accept either.
    'secrets' => [
        'current' => env('STATUS_PROBE_SECRET_CURRENT', null),
        'next' => env('STATUS_PROBE_SECRET_NEXT', null),
    ],

    'routes' => [
        'enabled' => (bool) env('STATUS_PROBE_ROUTES_ENABLED', true),
        'prefix' => env('STATUS_PROBE_ROUTE_PREFIX', 'health'),
        'middleware' => [],
    ],

    'readiness' => [
        // Give each dependency a public-safe id. Connection and store names are
        // never returned by the endpoint.
        'databases' => [
            // 'primary' => ['connection' => null],
        ],
        'caches' => [
            // 'primary' => ['store' => null],
        ],
    ],

    'queues' => [
        // 'default' => ['connection' => null, 'queue' => 'default'],
    ],

    'scheduler' => [
        'enabled' => (bool) env('STATUS_PROBE_SCHEDULER_ENABLED', true),
        'queue_probes_enabled' => (bool) env('STATUS_PROBE_QUEUE_PROBES_ENABLED', true),
        'on_one_server' => (bool) env('STATUS_PROBE_SCHEDULER_ONE_SERVER', false),
    ],

    'reverb' => [
        'enabled' => (bool) env('STATUS_PROBE_REVERB_ENABLED', true),
        'rate_limit_per_minute' => (int) env('STATUS_PROBE_REVERB_RATE_LIMIT', 10),
    ],

    'security' => [
        'timestamp_tolerance_seconds' => (int) env('STATUS_PROBE_TIMESTAMP_TOLERANCE', 300),
        'nonce_cache_store' => env('STATUS_PROBE_NONCE_CACHE_STORE', null),
    ],

    'logging' => [
        'enabled' => (bool) env('STATUS_PROBE_LOGGING_ENABLED', true),
        'channel' => env('STATUS_PROBE_LOG_CHANNEL', null),
    ],
];
