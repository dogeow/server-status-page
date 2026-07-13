from __future__ import annotations

import hashlib
import hmac
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import time
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest import mock


MODULE_PATH = Path(__file__).resolve().parents[1] / "local_heartbeat.py"
SPEC = importlib.util.spec_from_file_location("local_heartbeat", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
heartbeat = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = heartbeat
SPEC.loader.exec_module(heartbeat)


class ContextResponse:
    def __init__(self, status_code: int) -> None:
        self.status_code = status_code

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def getcode(self) -> int:
        return self.status_code


class LocalHeartbeatTest(unittest.TestCase):
    def test_signature_matches_control_plane_contract(self) -> None:
        body = b'{"status":"ok"}'
        timestamp = 1_725_000_000
        nonce = "00112233445566778899aabbccddeeff"
        secret = b"test-secret"
        canonical = f"{timestamp}\n{nonce}\n{hashlib.sha256(body).hexdigest()}".encode("ascii")

        self.assertEqual(
            hmac.new(secret, canonical, hashlib.sha256).hexdigest(),
            heartbeat.sign_body(secret, timestamp, nonce, body),
        )

    @mock.patch.object(heartbeat, "udp_listener_active", return_value=True)
    @mock.patch.object(heartbeat.subprocess, "run")
    def test_health_requires_active_service_and_udp_listener(self, run_mock, _udp_mock) -> None:
        run_mock.return_value = subprocess.CompletedProcess([], 0)
        config = heartbeat.Config(
            endpoint="https://status.example.com/api/probe/v1/heartbeat/123",
            secret_file=Path("/unused"),
            service="example.service",
            udp_port=12345,
            timeout=5.0,
        )

        result = heartbeat.check_health(config)

        self.assertTrue(result.healthy)
        self.assertIsNone(result.error_code)
        run_mock.assert_called_once_with(
            [heartbeat.SYSTEMCTL, "is-active", "--quiet", "--", "example.service"],
            stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
            timeout=5.0,
        )

    @mock.patch.object(heartbeat, "udp_listener_active", return_value=False)
    @mock.patch.object(heartbeat.subprocess, "run")
    def test_health_uses_fixed_sanitized_failure(self, run_mock, _udp_mock) -> None:
        run_mock.return_value = subprocess.CompletedProcess([], 3)
        config = heartbeat.Config(
            endpoint="https://status.example.com/api/probe/v1/heartbeat/123",
            secret_file=Path("/unused"),
            service="example.service",
            udp_port=12345,
            timeout=5.0,
        )

        result = heartbeat.check_health(config)

        self.assertFalse(result.healthy)
        self.assertEqual("local_checks_failed", result.error_code)
        self.assertEqual(
            {"service_active": False, "udp_listener_active": False},
            result.metrics(),
        )

    @mock.patch.object(heartbeat, "freshness_file_current", return_value=True)
    @mock.patch.object(heartbeat.subprocess, "run")
    def test_health_supports_freshness_without_udp(self, run_mock, freshness_mock) -> None:
        run_mock.return_value = subprocess.CompletedProcess([], 0)
        freshness_path = Path("/var/lib/example/last-update.db")
        config = heartbeat.Config(
            endpoint="https://status.example.com/api/probe/v1/heartbeat/123",
            secret_file=Path("/unused"),
            service="example.service",
            timeout=5.0,
            freshness_file=freshness_path,
            freshness_max_age_seconds=300,
        )

        result = heartbeat.check_health(config)

        self.assertTrue(result.healthy)
        self.assertEqual(
            {"service_active": True, "freshness_current": True},
            result.metrics(),
        )
        freshness_mock.assert_called_once_with(freshness_path, 300)

    def test_freshness_uses_regular_file_mtime_and_rejects_symlink(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            current_time = time.time()
            freshness_file = Path(directory) / "last-update.db"
            freshness_file.write_bytes(b"content-is-never-read")
            os.utime(freshness_file, (current_time - 30, current_time - 30))

            self.assertTrue(
                heartbeat.freshness_file_current(freshness_file, 60, now=current_time)
            )
            self.assertFalse(
                heartbeat.freshness_file_current(freshness_file, 10, now=current_time)
            )

            symlink = Path(directory) / "last-update-link"
            symlink.symlink_to(freshness_file)
            self.assertFalse(
                heartbeat.freshness_file_current(symlink, 60, now=current_time)
            )

    def test_freshness_config_is_optional_but_pair_is_atomic(self) -> None:
        base_config = {
            "endpoint": "https://status.example.com/api/probe/v1/heartbeat/123",
            "secret_file": "/etc/server-status-page/secrets/example.secret",
            "service": "example.service",
            "timeout": 5,
        }
        with tempfile.TemporaryDirectory() as directory:
            config_file = Path(directory) / "heartbeat.json"
            config_file.write_text(json.dumps(base_config), encoding="utf-8")
            config_file.chmod(0o600)

            config = heartbeat.load_config(config_file, owner_uid=os.geteuid())
            self.assertIsNone(config.udp_port)
            self.assertIsNone(config.freshness_file)

            config_file.write_text(
                json.dumps({**base_config, "freshness_file": "/var/lib/example/update.db"}),
                encoding="utf-8",
            )
            with self.assertRaises(heartbeat.ConfigurationError):
                heartbeat.load_config(config_file, owner_uid=os.geteuid())

    @mock.patch.object(heartbeat, "urlopen")
    def test_delivery_sends_signed_body_without_secret_header(self, urlopen_mock) -> None:
        urlopen_mock.return_value = ContextResponse(202)
        health = heartbeat.Health(service_active=True, udp_listener_active=True)
        body = heartbeat.encode_payload(health, 9, datetime(2026, 7, 14, tzinfo=timezone.utc))
        endpoint = "https://status.example.com/api/probe/v1/heartbeat/123"

        heartbeat.send_heartbeat(
            endpoint,
            b"test-secret",
            body,
            5.0,
            timestamp=1_725_000_000,
            nonce="00112233445566778899aabbccddeeff",
        )

        request = urlopen_mock.call_args.args[0]
        headers = {name.lower(): value for name, value in request.header_items()}
        self.assertEqual(endpoint, request.full_url)
        self.assertEqual(body, request.data)
        self.assertEqual(str(1_725_000_000), headers["x-timestamp"])
        self.assertEqual("00112233445566778899aabbccddeeff", headers["x-nonce"])
        self.assertEqual(
            heartbeat.sign_body(
                b"test-secret",
                1_725_000_000,
                "00112233445566778899aabbccddeeff",
                body,
            ),
            headers["x-signature"],
        )
        self.assertNotIn("test-secret", json.dumps(headers))
        urlopen_mock.assert_called_once_with(request, timeout=5.0)

    def test_private_file_requires_exact_owner_only_mode(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            private_file = Path(directory) / "secret"
            private_file.write_text("test-secret\n", encoding="utf-8")
            private_file.chmod(0o600)

            self.assertEqual(
                b"test-secret\n",
                heartbeat._read_private_file(private_file, owner_uid=os.geteuid(), max_bytes=128),
            )

            private_file.chmod(0o640)
            with self.assertRaises(heartbeat.ConfigurationError):
                heartbeat._read_private_file(private_file, owner_uid=os.geteuid(), max_bytes=128)

    def test_udp_table_parser_only_accepts_unconnected_local_socket(self) -> None:
        rows = [
            "sl local_address rem_address st tx_queue rx_queue",
            "0: 00000000:3039 00000000:0000 01 00000000:00000000",
            "1: 00000000:3039 00000000:0000 07 00000000:00000000",
        ]
        self.assertTrue(heartbeat.parse_udp_table(rows, 12345))
        self.assertFalse(heartbeat.parse_udp_table(rows, 12346))


if __name__ == "__main__":
    unittest.main()
