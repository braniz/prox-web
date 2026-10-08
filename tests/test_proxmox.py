import io
import json
import sys
import unittest
from pathlib import Path
from urllib.parse import urlsplit
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "python"))
import proxmox


class ProxmoxClientTests(unittest.TestCase):
    def setUp(self):
        self.config = {
            "host": "proxmox.example.test",
            "port": 8006,
            "token_id": "web@pam!readonly",
            "token_secret": "secret",
        }

    def test_fetches_cluster_nodes_and_resources_over_verified_https(self):
        payloads = [
            [{"type": "cluster", "name": "lab", "quorate": 1}],
            [{"node": "pve1", "status": "online"}],
            [{"type": "qemu", "vmid": 100, "name": "vm1"}],
        ]

        with patch(
            "proxmox.urlopen",
            side_effect=[io.BytesIO(json.dumps({"data": data}).encode()) for data in payloads],
        ) as open_url:
            result = proxmox.fetch_cluster_info(self.config)

        self.assertEqual(result["cluster"], payloads[0])
        self.assertEqual(result["nodes"], payloads[1])
        self.assertEqual(result["resources"], payloads[2])
        self.assertEqual(open_url.call_count, 3)
        request = open_url.call_args_list[0].args[0]
        url = urlsplit(request.full_url)
        self.assertEqual((url.scheme, url.hostname, url.port), ("https", "proxmox.example.test", 8006))
        self.assertEqual(url.path, "/api2/json/cluster/status")
        self.assertEqual(
            request.get_header("Authorization"),
            "PVEAPIToken=web@pam!readonly=secret",
        )

    def test_reports_missing_credentials_instead_of_returning_dummy_data(self):
        with self.assertRaisesRegex(proxmox.ProxmoxError, "Zugangsdaten fehlen"):
            proxmox.fetch_cluster_info({"host": "proxmox.example.test"})

    def test_rejects_invalid_host_before_requesting(self):
        with patch("proxmox.urlopen") as open_url:
            with self.assertRaisesRegex(proxmox.ProxmoxError, "gültigen Proxmox-Host"):
                proxmox.fetch_cluster_info({**self.config, "host": "https://example.test"})
        open_url.assert_not_called()

    def test_tls_enabled_by_default_and_uses_https(self):
        base_url, _ = proxmox._connection_details(self.config)
        self.assertTrue(base_url.startswith("https://"))

    def test_tls_no_uses_http(self):
        for value in (False, "nein", "0", "false"):
            base_url, _ = proxmox._connection_details({**self.config, "tls": value})
            self.assertTrue(base_url.startswith("http://"), value)

    def test_tls_yes_values_use_https(self):
        for value in (True, "ja", "1"):
            base_url, _ = proxmox._connection_details({**self.config, "tls": value})
            self.assertTrue(base_url.startswith("https://"), value)

    def test_invalid_tls_value_is_a_clear_error(self):
        with self.assertRaisesRegex(proxmox.ProxmoxError, "TLS-Einstellung"):
            proxmox._connection_details({**self.config, "tls": "vielleicht"})

    def test_main_reports_error_json_for_incomplete_config(self):
        with patch("sys.stdin", io.StringIO("{}")), patch("sys.stdout", new_callable=io.StringIO) as out:
            code = proxmox.main()
        self.assertEqual(code, 1)
        self.assertFalse(json.loads(out.getvalue())["success"])


if __name__ == "__main__":
    unittest.main()
