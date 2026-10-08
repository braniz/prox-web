#!/usr/bin/env python3
"""Fragt die Proxmox-API serverseitig ab und gibt JSON auf stdout aus.

Die Zugangsdaten stammen aus data/api.json (Admin: API-Info) und werden
weder als Argument übergeben noch in der Ausgabe zurückgegeben.
"""
import json
import os
import ssl
import sys
import urllib.error
import urllib.request

CONFIG = os.environ.get(
    "PROXWEB_API_CONFIG",
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data", "api.json"),
)


class ProxError(Exception):
    pass


def get(base, path, auth, ctx):
    req = urllib.request.Request(
        base + "/api2/json" + path,
        headers={"Accept": "application/json", "Authorization": auth},
    )
    try:
        with urllib.request.urlopen(req, timeout=15, context=ctx) as resp:
            return json.loads(resp.read().decode("utf-8")).get("data") or []
    except urllib.error.HTTPError as e:
        if e.code in (401, 403):
            raise ProxError("Anmeldung an der Proxmox-API fehlgeschlagen (Token-ID/Secret oder Berechtigungen prüfen).")
        raise ProxError("Proxmox-API-Fehler: HTTP %d" % e.code)
    except urllib.error.URLError as e:
        raise ProxError("Proxmox-API nicht erreichbar: %s" % e.reason)
    except (ValueError, OSError) as e:
        raise ProxError("Ungültige Antwort der Proxmox-API: %s" % e)


def collect():
    try:
        with open(CONFIG, "r", encoding="utf-8") as f:
            cfg = json.load(f)
    except (OSError, ValueError):
        raise ProxError("API-Konfiguration fehlt oder ist ungültig (Admin: API-Info).")
    host = str(cfg.get("host", "")).strip()
    token_id = str(cfg.get("token_id", "")).strip()
    secret = str(cfg.get("token_secret", ""))
    if not host or not token_id or not secret:
        raise ProxError("API-Zugangsdaten unvollständig (Host, Token-ID und Secret erforderlich).")
    port = int(cfg.get("port") or 8006)
    base = "https://%s:%d" % (host, port)
    auth = "PVEAPIToken=%s=%s" % (token_id, secret)
    ctx = ssl.create_default_context()
    if not cfg.get("verify_ssl", False):
        # Proxmox nutzt standardmäßig ein selbstsigniertes Zertifikat
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

    status = get(base, "/cluster/status", auth, ctx)
    resources = get(base, "/cluster/resources", auth, ctx)

    cluster = {"name": "", "quorate": None}
    nodes = {}
    for item in status:
        if item.get("type") == "cluster":
            cluster = {"name": item.get("name", ""), "quorate": bool(item.get("quorate"))}
        elif item.get("type") == "node":
            nodes[item.get("name", "")] = {"name": item.get("name", ""), "online": bool(item.get("online"))}
    for item in resources:
        if item.get("type") == "node" and item.get("node") not in nodes:
            nodes[item["node"]] = {"name": item["node"], "online": item.get("status") == "online"}

    guests = []
    for item in resources:
        if item.get("type") in ("qemu", "lxc"):
            guests.append({
                "vmid": item.get("vmid"),
                "name": item.get("name", ""),
                "type": item["type"],
                "status": item.get("status", "unknown"),
                "node": item.get("node", ""),
            })
    guests.sort(key=lambda g: g["vmid"] if isinstance(g["vmid"], int) else 0)
    return {
        "ok": True,
        "cluster": cluster,
        "nodes": sorted(nodes.values(), key=lambda n: n["name"]),
        "guests": guests,
    }


def main():
    try:
        out = collect()
        code = 0
    except ProxError as e:
        out, code = {"ok": False, "error": str(e)}, 1
    except Exception:
        out, code = {"ok": False, "error": "Unerwarteter Fehler im Python-Skript."}, 1
    print(json.dumps(out, ensure_ascii=False))
    return code


if __name__ == "__main__":
    sys.exit(main())
