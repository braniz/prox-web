import base64
import io
import json
import ssl
import subprocess
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
        context = open_url.call_args_list[0].kwargs["context"]
        self.assertTrue(context.check_hostname)
        self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)

    def test_certificate_verification_can_be_disabled_for_https(self):
        payloads = [io.BytesIO(json.dumps({"data": []}).encode()) for _ in range(3)]
        with patch("proxmox.urlopen", side_effect=payloads) as open_url:
            proxmox.fetch_cluster_info({**self.config, "verify_certificate": False})

        for call in open_url.call_args_list:
            context = call.kwargs["context"]
            self.assertFalse(context.check_hostname)
            self.assertEqual(context.verify_mode, ssl.CERT_NONE)

    def test_missing_certificate_verification_setting_defaults_to_enabled(self):
        payloads = [io.BytesIO(json.dumps({"data": []}).encode()) for _ in range(3)]
        with patch("proxmox.urlopen", side_effect=payloads) as open_url:
            proxmox.fetch_cluster_info(self.config)

        context = open_url.call_args_list[0].kwargs["context"]
        self.assertTrue(context.check_hostname)
        self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)

    def test_certificate_verification_is_not_used_for_http(self):
        payloads = [io.BytesIO(json.dumps({"data": []}).encode()) for _ in range(3)]
        with patch("proxmox.urlopen", side_effect=payloads) as open_url:
            proxmox.fetch_cluster_info({**self.config, "tls": False, "verify_certificate": False})

        self.assertEqual(open_url.call_args.kwargs, {"timeout": 10})

    def test_invalid_certificate_verification_setting_is_a_clear_error(self):
        with patch("proxmox.urlopen") as open_url:
            with self.assertRaisesRegex(proxmox.ProxmoxError, "Zertifikatsprüfung"):
                proxmox.fetch_cluster_info({**self.config, "verify_certificate": "maybe"})
        open_url.assert_not_called()

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

    def test_extract_resource_ip_handles_common_proxmox_fields(self):
        resource = {
            "type": "qemu",
            "name": "vm1",
            "ip-address": "10.0.0.42",
            "net0": "virtio=00:11:22:33:44:55,bridge=vmbr0,ip=dhcp",
        }
        self.assertEqual(proxmox.extract_resource_ip(resource), "10.0.0.42")

        resource2 = {"type": "lxc", "name": "ct1", "ip_addresses": ["192.168.1.10", "fe80::1"]}
        self.assertEqual(proxmox.extract_resource_ip(resource2), "192.168.1.10")

        resource3 = {
            "type": "qemu",
            "name": "vm2",
            "guest_agent": {
                "result": [
                    {"name": "eth0", "ip-address": "10.0.0.99"},
                    {"name": "eth1", "ip-addresses": ["fe80::9", "192.168.2.15"]},
                ]
            },
        }
        self.assertEqual(proxmox.extract_resource_ip(resource3), "10.0.0.99, 192.168.2.15")

    def test_extract_qemu_and_lxc_guest_info_helpers_work_like_reference(self):
        qemu = {
            "result": [
                {"name": "lo", "ip-addresses": [{"ip-address": "127.0.0.1/8"}]},
                {"name": "eth0", "ip-addresses": [{"ip-address": "10.0.0.42/24"}, {"ip-address": "fe80::1/64"}]},
            ]
        }
        self.assertEqual(proxmox.extract_qemu_ips(qemu), ["10.0.0.42"])
        self.assertEqual(proxmox.extract_qemu_hostname({"result": {"host-name": "web01"}}), "web01")

        lxc = {
            "result": [
                {"name": "lo", "inet": "127.0.0.1/8", "inet6": "::1/128"},
                {"name": "eth0", "inet": "192.168.1.25/24", "inet6": "fd00::25/64"},
            ]
        }
        self.assertEqual(proxmox.extract_lxc_ips(lxc), ["192.168.1.25", "fd00::25"])

    def test_fetch_guest_detail_uses_qm_config_for_stable_detail_table(self):
        osinfo = {"id": "ubuntu", "pretty-name": "Ubuntu 24.04.5 LTS", "version": "24.04.5 LTS (Noble Numbat)", "kernel-release": "6.8.0-146-generic", "kernel-version": "#146-Ubuntu SMP PREEMPT_DYNAMIC Thu Sep  3 16:12:30 UTC 2026", "machine": "x86_64"}
        users = [{"user": "root", "domain": None, "login-time": 1720000000000000000}, {"user": "admin", "domain": None, "login-time": 1720000001000000000}]
        time_value = 1720000000000000000
        interfaces = {"result": [{"name": "lo", "hardware-address": "00:00:00:00:00:00", "ip-addresses": [{"ip-address-type": "ipv4", "ip-address": "127.0.0.1"}]}, {"name": "eth0", "hardware-address": "00:11:22:33:44:55", "ip-addresses": [{"ip-address-type": "ipv4", "ip-address": "192.168.1.10/24"}]}]}
        fsinfo = {"result": [{"mountpoint": "/", "type": "ext4", "total": 2147483648, "used": 1073741824}]}
        payloads = [
            {"data": {"name": "test-gui", "memory": 2048, "cores": 1, "agent": 1}},
            {"data": {"status": "running", "memory": 2048, "name": "test-gui"}},
            {"data": {"status": "running", "maxmem": 2097152, "mem": 1048576, "cpus": 2, "cpu": 12.3, "maxdisk": 4294967296, "disk": 1073741824}},
            {"data": {"result": []}},
            {"data": interfaces},
            {"data": fsinfo},
            {"data": osinfo},
            {"data": users},
            {"data": time_value},
        ]
        with patch(
            "proxmox.urlopen",
            side_effect=[
                io.BytesIO(json.dumps(payloads[0]).encode()),
                io.BytesIO(json.dumps(payloads[1]).encode()),
                io.BytesIO(json.dumps(payloads[2]).encode()),
                io.BytesIO(json.dumps(payloads[3]).encode()),
                io.BytesIO(json.dumps(payloads[4]).encode()),
                io.BytesIO(json.dumps(payloads[5]).encode()),
                io.BytesIO(json.dumps(payloads[6]).encode()),
                io.BytesIO(json.dumps(payloads[7]).encode()),
                io.BytesIO(json.dumps(payloads[8]).encode()),
            ],
        ) as open_url:
            result = proxmox.fetch_guest_detail(self.config, {"node": "pve1", "vmid": 101, "type": "qemu"})

        self.assertEqual(result["proxmox"]["memory"], 2048)
        self.assertEqual(result["proxmox"]["name"], "test-gui")
        self.assertIn("eth0", result["guest_cmds"]["network"])
        self.assertIn("/", result["guest_cmds"]["fsinfo"])
        self.assertIn("Ubuntu", result["guest_cmds"]["osinfo"])
        self.assertIn("root", result["guest_cmds"]["users"])
        self.assertIn("2024", result["guest_cmds"]["time"])
        self.assertEqual(open_url.call_count, 9)

    def test_fetch_guest_detail_collects_qm_guest_cmd_outputs(self):
        osinfo = {"id": "ubuntu", "pretty-name": "Ubuntu 24.04.5 LTS", "version": "24.04.5 LTS (Noble Numbat)", "kernel-release": "6.8.0-146-generic", "kernel-version": "#146-Ubuntu SMP PREEMPT_DYNAMIC Thu Sep  3 16:12:30 UTC 2026", "machine": "x86_64"}
        users = [{"user": "root", "domain": None, "login-time": 1720000000000000000}, {"user": "admin", "domain": None, "login-time": 1720000001000000000}]
        time_value = 1720000000000000000
        interfaces = {"result": [{"name": "lo", "hardware-address": "00:00:00:00:00:00", "ip-addresses": [{"ip-address-type": "ipv4", "ip-address": "127.0.0.1"}]}, {"name": "eth0", "hardware-address": "00:11:22:33:44:55", "ip-addresses": [{"ip-address-type": "ipv4", "ip-address": "192.168.1.10/24"}]}]}
        fsinfo = {"result": [{"mountpoint": "/", "type": "ext4", "total": 2147483648, "used": 1073741824}]}
        payloads = [
            {"data": {"name": "test-gui", "memory": 2048}},
            {"data": {"status": "running", "memory": 2048, "name": "test-gui"}},
            {"data": {"status": "running", "maxmem": 2097152, "mem": 1048576, "cpus": 2, "cpu": 12.3, "maxdisk": 4294967296, "disk": 1073741824}},
            {"data": {"result": []}},
            {"data": interfaces},
            {"data": fsinfo},
            {"data": osinfo},
            {"data": users},
            {"data": time_value},
        ]
        with patch(
            "proxmox.urlopen",
            side_effect=[
                io.BytesIO(json.dumps(payloads[0]).encode()),
                io.BytesIO(json.dumps(payloads[1]).encode()),
                io.BytesIO(json.dumps(payloads[2]).encode()),
                io.BytesIO(json.dumps(payloads[3]).encode()),
                io.BytesIO(json.dumps(payloads[4]).encode()),
                io.BytesIO(json.dumps(payloads[5]).encode()),
                io.BytesIO(json.dumps(payloads[6]).encode()),
                io.BytesIO(json.dumps(payloads[7]).encode()),
                io.BytesIO(json.dumps(payloads[8]).encode()),
            ],
        ) as open_url:
            result = proxmox.fetch_guest_detail(self.config, {"node": "pve1", "vmid": 101, "type": "qemu"})

        self.assertIn("eth0", result["guest_cmds"]["network"])
        self.assertIn("/", result["guest_cmds"]["fsinfo"])
        self.assertIn("Ubuntu", result["guest_cmds"]["osinfo"])
        self.assertIn("root", result["guest_cmds"]["users"])
        self.assertIn("2024", result["guest_cmds"]["time"])
        self.assertEqual(open_url.call_count, 9)

    def test_qemu_file_read_falls_back_to_qm_guest_cmd(self):
        def fake_run(args, capture_output=None, text=None, timeout=None, check=None):
            self.assertEqual(args[0], "qm")
            self.assertEqual(args[1], "guest")
            self.assertEqual(args[2], "cmd")
            self.assertEqual(args[3], "101")
            if args[4] == "file-open":
                self.assertEqual(json.loads(args[5]), {"path": "/srv/info/host.info", "mode": "r"})
                return subprocess.CompletedProcess(args, 0, stdout='{"fd": 7}', stderr='')
            if args[4] == "file-read":
                self.assertEqual(json.loads(args[5]), {"fd": 7, "offset": 0, "count": 65536})
                return subprocess.CompletedProcess(args, 0, stdout='{"data": "server-42\\n"}', stderr='')
            if args[4] == "file-close":
                self.assertEqual(json.loads(args[5]), {"fd": 7})
                return subprocess.CompletedProcess(args, 0, stdout='{}', stderr='')
            raise AssertionError(f"Unexpected command: {args!r}")

        with patch("proxmox.subprocess.run", side_effect=fake_run):
            result = proxmox._qm_guest_cmd_file_read("pve1", 101, "/srv/info/host.info")

        self.assertEqual(result, "server-42")

    def test_fetch_guest_detail_uses_runtime_lxc_network_addresses_when_available(self):
        payloads = [
            {"data": {"name": "ct-web", "memory": 2048, "cores": 1, "type": "lxc"}},
            {"data": {"status": "running", "maxmem": 2097152, "mem": 1048576, "cpus": 2, "cpu": 12.3, "maxdisk": 4294967296, "disk": 1073741824}},
            {"data": {"net0": "name=eth0,bridge=vmbr0,ip=dhcp"}},
        ]
        with patch("proxmox.urlopen", side_effect=[
            io.BytesIO(json.dumps(payloads[0]).encode()),
            io.BytesIO(json.dumps(payloads[1]).encode()),
            io.BytesIO(json.dumps(payloads[2]).encode()),
        ]):
            with patch("proxmox.fetch_guest_command", return_value="2: eth0@if38: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500\n    link/ether bc:24:11:4e:76:0f brd ff:ff:ff:ff:ff:ff\n    inet 192.168.100.226/16 metric 1024 brd 192.168.255.255 scope global dynamic eth0\n    inet6 fe80::be24:11ff:fe4e:760f/64 scope link proto kernel_ll\n"):
                result = proxmox.fetch_guest_detail(self.config, {"node": "pve1", "vmid": 102, "type": "lxc"})

        self.assertIn("192.168.100.226", result["guest_cmds"]["network"])
        self.assertNotIn("fe80::be24:11ff:fe4e:760f", result["guest_cmds"]["network"])
        self.assertEqual(result["guest_agent"][0]["ip-addresses"][0], "192.168.100.226")

    def test_fetch_guest_detail_uses_verbose_qm_status_for_cpu_ram_and_disk(self):
        interfaces = {"result": [{"name": "eth0", "hardware-address": "00:11:22:33:44:55", "ip-addresses": [{"ip-address-type": "ipv4", "ip-address": "192.168.1.10/24"}]}]}
        payloads = [
            {"data": {"name": "test-gui", "memory": 2048, "cores": 1, "agent": 1}},
            {"data": {"status": "running", "maxmem": 2097152, "mem": 1048576, "cpus": 2, "cpu": 12.3, "maxdisk": 4294967296, "disk": 1073741824}},
            {"data": {"status": "running", "maxmem": 2097152, "mem": 1048576, "cpus": 2, "cpu": 12.3, "maxdisk": 4294967296, "disk": 1073741824}},
            {"data": {"result": []}},
            {"data": interfaces},
            {"data": {"result": [{"mountpoint": "/", "type": "ext4", "total": 2147483648, "used": 1073741824}]}},
            {"data": {"id": "ubuntu", "pretty-name": "Ubuntu 24.04.5 LTS"}},
            {"data": [{"user": "root"}]},
            {"data": 1720000000000000000},
        ]
        with patch(
            "proxmox.urlopen",
            side_effect=[
                io.BytesIO(json.dumps(payloads[0]).encode()),
                io.BytesIO(json.dumps(payloads[1]).encode()),
                io.BytesIO(json.dumps(payloads[2]).encode()),
                io.BytesIO(json.dumps(payloads[3]).encode()),
                io.BytesIO(json.dumps(payloads[4]).encode()),
                io.BytesIO(json.dumps(payloads[5]).encode()),
                io.BytesIO(json.dumps(payloads[6]).encode()),
                io.BytesIO(json.dumps(payloads[7]).encode()),
                io.BytesIO(json.dumps(payloads[8]).encode()),
            ],
        ):
            result = proxmox.fetch_guest_detail(self.config, {"node": "pve1", "vmid": 101, "type": "qemu"})

        self.assertEqual(result["qm_info"]["maxmem"], 2097152)
        self.assertEqual(result["qm_info"]["mem"], 1048576)
        self.assertEqual(result["qm_info"]["cpus"], 2)
        self.assertEqual(result["qm_info"]["disk"], 1073741824)

    def test_fetch_guest_detail_for_lxc_uses_status_and_config_api(self):
        config_payload = {"data": {"hostname": "web01", "memory": 2048, "cores": 2, "rootfs": "local-lvm:vm-101-disk-0,size=32G"}}
        status_payload = {"data": {"status": "running", "maxmem": 2097152, "mem": 1048576, "cpus": 2, "cpu": 12.3, "maxdisk": 4294967296, "disk": 1073741824}}
        with patch("proxmox.urlopen", side_effect=[
            io.BytesIO(json.dumps(config_payload).encode()),
            io.BytesIO(json.dumps(status_payload).encode()),
        ]):
            result = proxmox.fetch_guest_detail(self.config, {"node": "pve1", "vmid": 101, "type": "lxc"})

        self.assertEqual(result["proxmox"]["hostname"], "web01")
        self.assertEqual(result["qm_info"]["mem"], 1048576)
        self.assertNotIn('"status"', result["guest_cmds"]["status_current"])
        self.assertIn('"mem": 1048576', result["guest_cmds"]["status_current"])
        self.assertIn('"rootfs"', result["guest_cmds"]["config"])

    def test_fetch_guest_detail_for_lxc_includes_network_ip_from_config(self):
        config_payload = {"data": {"hostname": "web01", "net0": "name=eth0,bridge=vmbr0,ip=192.168.1.20/24,ip6=fd00::20/64"}}
        status_payload = {"data": {"status": "running", "maxmem": 2097152, "mem": 1048576, "cpus": 2, "disk": 1073741824}}
        with patch("proxmox.urlopen", side_effect=[
            io.BytesIO(json.dumps(config_payload).encode()),
            io.BytesIO(json.dumps(status_payload).encode()),
        ]):
            result = proxmox.fetch_guest_detail(self.config, {"node": "pve1", "vmid": 101, "type": "lxc"})

        network_json = json.loads(result["guest_cmds"]["network"])
        self.assertTrue(isinstance(network_json, list) and network_json)
        self.assertIn("eth0", network_json[0]["name"])
        self.assertIn("192.168.1.20", json.dumps(network_json))
        self.assertIn("fd00::20", json.dumps(network_json))

    def test_fetch_guest_detail_hides_volatile_guest_fields_from_serialized_output(self):
        config_payload = {"data": {"name": "web01", "memory": 2048, "cores": 2, "rootfs": "local-lvm:vm-101-disk-0,size=32G"}}
        status_payload = {"data": {"name": "web01", "status": "running", "vmid": 101, "type": "lxc", "mem": 1048576, "maxmem": 2097152, "disk": 1073741824}}
        with patch("proxmox.urlopen", side_effect=[
            io.BytesIO(json.dumps(config_payload).encode()),
            io.BytesIO(json.dumps(status_payload).encode()),
        ]):
            result = proxmox.fetch_guest_detail(self.config, {"node": "pve1", "vmid": 101, "type": "lxc"})

        status_json = json.loads(result["guest_cmds"]["status_current"])
        config_json = json.loads(result["guest_cmds"]["config"])
        self.assertNotIn("name", status_json)
        self.assertNotIn("status", status_json)
        self.assertNotIn("vmid", status_json)
        self.assertNotIn("type", status_json)
        self.assertNotIn("name", config_json)
        self.assertNotIn("type", config_json)

    def test_fetch_guest_command_formats_json_output_prettily(self):
        payload = {"id": "ubuntu", "pretty-name": "Ubuntu 24.04.5 LTS", "version": "24.04.5 LTS (Noble Numbat)"}
        response = {"data": payload}
        with patch("proxmox.urlopen", side_effect=[io.BytesIO(json.dumps(response).encode())]):
            result = proxmox.fetch_guest_command(self.config, {"node": "pve1", "vmid": 101, "type": "qemu"}, "get-osinfo")

        self.assertIn('"pretty-name": "Ubuntu 24.04.5 LTS"', result)
        self.assertIn('"version": "24.04.5 LTS (Noble Numbat)"', result)

    def test_fetch_guest_file_text_reads_file_via_guest_agent(self):
        with patch(
            "proxmox.urlopen",
            return_value=io.BytesIO(json.dumps({"data": {"content": "hello from host info"}}).encode()),
        ) as urlopen_mock:
            content = proxmox.fetch_guest_file_text(self.config, {"node": "pve1", "vmid": 101, "type": "qemu"}, "/srv/info/host.info")

        self.assertEqual(content, "hello from host info")
        self.assertIn("file-read?file=%2Fsrv%2Finfo%2Fhost.info", urlopen_mock.call_args[0][0].full_url)

    def test_fetch_guest_file_text_reads_content_via_proxmox_guest_agent(self):
        with patch(
            "proxmox.urlopen",
            return_value=io.BytesIO(json.dumps({"data": {"content": "Zweck: Web Test\n"}}).encode()),
        ) as urlopen_mock:
            content = proxmox.fetch_guest_file_text(self.config, {"node": "pve1", "vmid": 101, "type": "qemu"}, "/srv/info/host.info")

        self.assertEqual(content, "Zweck: Web Test")
        self.assertIn("file-read?file=%2Fsrv%2Finfo%2Fhost.info", urlopen_mock.call_args[0][0].full_url)

    def test_fetch_guest_file_text_prefers_lxc_guest_agent_and_uses_pct_exec_only_as_fallback(self):
        with patch(
            "proxmox.urlopen",
            return_value=io.BytesIO(json.dumps({"data": {"content": "hello from lxc info\n"}}).encode()),
        ) as urlopen_mock, patch("proxmox.subprocess.run") as run_mock:
            content = proxmox.fetch_guest_file_text(self.config, {"node": "pve1", "vmid": 102, "type": "lxc"}, "/srv/info/host.info")

        self.assertEqual(content, "hello from lxc info")
        self.assertIn("/lxc/102/agent/file-read?file=%2Fsrv%2Finfo%2Fhost.info", urlopen_mock.call_args[0][0].full_url)
        run_mock.assert_not_called()

    def test_write_guest_file_text_uses_qm_guest_cmd_fallback(self):
        with patch(
            "proxmox.urlopen",
            side_effect=proxmox.ProxmoxError("guest write endpoint unavailable"),
        ), patch(
            "proxmox.subprocess.run",
            side_effect=[
                subprocess.CompletedProcess(
                    args=["qm", "guest", "cmd", "101", "file-open", '{"path": "/srv/info/host.info", "mode": "w"}'],
                    returncode=0,
                    stdout='{"data":{"fd":9}}',
                    stderr='',
                ),
                subprocess.CompletedProcess(
                    args=["qm", "guest", "cmd", "101", "file-write", '{"fd": 9, "offset": 0, "data": "new comment"}'],
                    returncode=0,
                    stdout='{"data":{}}',
                    stderr='',
                ),
                subprocess.CompletedProcess(
                    args=["qm", "guest", "cmd", "101", "file-close", '{"fd": 9}'],
                    returncode=0,
                    stdout='{"data":{}}',
                    stderr='',
                ),
            ],
        ) as run_mock:
            proxmox.write_guest_file_text(self.config, {"node": "pve1", "vmid": 101, "type": "qemu"}, "/srv/info/host.info", "new comment")

        self.assertEqual(run_mock.call_count, 3)

    def test_write_guest_file_text_writes_via_guest_agent(self):
        with patch(
            "proxmox.urlopen",
            side_effect=[
                io.BytesIO(json.dumps({"data": {"fd": 9}}).encode()),
                io.BytesIO(json.dumps({"data": {}}).encode()),
                io.BytesIO(json.dumps({"data": {}}).encode()),
            ],
        ):
            proxmox.write_guest_file_text(self.config, {"node": "pve1", "vmid": 101, "type": "qemu"}, "/srv/info/host.info", "new comment")

    def test_main_reports_error_json_for_incomplete_config(self):
        with patch("sys.stdin", io.StringIO("{}")), patch("sys.stdout", new_callable=io.StringIO) as out:
            code = proxmox.main()
        self.assertEqual(code, 1)
        self.assertFalse(json.loads(out.getvalue())["success"])


if __name__ == "__main__":
    unittest.main()
