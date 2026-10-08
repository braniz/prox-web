#!/usr/bin/env python3
"""Fragt die Proxmox-API ab und gibt das Ergebnis als JSON auf stdout aus.

Zugangsdaten stammen aus data/api.json (Admin: API-Info), nie aus Argumenten.
"""
import json
import os
import ssl
import sys
import urllib.error
import urllib.request

CONFIG = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data", "api.json")


class ApiError(Exception):
    pass


def load_config():
    try:
        with open(CONFIG, "r", encoding="utf-8") as f:
            cfg = json.load(f)
    except (OSError, ValueError):
        raise ApiError("API-Info nicht gefunden oder ungültig (Admin: API-Info).")
    for key in ("host", "token_id", "token_secret"):
        if not str(cfg.get(key, "")).strip():
            raise ApiError("API-Info unvollständig (Host, Token-ID und Token-Secret erforderlich).")
    return cfg


def fetch(cfg, path, ctx):
    host = str(cfg["host"]).strip()
    for prefix in ("https://", "http://"):
        if host.startswith(prefix):
            host = host[len(prefix):]
    host = host.rstrip("/")
    url = "https://%s:%d/api2/json/%s" % (host, int(cfg.get("port") or 8006), path)
    req = urllib.request.Request(url, headers={
        "Accept": "application/json",
        "Authorization": "PVEAPIToken=%s=%s" % (cfg["token_id"].strip(), cfg["token_secret"]),
    })
    try:
        with urllib.request.urlopen(req, timeout=10, context=ctx) as resp:
            return json.loads(resp.read().decode("utf-8")).get("data") or []
    except urllib.error.HTTPError as e:
        if e.code in (401, 403):
            raise ApiError("Zugriff verweigert (HTTP %d): Token-ID/Secret oder Berechtigungen prüfen." % e.code)
        raise ApiError("Proxmox-API-Fehler: HTTP %d" % e.code)
    except urllib.error.URLError as e:
        raise ApiError("Proxmox nicht erreichbar: %s" % e.reason)
    except (OSError, ValueError) as e:
        raise ApiError("Ungültige Antwort von Proxmox: %s" % e)


def main():
    try:
        cfg = load_config()
        # Proxmox nutzt meist ein selbstsigniertes Zertifikat
        ctx = ssl.create_default_context()
        if cfg.get("verify_ssl") is not True:
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
        status = fetch(cfg, "cluster/status", ctx)
        resources = fetch(cfg, "cluster/resources", ctx)

        cluster = next((i for i in status if i.get("type") == "cluster"), None)
        nodes = [
            {
                "name": n.get("name", ""),
                "online": bool(n.get("online")),
                "ip": n.get("ip", ""),
            }
            for n in status if n.get("type") == "node"
        ]
        guests = [
            {
                "vmid": r.get("vmid"),
                "name": r.get("name", ""),
                "type": r.get("type", ""),
                "status": r.get("status", ""),
                "node": r.get("node", ""),
            }
            for r in resources if r.get("type") in ("qemu", "lxc")
        ]
        guests.sort(key=lambda g: g["vmid"] or 0)
        result = {
            "ok": True,
            "cluster": {
                "name": cluster.get("name", "") if cluster else "",
                "quorate": bool(cluster.get("quorate")) if cluster else None,
                "standalone": cluster is None,
            },
            "nodes": nodes,
            "guests": guests,
        }
    except ApiError as e:
        result = {"ok": False, "error": str(e)}
    except Exception as e:  # noqa: BLE001
        result = {"ok": False, "error": "Unerwarteter Fehler: %s" % e}
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
