# Laravel Status Probe

`status-page/laravel-probe` adds deliberately small, fail-open probes to a Laravel application:

- a dynamic `GET /health/ready` endpoint with optional database and cache dependencies;
- one no-op queue canary for every configured connection/queue pair;
- a one-minute scheduler heartbeat and helpers for critical task success/failure;
- an HMAC-protected `POST /health/reverb/probe` endpoint that immediately broadcasts a random nonce on a fixed public channel;
- one HMAC-SHA256 client for all outbound telemetry, with current/next secret rotation.

Probe failures never throw into a web request, scheduled business task, or queue canary. Logs contain fixed codes and safe identifiers only; transport payloads, URLs, exception messages, credentials, and connection names are not logged.

## Requirements

- PHP `^8.1`
- Laravel 10, 11, 12, or 13

Composer selects the compatible Laravel/PHP pair. Laravel 10 is the PHP 8.1 baseline; newer Laravel releases retain their own higher PHP constraints. Scheduling is registered only when the detected Laravel major version is 10 through 13.

## Installation

```bash
composer require status-page/laravel-probe
php artisan vendor:publish --tag=status-probe-config
```

Laravel package discovery registers `StatusProbeServiceProvider` and the `StatusProbe` facade. For a local monorepo before publishing, add a Composer `path` repository pointing at `packages/laravel-probe`.

Create a Laravel Integration in the Status Control admin first. It returns one
absolute endpoint and the current secret exactly once. Configure those returned
values in the monitored application:

```dotenv
STATUS_PROBE_APP_ID=orders-api
STATUS_PROBE_INSTANCE_ID=orders-api-01
STATUS_PROBE_PUSH_URL=https://status-control.example.com/api/probe/v1/integrations/<integration-uuid>/events
STATUS_PROBE_SECRET_CURRENT=replace-with-a-random-32-byte-or-longer-secret
STATUS_PROBE_PUSH_TIMEOUT=2
STATUS_PROBE_CONNECT_TIMEOUT=1
```

The client performs one short request and never retries inline. A missing endpoint/secret, timeout, DNS failure, TLS failure, or non-2xx response returns `false` without affecting the caller.

## Readiness

Configure public-safe dependency IDs in `config/status-probe.php`:

```php
'readiness' => [
    'databases' => [
        'primary-db' => ['connection' => 'mysql'],
        'analytics-db' => ['connection' => 'pgsql'],
    ],
    'caches' => [
        'application-cache' => ['store' => 'redis'],
    ],
],
```

`GET /health/ready` runs a constant `SELECT 1` on each database and a read of a guaranteed-missing namespaced key on each cache store. It never writes application data. The response is `200 ready` or `503 not_ready`, carries `Cache-Control: no-store`, and contains only fixed codes such as `database_unavailable` or `cache_unavailable`.

To prove the response is fresh, a monitor may send a 16–128 character nonce:

```bash
curl -H 'X-Status-Probe-Nonce: probe_01JABCDEF123456789' \
  https://app.example.com/health/ready
```

The valid nonce is echoed in the header and `probe_nonce` response field. Without one, or with an invalid value, the endpoint generates a fresh random nonce.

Connection/store names, exception messages, DSNs, hosts, and stack traces are never returned.

## Queue canaries

List every actual connection and queue that must be independently proven:

```php
'queues' => [
    // The array key is the public-safe target ID sent to the control plane.
    'mail-critical' => ['connection' => 'redis', 'queue' => 'mail'],
    'exports-low' => ['connection' => 'database', 'queue' => 'exports'],
],
```

Each minute the package dispatches one `StatusProbeJob` to each pair. The job has `tries = 1`, performs no application/database/cache work, and reports:

- `queue.enqueued` after Laravel's bus accepts it;
- `queue.started` when a worker begins it, including queue wait time;
- `queue.completed` at the end;
- `queue.dispatch_failed` or `queue.failed` with a fixed code when applicable.

Only the public target ID is transmitted. Actual Laravel connection and queue names remain local.

## Scheduler heartbeat and critical tasks

The provider registers `status-probe:scheduler-heartbeat` every minute and, when queues are configured, `status-probe:queue-canaries` every minute. The application must already run Laravel's scheduler, for example:

```cron
* * * * * cd /srv/app && php artisan schedule:run >> /dev/null 2>&1
```

In an active-active deployment, either keep per-instance heartbeats or enable:

```dotenv
STATUS_PROBE_SCHEDULER_ONE_SERVER=true
```

`onOneServer()` requires a shared, lock-capable cache. It is disabled by default so a cache outage cannot suppress all scheduler heartbeats.

Wrap a critical scheduled task to report its terminal state while preserving its return value and exception behavior:

```php
use Illuminate\Support\Facades\Schedule;
use StatusPage\LaravelProbe\Facades\StatusProbe;

Schedule::call(fn () => StatusProbe::trackScheduledTask(
    'billing.close',
    fn () => app(\App\Actions\CloseBillingPeriod::class)->run(),
    ['region' => 'cn'],
))->daily();
```

Or report around an existing workflow:

```php
StatusProbe::scheduledTaskSucceeded('search.reindex', ['documents' => 120]);
StatusProbe::scheduledTaskFailed('search.reindex', 'index_timeout');

status_probe()->schedulerTick(['region' => 'cn']);
$result = status_probe_task('reports.daily', fn () => build_daily_report());
```

Optional metadata is limited to 20 scalar values, string values are truncated, and sensitive-looking keys such as `password`, `secret`, `token`, `dsn`, and `credential` are dropped.

## Reverb end-to-end probe

The monitor first subscribes to the fixed public channel `status-probe.public`, then sends an HMAC-authenticated request to:

```text
POST /health/reverb/probe
```

The route is rate-limited and replay protected. It generates a random 256-bit nonce and dispatches `StatusProbeBroadcast`, which implements `ShouldBroadcastNow`, under event name `status-probe.nonce`. The response contains the same nonce. A successful deep probe observes that nonce on Reverb before its timeout.

For Laravel Echo, listen with the explicit event alias:

```js
Echo.channel('status-probe.public')
  .listen('.status-probe.nonce', ({ nonce, sent_at }) => {
    // Match nonce against the signed trigger response.
  });
```

The route does not expose Reverb credentials or accept a caller-selected broadcast payload. Disable it when unused:

```dotenv
STATUS_PROBE_REVERB_ENABLED=false
```

## HMAC protocol and rotation

All outbound telemetry and inbound Reverb triggers use the exact raw HTTP body. Compute:

```text
body_hash = lowercase_hex(SHA256(raw_body))

canonical = "STATUS-PROBE-HMAC-SHA256-V1\n"
          + unix_timestamp + "\n"
          + nonce + "\n"
          + body_hash

signature = "sha256=" + lowercase_hex(HMAC_SHA256(secret, canonical))
```

Headers:

```text
X-Status-Probe-Timestamp: <unix seconds>
X-Status-Probe-Nonce: <16-128 URL-safe characters>
X-Status-Probe-Content-SHA256: <64 lowercase hex characters>
X-Status-Probe-Signature: sha256=<64 lowercase hex characters>
X-Status-Probe-Signature-Next: sha256=<optional rotation signature>
```

Inbound timestamps default to a ±300 second window. Accepted nonces are atomically reserved in the configured cache store for twice that window; if the nonce store is unavailable, the protected route fails closed with `probe_auth_unavailable`.

Zero-downtime rotation:

1. Put the old secret in `STATUS_PROBE_SECRET_CURRENT` and the new secret in `STATUS_PROBE_SECRET_NEXT` on both sides.
2. Deploy and confirm either signature is accepted. Outbound pushes carry both signatures.
3. Promote the new secret to `CURRENT` and clear `NEXT` on both sides.

Use a shared nonce cache when multiple application instances serve the trigger route.

## Outbound event envelope

The push endpoint receives JSON in this shape:

```json
{
  "event": "scheduler.tick",
  "occurred_at": "2026-07-11T12:00:00.000+08:00",
  "application": {
    "id": "orders-api",
    "environment": "production",
    "instance_id": "orders-api-01"
  },
  "payload": {}
}
```

Custom low-risk events are available through `StatusProbe::push($event, $payload)`. Do not pass customer or credential data.

## Route and logging configuration

The default paths are `/health/ready` and `/health/reverb/probe`. Change the common prefix or attach application middleware:

```php
'routes' => [
    'enabled' => true,
    'prefix' => 'health',
    'middleware' => [],
],
```

The Reverb route always retains the package's named throttle and HMAC middleware. Readiness remains suitable for load balancers.

Sanitized warnings can use a dedicated channel:

```dotenv
STATUS_PROBE_LOG_CHANNEL=stack
STATUS_PROBE_LOGGING_ENABLED=true
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

The Orchestra Testbench suite covers readiness/no-store behavior, sanitized failures, current/next HMAC validation, timestamp and replay rejection, Reverb nonce dispatch, queue lifecycle events, one-minute schedule registration, critical task reporting, and fail-open push behavior.
