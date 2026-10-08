import io
import json
import sys
import unittest
from pathlib import Path
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
        self.assertTrue(request.full_url.startswith("https://proxmox.example.test:8006/"))
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


if __name__ == "__main__":
    unittest.main()
