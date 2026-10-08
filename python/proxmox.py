#!/usr/bin/env python3
"""Read-only Proxmox cluster information for the prox-web PHP frontend."""

import ipaddress
import json
import re
import ssl
import sys
from urllib.error import HTTPError, URLError
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


def extract_resource_ip(resource):
    if not isinstance(resource, dict):
        return ""

    candidates = []
    for key in ("ip", "ip-address", "ip_address", "ipaddress"):
        value = resource.get(key)
        if value not in (None, ""):
            candidates.append(str(value))

    ip_addresses = resource.get("ip_addresses")
    if isinstance(ip_addresses, list):
        for value in ip_addresses:
            if value not in (None, ""):
                candidates.append(str(value))

    for key in ("net0", "net1", "network", "net"):
        value = resource.get(key)
        if isinstance(value, str):
            for match in re.findall(r"(?:\d{1,3}\.){3}\d{1,3}|[0-9a-fA-F:]{2,}", value):
                if match in ("dhcp", "localhost"):
                    continue
                try:
                    ipaddress.ip_address(match)
                    candidates.append(match)
                except ValueError:
                    continue

    seen = set()
    result = []
    for value in candidates:
        if value and value not in seen:
            seen.add(value)
            result.append(value)
    return ", ".join(result)


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
        "guest_agent": {},
    }

    if resource_type in {"qemu", "lxc"}:
        detail["proxmox"] = _api_get_object(
            base_url,
            authorization,
            f"/nodes/{node}/{resource_type}/{vmid}/config",
            verify_certificate,
        )
        try:
            detail["guest_agent"] = _api_get_object(
                base_url,
                authorization,
                f"/nodes/{node}/{resource_type}/{vmid}/agent/network-get-interfaces",
                verify_certificate,
            )
        except ProxmoxError:
            detail["guest_agent"] = {}
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
        if isinstance(config.get("guest"), dict):
            result = {"success": True, "data": fetch_guest_detail(config, config["guest"])}
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
