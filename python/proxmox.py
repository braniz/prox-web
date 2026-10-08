#!/usr/bin/env python3
"""Read-only Proxmox cluster information for the prox-web PHP frontend."""

import base64
import ipaddress
import json
import re
import shlex
import ssl
import subprocess
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import Request, urlopen


class ProxmoxError(Exception):
    """An expected configuration or Proxmox API error."""


def parse_certificate_verification(value):
    """Interpret the stored certificate-verification flag; a missing value means enabled."""
    if value is None:
        return True
    if isinstance(value, str):
        normalized = value.strip().lower()
        if normalized in ("1", "true", "ja", "yes", "on"):
            return True
        if normalized in ("0", "false", "nein", "no", "off", ""):
            return False
        raise ProxmoxError(
            "Die konfigurierte Zertifikatsprüfung ist ungültig (erlaubt: ja/nein)."
        )
    return bool(value)


def parse_tls(value):
    """Interpret the stored TLS flag; a missing value means TLS is enabled."""
    if value is None:
        return True
    if isinstance(value, str):
        normalized = value.strip().lower()
        if normalized in ("1", "true", "ja", "yes", "on"):
            return True
        if normalized in ("0", "false", "nein", "no", "off", ""):
            return False
        raise ProxmoxError("Die konfigurierte TLS-Einstellung ist ungültig (erlaubt: ja/nein).")
    return bool(value)


def _connection_details(config):
    host = str(config.get("host", "")).strip()
    if not host or any(char.isspace() for char in host) or any(char in host for char in "/@?#"):
        raise ProxmoxError("Bitte geben Sie im Adminbereich einen gültigen Proxmox-Host ein.")

    try:
        address = ipaddress.ip_address(host.strip("[]"))
        host = f"[{address}]" if address.version == 6 else str(address)
    except ValueError:
        if ":" in host or not re.fullmatch(r"[A-Za-z0-9.-]+", host):
            raise ProxmoxError("Bitte geben Sie im Adminbereich einen gültigen Proxmox-Host ein.")

    try:
        port = int(config.get("port", 8006))
    except (TypeError, ValueError):
        raise ProxmoxError("Der konfigurierte Proxmox-Port ist ungültig.")
    if not 1 <= port <= 65535:
        raise ProxmoxError("Der konfigurierte Proxmox-Port ist ungültig.")

    tls = parse_tls(config.get("tls"))
    scheme = "https" if tls else "http"

    token_id = str(config.get("token_id", ""))
    token_secret = str(config.get("token_secret", ""))
    if not token_id or not token_secret:
        raise ProxmoxError("Proxmox-Zugangsdaten fehlen. Bitte lassen Sie diese im Adminbereich konfigurieren.")
    if any(ord(char) < 32 or ord(char) == 127 for char in token_id + token_secret):
        raise ProxmoxError("Die konfigurierten Proxmox-Zugangsdaten sind ungültig.")

    return f"{scheme}://{host}:{port}", f"PVEAPIToken={token_id}={token_secret}"


def _api_get(base_url, authorization, path, verify_certificate):
    request = Request(
        base_url + "/api2/json" + path,
        headers={"Authorization": authorization, "Accept": "application/json"},
    )
    try:
        options = {}
        if base_url.startswith("https://"):
            if verify_certificate:
                options["context"] = ssl.create_default_context()
            else:
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
                options["context"] = context
        with urlopen(request, timeout=10, **options) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except HTTPError as error:
        raise ProxmoxError(f"Die Proxmox-API antwortete mit HTTP {error.code}.")
    except (URLError, TimeoutError, OSError):
        if base_url.startswith("https://"):
            raise ProxmoxError("Die Proxmox-API ist nicht erreichbar. Host, Port und TLS-Zertifikat prüfen.")
        raise ProxmoxError("Die Proxmox-API ist nicht erreichbar. Host und Port prüfen (TLS ist deaktiviert).")
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise ProxmoxError("Die Proxmox-API lieferte eine ungültige Antwort.")

    if not isinstance(payload, dict):
        raise ProxmoxError("Die Proxmox-API lieferte eine unerwartete Antwort.")
    return payload.get("data")


def _api_get_list(base_url, authorization, path, verify_certificate):
    data = _api_get(base_url, authorization, path, verify_certificate)
    if not isinstance(data, list) or not all(isinstance(item, dict) for item in data):
        raise ProxmoxError("Die Proxmox-API lieferte eine unerwartete Antwort.")
    return data


def _api_get_object(base_url, authorization, path, verify_certificate):
    data = _api_get(base_url, authorization, path, verify_certificate)
    if data is None:
        return {}
    if isinstance(data, dict):
        return data
    if isinstance(data, list):
        return {"items": data}
    return {}


def _api_post_json(base_url, authorization, path, payload, verify_certificate):
    request = Request(
        base_url + "/api2/json" + path,
        headers={"Authorization": authorization, "Accept": "application/json", "Content-Type": "application/json"},
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        method="POST",
    )
    try:
        options = {}
        if base_url.startswith("https://"):
            if verify_certificate:
                options["context"] = ssl.create_default_context()
            else:
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
                options["context"] = context
        with urlopen(request, timeout=10, **options) as response:
            raw = response.read().decode("utf-8")
    except HTTPError as error:
        raise ProxmoxError(f"Die Proxmox-API antwortete mit HTTP {error.code}.")
    except (URLError, TimeoutError, OSError):
        if base_url.startswith("https://"):
            raise ProxmoxError("Die Proxmox-API ist nicht erreichbar. Host, Port und TLS-Zertifikat prüfen.")
        raise ProxmoxError("Die Proxmox-API ist nicht erreichbar. Host und Port prüfen (TLS ist deaktiviert).")
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise ProxmoxError("Die Proxmox-API lieferte eine ungültige Antwort.")

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise ProxmoxError("Die Proxmox-API lieferte eine ungültige Antwort.") from exc

    if not isinstance(payload, dict):
        raise ProxmoxError("Die Proxmox-API lieferte eine unerwartete Antwort.")
    return payload


VOLATILE_GUEST_KEYS = {"name", "vmid", "status", "type"}


def _strip_volatile_root_fields(data):
    if not isinstance(data, dict):
        return data
    filtered = {}
    for key, value in data.items():
        if str(key).lower() in VOLATILE_GUEST_KEYS:
            continue
        filtered[key] = value
    return filtered


def _result(data):
    if isinstance(data, dict) and "result" in data:
        return data.get("result")
    return data


def _usable_ip(value):
    if value is None:
        return None
    text = str(value).split("/", 1)[0].strip()
    text = text.split("%", 1)[0].strip()
    if not text:
        return None
    try:
        ip = ipaddress.ip_address(text)
    except ValueError:
        return None
    if ip.is_loopback or ip.is_link_local:
        return None
    return str(ip)


def _dedupe(items):
    return list(dict.fromkeys(items))


def _lxc_network_interfaces(config_data):
    if not isinstance(config_data, dict):
        return []

    interfaces = []
    for key, value in config_data.items():
        if not isinstance(key, str) or not re.fullmatch(r"net\d+", key.lower()):
            continue
        if not isinstance(value, str):
            continue

        fields = {}
        for chunk in value.split(","):
            item = chunk.strip()
            if not item or "=" not in item:
                continue
            field_name, field_value = item.split("=", 1)
            if field_name.strip():
                fields[field_name.strip().lower()] = field_value.strip()

        interface_name = fields.get("name") or key
        addresses = []
        for field_name in ("ip", "ip0", "ip1", "ip6", "ip6-addr", "ip6addr"):
            raw_value = fields.get(field_name)
            if not raw_value:
                continue
            for partial in raw_value.split():
                candidate = partial.strip().strip(",")
                if not candidate:
                    continue
                ip_value = _usable_ip(candidate)
                if ip_value:
                    addresses.append(ip_value)

        if not interface_name and not addresses:
            continue

        interfaces.append({
            "name": interface_name,
            "interface": interface_name,
            "ifname": interface_name,
            "ip-addresses": addresses,
            "addresses": addresses,
        })

    return interfaces


def extract_qemu_ips(data):
    payload = _result(data)
    ips = []
    if not isinstance(payload, list):
        return ips
    for iface in payload:
        if not isinstance(iface, dict) or iface.get("name") == "lo":
            continue
        for addr in iface.get("ip-addresses") or []:
            if isinstance(addr, dict):
                ip = _usable_ip(addr.get("ip-address"))
            else:
                ip = _usable_ip(addr)
            if ip:
                ips.append(ip)
    return _dedupe(ips)


def extract_lxc_ips(data):
    payload = _result(data)
    ips = []
    if not isinstance(payload, list):
        return ips
    for iface in payload:
        if not isinstance(iface, dict) or iface.get("name") == "lo":
            continue
        for key in ("inet", "inet6"):
            ip = _usable_ip(iface.get(key))
            if ip:
                ips.append(ip)
    return _dedupe(ips)


def _parse_runtime_lxc_ips(text):
    if not isinstance(text, str):
        return []
    interfaces = {}
    current_name = None

    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line:
            continue

        header_match = re.match(r"^\d+:\s+(\S+)", line)
        if header_match:
            current_name = header_match.group(1).rstrip(":")
            current_name = current_name.split("@", 1)[0]
            if current_name == "lo":
                current_name = None
                continue
            iface = interfaces.setdefault(
                current_name,
                {"name": current_name, "interface": current_name, "ifname": current_name, "ip-addresses": [], "addresses": []},
            )
            continue

        if current_name is None:
            continue

        for address in re.findall(r"(?:inet|inet6)\s+([0-9A-Fa-f:.]+(?:/\d+)?)", line):
            ip = _usable_ip(address)
            if ip and ip not in interfaces[current_name]["ip-addresses"]:
                interfaces[current_name]["ip-addresses"].append(ip)
                interfaces[current_name]["addresses"].append(ip)

    return [
        {
            "name": iface["name"],
            "interface": iface["interface"],
            "ifname": iface["ifname"],
            "ip-addresses": iface["ip-addresses"],
            "addresses": iface["addresses"],
        }
        for iface in interfaces.values()
    ]


def extract_qemu_hostname(data):
    payload = _result(data)
    if not isinstance(payload, dict):
        return None
    name = payload.get("host-name") or payload.get("hostname") or payload.get("name")
    if isinstance(name, str) and name:
        return name
    return None


def extract_resource_ip(resource):
    if not isinstance(resource, (dict, list)):
        return ""

    def add_items(target, items):
        for item in items:
            if isinstance(item, str):
                ip = _usable_ip(item)
                if ip:
                    target.append(ip)
            elif isinstance(item, dict):
                for key in ("ip-address", "ip_address", "ip-addresses", "ip_addresses", "inet", "inet6"):
                    if key in item:
                        values = item.get(key)
                        if isinstance(values, list):
                            for value in values:
                                ip = _usable_ip(value)
                                if ip:
                                    target.append(ip)
                        else:
                            ip = _usable_ip(values)
                            if ip:
                                target.append(ip)

    seen = set()
    result = []

    def walk(node):
        if isinstance(node, dict):
            for key, value in node.items():
                key_lower = str(key).lower()
                if key_lower in {"ip", "ip-address", "ip_address", "ipaddress", "ip-addresses", "ip_addresses", "inet", "inet6"}:
                    if isinstance(value, list):
                        add_items(result, value)
                    else:
                        ip = _usable_ip(value)
                        if ip:
                            result.append(ip)
                elif key_lower in {"net0", "net1", "network", "net"} and isinstance(value, str):
                    for match in re.findall(r"\b(?:\d{1,3}\.){3}\d{1,3}\b|\b[0-9a-fA-F:]{2,}\b", value):
                        if match not in {"dhcp", "localhost"}:
                            ip = _usable_ip(match)
                            if ip:
                                result.append(ip)
                elif isinstance(value, (dict, list)):
                    walk(value)
        elif isinstance(node, list):
            for item in node:
                walk(item)

    walk(resource)
    deduped = []
    for ip in result:
        if ip not in seen:
            seen.add(ip)
            deduped.append(ip)
    return ", ".join(deduped)


def _guest_cmd_output_from_payload(payload):
    if isinstance(payload, dict):
        for key in ("stdout", "output", "result", "data"):
            value = payload.get(key)
            if isinstance(value, str) and value.strip():
                return value.strip()
            if isinstance(value, (dict, list)):
                nested = _guest_cmd_output_from_payload(value)
                if nested:
                    return nested
        if payload:
            return json.dumps(payload, ensure_ascii=False, sort_keys=True)
        return ""

    if isinstance(payload, list):
        if not payload:
            return ""
        return json.dumps(payload, ensure_ascii=False, sort_keys=True)

    if isinstance(payload, str):
        return payload.strip()

    return ""


def _pretty_guest_output(value):
    if not isinstance(value, str):
        return value
    trimmed = value.strip()
    if not trimmed:
        return value
    if trimmed[0] in "[{":
        try:
            parsed = json.loads(trimmed)
            return json.dumps(parsed, ensure_ascii=False, indent=2, sort_keys=True)
        except json.JSONDecodeError:
            return value
    return value


def _format_guest_time(value):
    if isinstance(value, bool):
        return str(value)
    if isinstance(value, (int, float)):
        try:
            seconds = float(value) / 1e9 if abs(float(value)) > 1e12 else float(value)
            return __import__("datetime").datetime.fromtimestamp(seconds, __import__("datetime").timezone.utc).strftime("%d.%m.%Y %H:%M:%S UTC")
        except (OverflowError, OSError, ValueError):
            return str(value)
    if isinstance(value, str):
        value = value.strip()
        if re.fullmatch(r"[+-]?\d+", value):
            try:
                seconds = float(value) / 1e9 if abs(float(value)) > 1e12 else float(value)
                return __import__("datetime").datetime.fromtimestamp(seconds, __import__("datetime").timezone.utc).strftime("%d.%m.%Y %H:%M:%S UTC")
            except (OverflowError, OSError, ValueError):
                return value
        return value
    return str(value)


def _extract_guest_text_value(payload):
    if isinstance(payload, str):
        return payload.strip()
    if isinstance(payload, dict):
        for key in ("data", "content", "text", "result", "stdout", "output"):
            if key in payload:
                value = _extract_guest_text_value(payload[key])
                if value not in (None, ""):
                    return value
        return ""
    if isinstance(payload, list):
        combined = []
        for item in payload:
            text = _extract_guest_text_value(item)
            if text not in (None, ""):
                combined.append(text)
        return "\n".join(combined) if combined else ""
    return ""


def _extract_fd(payload):
    if payload in (None, ""):
        return None
    if isinstance(payload, (int, str)):
        return payload
    if isinstance(payload, dict):
        for key in ("fd", "file-descriptor"):
            if key in payload and payload[key] not in (None, ""):
                return payload[key]
        for key in ("data", "result"):
            fd = _extract_fd(payload.get(key))
            if fd is not None:
                return fd
    if isinstance(payload, list):
        for item in payload:
            fd = _extract_fd(item)
            if fd is not None:
                return fd
    return None


def _run_lxc_guest_command(node, vmid, shell_command):
    commands = [
        ["pct", "exec", str(vmid), "--", "bash", "-lc", shell_command],
        ["ssh", "-o", "StrictHostKeyChecking=no", "-o", "BatchMode=yes", f"root@{node}", "pct", "exec", str(vmid), "--", "bash", "-lc", shell_command],
    ]
    last_error = None
    for args in commands:
        try:
            completed = subprocess.run(args, capture_output=True, text=True, timeout=20, check=False)
        except (FileNotFoundError, OSError, subprocess.TimeoutExpired) as exc:
            last_error = exc
            continue
        if completed.returncode == 0:
            return (completed.stdout or "").strip()
        last_error = RuntimeError((completed.stderr or "").strip() or f"pct exec {vmid} failed")
    if isinstance(last_error, Exception):
        raise ProxmoxError(str(last_error))
    raise ProxmoxError(f"Die LXC-Guest-Anweisung konnte nicht ausgeführt werden.")


def fetch_guest_command(config, guest, command, payload=None):
    node = str(guest.get("node", "")).strip()
    vmid = guest.get("vmid")
    resource_type = str(guest.get("type", "")).strip().lower()
    if not node or vmid in (None, ""):
        raise ProxmoxError("Die Anforderung enthält keine gültige VM- oder Container-ID.")
    if resource_type not in {"qemu", "lxc"}:
        raise ProxmoxError("Die Anforderung enthält keine gültige VM- oder Container-ID.")

    if resource_type == "lxc":
        command_map = {
            "network-get-interfaces": "ip -o addr show 2>/dev/null || cat /proc/net/fib_trie 2>/dev/null || ip addr 2>/dev/null",
            "get-fsinfo": "df -T / 2>/dev/null || df -h / 2>/dev/null || cat /proc/mounts 2>/dev/null",
            "get-osinfo": "cat /etc/os-release 2>/dev/null || uname -a 2>/dev/null",
            "get-users": "getent passwd 2>/dev/null | head -n 50",
            "get-time": "date -u '+%Y-%m-%d %H:%M:%S UTC' 2>/dev/null",
        }
        if command not in command_map:
            raise ProxmoxError(f"Die LXC-Guest-Anweisung {command} wird nicht unterstützt.")
        return _run_lxc_guest_command(node, vmid, command_map[command])

    base_url, authorization = _connection_details(config)
    verify_certificate = parse_certificate_verification(config.get("verify_certificate"))
    endpoint = f"/nodes/{node}/{resource_type}/{vmid}/agent/{command}"

    if payload is None:
        request = Request(
            base_url + "/api2/json" + endpoint,
            headers={"Authorization": authorization, "Accept": "application/json"},
        )
        options = {}
        if base_url.startswith("https://"):
            if verify_certificate:
                options["context"] = ssl.create_default_context()
            else:
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
                options["context"] = context
        try:
            with urlopen(request, timeout=10, **options) as response:
                raw = response.read().decode("utf-8")
        except (HTTPError, URLError, TimeoutError, OSError):
            raise ProxmoxError(f"Die Proxmox-Guest-Anweisung {command} konnte nicht ausgeführt werden.")
        except UnicodeDecodeError:
            raise ProxmoxError(f"Die Proxmox-Guest-Anweisung {command} lieferte eine ungültige Antwort.")
        try:
            payload = json.loads(raw)
        except json.JSONDecodeError as exc:
            raise ProxmoxError(f"Die Proxmox-Guest-Anweisung {command} lieferte eine ungültige Antwort.") from exc
    else:
        payload = _api_post_json(base_url, authorization, endpoint, payload, verify_certificate)

    data = payload.get("data") if isinstance(payload, dict) else payload
    plain = _result(data)
    if command == "get-time" and plain is not None:
        return _format_guest_time(plain)
    if isinstance(plain, dict):
        text = _extract_guest_text_value(plain)
        if text:
            return text
        return _pretty_guest_output(json.dumps(plain, ensure_ascii=False, sort_keys=True, indent=2))
    if isinstance(plain, list):
        text = _extract_guest_text_value(plain)
        if text:
            return text
        return _pretty_guest_output(json.dumps(plain, ensure_ascii=False, sort_keys=True, indent=2))
    if plain is None:
        return ""
    return str(plain).strip()


def fetch_guest_file_text(config, guest, path):
    node = str(guest.get("node", "")).strip()
    vmid = guest.get("vmid")
    resource_type = str(guest.get("type", "")).strip().lower()
    if not node or vmid in (None, ""):
        raise ProxmoxError("Die Anforderung enthält keine gültige VM- oder Container-ID.")
    if resource_type not in {"qemu", "lxc"}:
        raise ProxmoxError("Die Anforderung enthält keine gültige VM- oder Container-ID.")

    base_url, authorization = _connection_details(config)
    verify_certificate = parse_certificate_verification(config.get("verify_certificate"))
    request = Request(
        base_url + f"/api2/json/nodes/{node}/{resource_type}/{vmid}/agent/file-read?file={quote(path, safe='')}",
        headers={"Authorization": authorization, "Accept": "application/json"},
    )
    options = {}
    if base_url.startswith("https://"):
        if verify_certificate:
            options["context"] = ssl.create_default_context()
        else:
            context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE
            options["context"] = context
    try:
        with urlopen(request, timeout=10, **options) as response:
            raw = response.read().decode("utf-8")
    except (HTTPError, URLError, TimeoutError, OSError):
        if resource_type == "lxc":
            safe_path = shlex.quote(str(path))
            return _run_lxc_guest_command(node, vmid, f"if [ -f {safe_path} ]; then cat {safe_path}; fi")
        return _qm_guest_cmd_file_read(node, vmid, path)
    except UnicodeDecodeError:
        if resource_type == "lxc":
            safe_path = shlex.quote(str(path))
            return _run_lxc_guest_command(node, vmid, f"if [ -f {safe_path} ]; then cat {safe_path}; fi")
        return _qm_guest_cmd_file_read(node, vmid, path)

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError:
        if resource_type == "lxc":
            safe_path = shlex.quote(str(path))
            return _run_lxc_guest_command(node, vmid, f"if [ -f {safe_path} ]; then cat {safe_path}; fi")
        return _qm_guest_cmd_file_read(node, vmid, path)

    if isinstance(payload, dict):
        data = payload.get("data")
        if isinstance(data, dict):
            for key in ("content", "data", "result"):
                value = data.get(key)
                if isinstance(value, str):
                    return value.rstrip("\r\n")
                if isinstance(value, dict):
                    nested = _extract_guest_text_value(value)
                    if nested:
                        return nested.rstrip("\r\n")
        text = _extract_guest_text_value(payload)
        if text:
            return text.rstrip("\r\n")

    if resource_type == "lxc":
        safe_path = shlex.quote(str(path))
        return _run_lxc_guest_command(node, vmid, f"if [ -f {safe_path} ]; then cat {safe_path}; fi")
    return _qm_guest_cmd_file_read(node, vmid, path)


def _qm_guest_cmd_file_read(node, vmid, path):
    open_data = _qm_guest_cmd_invoke(node, vmid, "file-open", {"path": path, "mode": "r"})
    fd = _extract_fd(open_data)
    if fd in (None, ""):
        raise ProxmoxError(f"Die Datei {path} konnte nicht gelesen werden.")
    try:
        read_data = _qm_guest_cmd_invoke(node, vmid, "file-read", {"fd": fd, "offset": 0, "count": 65536})
    finally:
        try:
            _qm_guest_cmd_invoke(node, vmid, "file-close", {"fd": fd})
        except ProxmoxError:
            pass

    if isinstance(read_data, dict):
        for key in ("data", "result", "content", "text"):
            value = read_data.get(key)
            if isinstance(value, str):
                return value.rstrip("\r\n")
            if isinstance(value, dict):
                nested = _extract_guest_text_value(value)
                if nested:
                    return nested.rstrip("\r\n")
    text = _extract_guest_text_value(read_data)
    if text:
        return text.rstrip("\r\n")
    return ""


def _qm_guest_cmd_invoke(node, vmid, command, payload=None):
    base = ["qm", "guest", "cmd", str(vmid), command]
    if payload is not None:
        base.append(json.dumps(payload, ensure_ascii=False))
    candidates = [base]
    if node:
        candidates.append(["ssh", "-o", "StrictHostKeyChecking=no", "-o", "BatchMode=yes", f"root@{node}", "qm", "guest", "cmd", str(vmid), command])
        if payload is not None:
            candidates[-1].append(json.dumps(payload, ensure_ascii=False))

    last_error = None
    for args in candidates:
        try:
            completed = subprocess.run(args, capture_output=True, text=True, timeout=20, check=False)
        except (FileNotFoundError, OSError, subprocess.TimeoutExpired) as exc:
            last_error = exc
            continue
        if completed.returncode == 0:
            raw = (completed.stdout or "").strip()
            if not raw:
                return {}
            try:
                return json.loads(raw)
            except json.JSONDecodeError:
                return {"result": raw}
        last_error = RuntimeError((completed.stderr or "").strip() or f"qm guest cmd {command} failed")

    if isinstance(last_error, Exception):
        raise ProxmoxError(str(last_error))
    raise ProxmoxError(f"Die Proxmox-Guest-Anweisung {command} konnte nicht ausgeführt werden.")


def write_guest_file_text(config, guest, path, content):
    node = str(guest.get("node", "")).strip()
    vmid = guest.get("vmid")
    resource_type = str(guest.get("type", "")).strip().lower()
    if not node or vmid in (None, ""):
        raise ProxmoxError("Die Anforderung enthält keine gültige VM- oder Container-ID.")
    if resource_type not in {"qemu", "lxc"}:
        raise ProxmoxError("Die Anforderung enthält keine gültige VM- oder Container-ID.")
    if resource_type == "lxc":
        return

    try:
        open_data = fetch_guest_command(config, guest, "file-open", {"path": path, "mode": "w"})
    except ProxmoxError:
        open_data = _qm_guest_cmd_invoke(node, vmid, "file-open", {"path": path, "mode": "w"})
    fd = _extract_fd(open_data)
    if fd in (None, ""):
        raise ProxmoxError(f"Die Datei {path} konnte nicht geöffnet werden.")

    try:
        try:
            fetch_guest_command(config, guest, "file-write", {"fd": fd, "offset": 0, "data": content})
        except ProxmoxError:
            _qm_guest_cmd_invoke(node, vmid, "file-write", {"fd": fd, "offset": 0, "data": content})
    finally:
        try:
            try:
                fetch_guest_command(config, guest, "file-close", {"fd": fd})
            except ProxmoxError:
                _qm_guest_cmd_invoke(node, vmid, "file-close", {"fd": fd})
        except ProxmoxError:
            pass


def fetch_cluster_info(config):
    base_url, authorization = _connection_details(config)
    verify_certificate = parse_certificate_verification(config.get("verify_certificate"))
    return {
        "cluster": _api_get_list(base_url, authorization, "/cluster/status", verify_certificate),
        "nodes": _api_get_list(base_url, authorization, "/nodes", verify_certificate),
        "resources": _api_get_list(base_url, authorization, "/cluster/resources", verify_certificate),
    }


def fetch_guest_detail(config, guest):
    node = str(guest.get("node", "")).strip()
    vmid = guest.get("vmid")
    resource_type = str(guest.get("type", "")).strip().lower()
    if not node or vmid in (None, ""):
        raise ProxmoxError("Die Anforderung enthält keine gültige VM- oder Container-ID.")

    base_url, authorization = _connection_details(config)
    verify_certificate = parse_certificate_verification(config.get("verify_certificate"))
    detail = {
        "node": node,
        "vmid": vmid,
        "type": resource_type,
        "proxmox": {},
        "qm_info": {},
        "guest_agent": {},
        "guest_cmds": {},
    }

    if resource_type == "qemu":
        detail["proxmox"] = _api_get_object(
            base_url,
            authorization,
            f"/nodes/{node}/{resource_type}/{vmid}/config",
            verify_certificate,
        )
        try:
            detail["qm_info"] = _api_get_object(
                base_url,
                authorization,
                f"/nodes/{node}/{resource_type}/{vmid}/status/current",
                verify_certificate,
            )
        except ProxmoxError:
            detail["qm_info"] = {}

        try:
            verbose_status = _api_get_object(
                base_url,
                authorization,
                f"/nodes/{node}/{resource_type}/{vmid}/status?verbose=1",
                verify_certificate,
            )
            if isinstance(verbose_status, dict):
                detail["qm_info"].update(verbose_status)
        except ProxmoxError:
            pass

        try:
            detail["guest_agent"] = _api_get_object(
                base_url,
                authorization,
                f"/nodes/{node}/{resource_type}/{vmid}/agent/network-get-interfaces",
                verify_certificate,
            )
        except ProxmoxError:
            detail["guest_agent"] = {}

        for label, command in (("network", "network-get-interfaces"), ("fsinfo", "get-fsinfo"), ("osinfo", "get-osinfo"), ("users", "get-users"), ("time", "get-time")):
            try:
                detail["guest_cmds"][label] = fetch_guest_command(config, guest, command)
            except ProxmoxError:
                detail["guest_cmds"][label] = ""
        return detail

    if resource_type == "lxc":
        detail["proxmox"] = _api_get_object(
            base_url,
            authorization,
            f"/nodes/{node}/lxc/{vmid}/config",
            verify_certificate,
        )
        detail["qm_info"] = _api_get_object(
            base_url,
            authorization,
            f"/nodes/{node}/lxc/{vmid}/status/current",
            verify_certificate,
        )
        detail["guest_cmds"]["status_current"] = json.dumps(_strip_volatile_root_fields(detail["qm_info"]), ensure_ascii=False, indent=2, sort_keys=True)
        detail["guest_cmds"]["config"] = json.dumps(_strip_volatile_root_fields(detail["proxmox"]), ensure_ascii=False, indent=2, sort_keys=True)

        try:
            runtime_network = fetch_guest_command(config, guest, "network-get-interfaces")
            runtime_interfaces = _parse_runtime_lxc_ips(runtime_network)
            if runtime_interfaces:
                detail["guest_agent"] = runtime_interfaces
                detail["guest_cmds"]["network"] = json.dumps(runtime_interfaces, ensure_ascii=False, indent=2, sort_keys=True)
            else:
                detail["guest_agent"] = _lxc_network_interfaces(detail["proxmox"])
                detail["guest_cmds"]["network"] = json.dumps(
                    detail["guest_agent"],
                    ensure_ascii=False,
                    indent=2,
                    sort_keys=True,
                )
        except ProxmoxError:
            detail["guest_agent"] = _lxc_network_interfaces(detail["proxmox"])
            detail["guest_cmds"]["network"] = json.dumps(
                detail["guest_agent"],
                ensure_ascii=False,
                indent=2,
                sort_keys=True,
            )

        detail["guest_cmds"]["fsinfo"] = ""
        detail["guest_cmds"]["osinfo"] = ""
        detail["guest_cmds"]["users"] = ""
        detail["guest_cmds"]["time"] = ""
        return detail

    if resource_type == "docker":
        detail["proxmox"] = {
            "type": "docker",
            "note": "Docker-Container werden in Proxmox oft über die Cluster-Resources gelistet, aber die ausführliche Docker-Engine-Information steht nicht direkt als Proxmox API-Config zur Verfügung.",
        }
        return detail

    raise ProxmoxError("Für dieses Element ist keine Detailansicht verfügbar.")


def main():
    try:
        config = json.load(sys.stdin)
        if not isinstance(config, dict):
            raise ProxmoxError("Die Proxmox-Konfiguration ist ungültig.")
        guest = config.get("guest")
        if isinstance(guest, dict):
            if guest.get("read_file"):
                result = {"success": True, "data": {"host_info": fetch_guest_file_text(config, guest, guest["read_file"])}}
            elif guest.get("write_file"):
                content = guest.get("file_content", "")
                write_guest_file_text(config, guest, guest["write_file"], content)
                result = {"success": True, "data": {"written": True}}
            else:
                result = {"success": True, "data": fetch_guest_detail(config, guest)}
        else:
            result = {"success": True, "data": fetch_cluster_info(config)}
        exit_code = 0
    except (json.JSONDecodeError, ProxmoxError) as error:
        result = {"success": False, "error": str(error)}
        exit_code = 1
    except Exception:
        result = {"success": False, "error": "Die Cluster-Informationen konnten nicht geladen werden."}
        exit_code = 1

    print(json.dumps(result, ensure_ascii=False))
    return exit_code


if __name__ == "__main__":
    sys.exit(main())
