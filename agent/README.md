# Status Agent

`status-agent` is the outbound-only probe process for the status service. It enrolls once, polls a versioned plan every 15 seconds with `ETag`, schedules checks locally with ±10% jitter, and durably spools results in SQLite until the control plane accepts them.

## Run

```bash
docker build -t status-agent ./agent
docker run --read-only --restart unless-stopped \
  -e STATUS_AGENT_SERVER_URL=https://status.example.com \
  -e STATUS_AGENT_ENROLLMENT_TOKEN=replace-once \
  -v status-agent-data:/var/lib/status-agent \
  status-agent
```

The state volume contains `credentials.json` (mode `0600`), the last accepted `plan.json` (mode `0600`), and the bounded WAL-mode `spool.db`. Preserve it across upgrades. The cached plan lets probes continue after an agent restart while the control plane is temporarily unreachable. Deleting `credentials.json` requires issuing a new one-time enrollment token in the control plane.

Configuration is read from `/etc/status-agent/config.yaml`; `STATUS_AGENT_SERVER_URL`, `STATUS_AGENT_ENROLLMENT_TOKEN`, `STATUS_AGENT_NAME`, `STATUS_AGENT_STATE_DIR`, `STATUS_AGENT_SECRET_FILE_ROOT`, and `STATUS_AGENT_LOG_LEVEL` override YAML values. See [`config.example.yaml`](config.example.yaml).

## Control-plane protocol

Enrollment sends `POST /api/agent/v1/enroll`. Authenticated requests include:

```text
X-Agent-Id: <agent id>
X-Timestamp: <unix seconds>
X-Nonce: <random 16-byte hex>
X-Signature: hex(HMAC-SHA256(agent_secret, timestamp + "\n" + nonce + "\n" + hex(SHA256(raw_body))))
```

The agent requests `GET /api/agent/v1/plan` with `If-None-Match`, posts liveness to `/api/agent/v1/heartbeat`, and uploads batches to `/api/agent/v1/results/batch`. A monitor plan entry has this common shape:

```json
{
  "id": 42,
  "type": "http",
  "enabled": true,
  "interval_seconds": 60,
  "timeout_ms": 5000,
  "connect_timeout_ms": 2000,
  "slow_threshold_ms": 1000,
  "config_version": "7",
  "config": { "url": "https://app.example.com/readiness" }
}
```

Intervals are clamped to 15 seconds–24 hours for scheduler safety. The default is 60 seconds. Only one execution of a monitor can be in flight, and the process-wide concurrency limit is configurable. Failed, timed-out, authentication, configuration, and unknown results wake the uploader immediately; successful results are batched. Idempotency is based on `monitor_id + agent_id + scheduled_at + config_version`.

## Typed probe configurations

There is deliberately no command or shell probe. Every supported probe has a fixed, validated configuration:

- `http`, `https`, `nextjs`, `laravel`: `url`; optional `method`, `headers`, `body`, `expected_status`, `keyword`, `json_path`, `json_equals`, `require_nonce`, `nonce_response_header`, `insecure_skip_verify`. A `{{nonce}}` URL placeholder automatically enables nonce mode. `nextjs` and `laravel` always force nonce mode even when the flag is omitted. The nonce is also sent as `X-Status-Nonce` and `X-Status-Probe-Nonce`, then an exact response-header or body echo is required. Same-origin redirects are allowed; origin-changing redirects are rejected so custom authentication headers cannot leave the configured target.
- `tcp`: `address` (`host:port`); optional `send`, `expect`, `read_bytes`.
- `dns`: `name`; optional `server` (`host:port`) and `expected_ips` (all must be returned).
- `tls`: `address`, optional `server_name`, `min_valid_days`, `insecure_skip_verify`.
- `squid`: `proxy_url` and controlled HTTPS `canary_url`; optional `username`, `password`, `insecure_skip_verify`. Proxy and direct-control requests run in parallel with the same nonce so a canary outage becomes `unknown`, not a Squid outage.
- `mysql`: either `dsn`, or `host` and `user`; structured mode also accepts `port`, `password`, `database`, `tls_mode`.
- `postgresql`: either `dsn`, or `host` and `user`; structured mode also accepts `port`, `password`, `database`, `tls_mode`.
- `redis`: either `url`, `address`, or `host` plus optional `port`; optional `username`, `password`, `database`, `tls`, `insecure_tls`, `mode`, `capability_write`, `key_prefix`. Capability/read-write mode performs a short-lived `SET/GET/DEL` canary.
- `reverb` / `pusher`: `url` and `trigger_url`; optional `app_key`, `origin`, `channel` (defaults to `status-probe.public`), `event`, `trigger_headers`, `trigger_secret`, `trigger_secret_next`, `insecure_skip_verify`. It validates connection, subscription, synchronous trigger, and receipt of the broadcast nonce. `origin` must match Reverb's allowed-origin list; when omitted it is derived from the WebSocket URL (`ws`→`http`, `wss`→`https`). Trigger secrets generate the versioned HMAC headers expected by `packages/laravel-probe` and support two-key rotation.
- `heartbeat` / `push` / `laravel_queue` / `laravel_scheduler`: passive monitor types. They are not actively scheduled by the agent; the Laravel integration reports their signed heartbeats.

`tls_mode` accepts `required` (default), `preferred`, `insecure`, or `disabled`.

Sensitive values in any monitor `config` can be replaced with an object containing one `secretRef`:

```json
{
  "host": "db.internal",
  "user": "status_probe",
  "password": { "secretRef": "env://STATUS_DB_PASSWORD" }
}
```

`file:///run/secrets/name` is also supported. If `secret_file_root` is set, file references outside it are rejected. Resolved values are remembered by the log redactor and never included in result messages. Probe responses are bounded to 1 MiB, errors are normalized, and credentials are never returned by plan or heartbeat APIs.

## Development

```bash
go test -race ./...
go vet ./...
```

Tests cover canonical signing, plan scheduler jitter/concurrency, SQLite deduplication and bounds, secret resolution/redaction, HTTP nonce and JSON assertions, TCP round trips, database configuration validation, and rejection of shell probes.
