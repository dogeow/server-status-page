#!/usr/bin/env python3
"""Report a fixed local systemd + UDP health check to a heartbeat monitor."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import secrets
import stat
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable


SYSTEMCTL = "/usr/bin/systemctl"
UDP_TABLES = ("/proc/net/udp", "/proc/net/udp6")
SERVICE_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.@-]{0,127}$")
HEARTBEAT_PATH_PATTERN = re.compile(r"^/api/probe/v1/heartbeat/[1-9][0-9]*$")
REQUIRED_CONFIG_KEYS = {"endpoint", "secret_file", "service", "timeout"}
OPTIONAL_CONFIG_KEYS = {"udp_port", "freshness_file", "freshness_max_age_seconds"}
CONFIG_KEYS = REQUIRED_CONFIG_KEYS | OPTIONAL_CONFIG_KEYS


class ConfigurationError(ValueError):
    """A deliberately detail-free configuration failure."""


class DeliveryError(RuntimeError):
    """A deliberately detail-free heartbeat transport failure."""


@dataclass(frozen=True)
class Config:
    endpoint: str
    secret_file: Path
    service: str
    timeout: float
    udp_port: int | None = None
    freshness_file: Path | None = None
    freshness_max_age_seconds: int | None = None


@dataclass(frozen=True)
class Health:
    service_active: bool
    udp_listener_active: bool | None = None
    freshness_current: bool | None = None

    @property
    def healthy(self) -> bool:
        return (
            self.service_active
            and self.udp_listener_active is not False
            and self.freshness_current is not False
        )

    @property
    def error_code(self) -> str | None:
        if self.healthy:
            return None
        failures = sum(
            check is False
            for check in (self.service_active, self.udp_listener_active, self.freshness_current)
        )
        if failures > 1:
            return "local_checks_failed"
        if not self.service_active:
            return "service_inactive"
        if self.udp_listener_active is False:
            return "udp_listener_missing"
        return "freshness_stale"

    def metrics(self) -> dict[str, bool]:
        # Do not include unit names, paths, ports, timestamps, command output, or errors.
        metrics = {"service_active": self.service_active}
        if self.udp_listener_active is not None:
            metrics["udp_listener_active"] = self.udp_listener_active
        if self.freshness_current is not None:
            metrics["freshness_current"] = self.freshness_current
        return metrics


class NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Fail on redirects so signed headers never leave the configured origin."""

    def redirect_request(self, _request, _file_pointer, _code, _message, _headers, _new_url):
        return None


def urlopen(request: urllib.request.Request, timeout: float):
    return urllib.request.build_opener(NoRedirectHandler()).open(request, timeout=timeout)


def _read_private_file(path: Path, *, owner_uid: int, max_bytes: int) -> bytes:
    """Read one owner-only regular file without following a final symlink."""

    if not path.is_absolute():
        raise ConfigurationError

    flags = os.O_RDONLY | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(path, flags)
    except OSError as exception:
        raise ConfigurationError from exception

    try:
        metadata = os.fstat(descriptor)
        if (
            not stat.S_ISREG(metadata.st_mode)
            or metadata.st_uid != owner_uid
            or stat.S_IMODE(metadata.st_mode) != 0o600
            or metadata.st_size > max_bytes
        ):
            raise ConfigurationError

        data = bytearray()
        while len(data) <= max_bytes:
            chunk = os.read(descriptor, min(4096, max_bytes + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
        if len(data) > max_bytes:
            raise ConfigurationError
        return bytes(data)
    finally:
        os.close(descriptor)


def load_config(path: Path, *, owner_uid: int) -> Config:
    raw = _read_private_file(path, owner_uid=owner_uid, max_bytes=16 * 1024)
    try:
        document = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exception:
        raise ConfigurationError from exception

    if (
        not isinstance(document, dict)
        or not REQUIRED_CONFIG_KEYS.issubset(document)
        or not set(document).issubset(CONFIG_KEYS)
        or ("freshness_file" in document) != ("freshness_max_age_seconds" in document)
    ):
        raise ConfigurationError

    endpoint = document["endpoint"]
    secret_file = document["secret_file"]
    service = document["service"]
    udp_port = document.get("udp_port")
    freshness_file = document.get("freshness_file")
    freshness_max_age_seconds = document.get("freshness_max_age_seconds")
    timeout = document["timeout"]
    if not all(isinstance(value, str) for value in (endpoint, secret_file, service)):
        raise ConfigurationError
    if udp_port is not None and (
        not isinstance(udp_port, int)
        or isinstance(udp_port, bool)
        or not 1 <= udp_port <= 65535
    ):
        raise ConfigurationError
    if freshness_file is not None and not isinstance(freshness_file, str):
        raise ConfigurationError
    if freshness_max_age_seconds is not None and (
        not isinstance(freshness_max_age_seconds, int)
        or isinstance(freshness_max_age_seconds, bool)
        or not 1 <= freshness_max_age_seconds <= 31_536_000
    ):
        raise ConfigurationError
    if not isinstance(timeout, (int, float)) or isinstance(timeout, bool) or not 0.1 <= float(timeout) <= 30:
        raise ConfigurationError
    if not SERVICE_PATTERN.fullmatch(service):
        raise ConfigurationError

    parsed = urllib.parse.urlsplit(endpoint)
    if (
        parsed.scheme != "https"
        or not parsed.hostname
        or parsed.username is not None
        or parsed.password is not None
        or parsed.query
        or parsed.fragment
        or not HEARTBEAT_PATH_PATTERN.fullmatch(parsed.path)
    ):
        raise ConfigurationError

    secret_path = Path(secret_file)
    if not secret_path.is_absolute():
        raise ConfigurationError
    freshness_path = Path(freshness_file) if freshness_file is not None else None
    if freshness_path is not None and not freshness_path.is_absolute():
        raise ConfigurationError

    return Config(
        endpoint=endpoint,
        secret_file=secret_path,
        service=service,
        timeout=float(timeout),
        udp_port=udp_port,
        freshness_file=freshness_path,
        freshness_max_age_seconds=freshness_max_age_seconds,
    )


def load_secret(path: Path, *, owner_uid: int) -> bytes:
    secret = _read_private_file(path, owner_uid=owner_uid, max_bytes=4096).strip()
    if not secret:
        raise ConfigurationError
    return secret


def systemd_service_active(service: str, timeout: float) -> bool:
    try:
        result = subprocess.run(
            [SYSTEMCTL, "is-active", "--quiet", "--", service],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
            timeout=timeout,
        )
    except (OSError, subprocess.SubprocessError):
        return False
    return result.returncode == 0


def parse_udp_table(lines: Iterable[str], port: int) -> bool:
    expected = f"{port:04X}"
    for line in lines:
        fields = line.split()
        if len(fields) < 4 or fields[0] == "sl":
            continue
        local_address = fields[1].rsplit(":", 1)
        if len(local_address) == 2 and local_address[1].upper() == expected and fields[3] == "07":
            return True
    return False


def udp_listener_active(port: int) -> bool:
    for table in UDP_TABLES:
        try:
            with open(table, "r", encoding="ascii", errors="ignore") as handle:
                if parse_udp_table(handle, port):
                    return True
        except OSError:
            continue
    return False


def freshness_file_current(path: Path, max_age_seconds: int, *, now: float | None = None) -> bool:
    """Check a regular file's mtime without reading its content or following it."""

    if not path.is_absolute():
        return False
    flags = (
        os.O_RDONLY
        | getattr(os, "O_CLOEXEC", 0)
        | getattr(os, "O_NOFOLLOW", 0)
        | getattr(os, "O_NONBLOCK", 0)
    )
    try:
        descriptor = os.open(path, flags)
    except OSError:
        return False
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode):
            return False
        age_seconds = (time.time() if now is None else now) - metadata.st_mtime
        return 0 <= age_seconds <= max_age_seconds
    except OSError:
        return False
    finally:
        os.close(descriptor)


def check_health(config: Config) -> Health:
    return Health(
        service_active=systemd_service_active(config.service, config.timeout),
        udp_listener_active=(
            udp_listener_active(config.udp_port)
            if config.udp_port is not None
            else None
        ),
        freshness_current=(
            freshness_file_current(config.freshness_file, config.freshness_max_age_seconds)
            if config.freshness_file is not None and config.freshness_max_age_seconds is not None
            else None
        ),
    )


def sign_body(secret: bytes, timestamp: int, nonce: str, body: bytes) -> str:
    body_hash = hashlib.sha256(body).hexdigest()
    canonical = f"{timestamp}\n{nonce}\n{body_hash}".encode("ascii")
    return hmac.new(secret, canonical, hashlib.sha256).hexdigest()


def encode_payload(health: Health, latency_ms: int, observed_at: datetime) -> bytes:
    payload: dict[str, Any] = {
        "status": "ok" if health.healthy else "failed",
        "observed_at": observed_at.astimezone(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z"),
        "latency_ms": max(0, latency_ms),
        "error_code": health.error_code,
        "metrics": health.metrics(),
    }
    return json.dumps(
        payload,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")


def send_heartbeat(
    endpoint: str,
    secret: bytes,
    body: bytes,
    timeout: float,
    *,
    timestamp: int | None = None,
    nonce: str | None = None,
) -> None:
    timestamp = int(time.time()) if timestamp is None else timestamp
    nonce = secrets.token_hex(16) if nonce is None else nonce
    signature = sign_body(secret, timestamp, nonce, body)
    request = urllib.request.Request(
        endpoint,
        data=body,
        method="POST",
        headers={
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent": "server-status-local-heartbeat/1",
            "X-Timestamp": str(timestamp),
            "X-Nonce": nonce,
            "X-Signature": signature,
        },
    )
    try:
        with urlopen(request, timeout=timeout) as response:
            status_code = response.getcode()
            if status_code is None or not 200 <= status_code < 300:
                raise DeliveryError
    except (urllib.error.URLError, OSError, TimeoutError) as exception:
        raise DeliveryError from exception


def run(config_path: Path) -> int:
    if os.geteuid() != 0:
        print("local-heartbeat: must run as root", file=sys.stderr)
        return 2

    try:
        config = load_config(config_path, owner_uid=0)
        secret = load_secret(config.secret_file, owner_uid=0)
    except ConfigurationError:
        print("local-heartbeat: configuration rejected", file=sys.stderr)
        return 2

    started = time.monotonic()
    health = check_health(config)
    latency_ms = round((time.monotonic() - started) * 1000)
    body = encode_payload(health, latency_ms, datetime.now(timezone.utc))
    try:
        send_heartbeat(config.endpoint, secret, body, config.timeout)
    except DeliveryError:
        print("local-heartbeat: delivery failed", file=sys.stderr)
        return 2

    if not health.healthy:
        print("local-heartbeat: local health check failed", file=sys.stderr)
        return 1
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Report a fixed local service heartbeat.")
    parser.add_argument("--config", required=True, type=Path, help="Path to the root-only JSON configuration file")
    arguments = parser.parse_args()
    return run(arguments.config)


if __name__ == "__main__":
    raise SystemExit(main())
