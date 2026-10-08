#!/usr/bin/env python3
"""Read-only Proxmox cluster information for the prox-web PHP frontend."""

import ipaddress
import json
import re
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


class ProxmoxError(Exception):
    """An expected configuration or Proxmox API error."""


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

    tls = bool(config.get("tls", True))
    scheme = "https" if tls else "http"

    token_id = str(config.get("token_id", ""))
    token_secret = str(config.get("token_secret", ""))
    if not token_id or not token_secret:
        raise ProxmoxError("Proxmox-Zugangsdaten fehlen. Bitte lassen Sie diese im Adminbereich konfigurieren.")
    if any(ord(char) < 32 or ord(char) == 127 for char in token_id + token_secret):
        raise ProxmoxError("Die konfigurierten Proxmox-Zugangsdaten sind ungültig.")

    return f"{scheme}://{host}:{port}", f"PVEAPIToken={token_id}={token_secret}"


def _api_get(base_url, authorization, path):
    request = Request(
        base_url + "/api2/json" + path,
        headers={"Authorization": authorization, "Accept": "application/json"},
    )
    try:
        with urlopen(request, timeout=10) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except HTTPError as error:
        raise ProxmoxError(f"Die Proxmox-API antwortete mit HTTP {error.code}.")
    except (URLError, TimeoutError, OSError):
        raise ProxmoxError("Die Proxmox-API ist nicht erreichbar. Host, Port und TLS-Zertifikat prüfen.")
    except (UnicodeDecodeError, json.JSONDecodeError):
        raise ProxmoxError("Die Proxmox-API lieferte eine ungültige Antwort.")

    if (
        not isinstance(payload, dict)
        or not isinstance(payload.get("data"), list)
        or not all(isinstance(item, dict) for item in payload["data"])
    ):
        raise ProxmoxError("Die Proxmox-API lieferte eine unerwartete Antwort.")
    return payload["data"]


def fetch_cluster_info(config):
    base_url, authorization = _connection_details(config)
    return {
        "cluster": _api_get(base_url, authorization, "/cluster/status"),
        "nodes": _api_get(base_url, authorization, "/nodes"),
        "resources": _api_get(base_url, authorization, "/cluster/resources"),
    }


def main():
    try:
        config = json.load(sys.stdin)
        if not isinstance(config, dict):
            raise ProxmoxError("Die Proxmox-Konfiguration ist ungültig.")
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
