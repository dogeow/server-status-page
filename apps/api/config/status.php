<?php

return [
    'agent_signature_ttl' => (int) env('AGENT_SIGNATURE_TTL', 300),
    'agent_offline_after_seconds' => (int) env('AGENT_OFFLINE_AFTER_SECONDS', 180),
    'raw_result_retention_days' => (int) env('RESULT_RETENTION_DAYS', 30),
    'rollup_retention_months' => (int) env('STATUS_ROLLUP_RETENTION_MONTHS', 13),
    'public_history_days' => 90,
    'webhook_timeout_seconds' => (int) env('WEBHOOK_TIMEOUT_SECONDS', 10),
    'scheduler_cache_store' => env('SCHEDULE_CACHE_STORE', 'database'),
];
