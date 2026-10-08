#!/usr/bin/env python3
"""Fragt die Proxmox-API serverseitig ab und gibt JSON auf stdout aus.

Liest data/api.json (host, port, token_id, token_secret), das auch die
Admin-Seite "API-Info" schreibt. Optional: PROXWEB_API_CONFIG, PROXWEB_VERIFY_SSL=1.
"""
import json
import os
import ssl
import sys
import urllib.error
import urllib.request

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


class ApiError(Exception):
    pass


def load_config():
    path = os.environ.get("PROXWEB_API_CONFIG", os.path.join(BASE, "data", "api.json"))
    try:
        with open(path, "r", encoding="utf-8") as f:
            cfg = json.load(f)
    except (OSError, ValueError):
        raise ApiError("API-Info nicht gefunden. Bitte unter Admin: API-Info hinterlegen.")
    for key in ("host", "token_id", "token_secret"):
        if not str(cfg.get(key, "")).strip():
            raise ApiError("API-Info unvollständig (Host, Token-ID und Token-Secret erforderlich).")
    return cfg


def api_get(cfg, path, ctx):
    host = str(cfg["host"]).strip()
    for prefix in ("https://", "http://"):
        if host.startswith(prefix):
            host = host[len(prefix):]
    host = host.rstrip("/")
    port = int(cfg.get("port") or 8006)
    url = "https://%s:%d/api2/json/%s" % (host, port, path)
    auth = "PVEAPIToken=%s=%s" % (cfg["token_id"].strip(), cfg["token_secret"])
    req = urllib.request.Request(url, headers={"Authorization": auth, "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, context=ctx, timeout=15) as resp:
            return json.loads(resp.read().decode("utf-8")).get("data", [])
    except urllib.error.HTTPError as e:
        if e.code in (401, 403):
            raise ApiError("Zugangsdaten ungültig oder keine Berechtigung (HTTP %d)." % e.code)
        raise ApiError("Proxmox-API-Fehler: HTTP %d." % e.code)
    except urllib.error.URLError as e:
        raise ApiError("Proxmox-API nicht erreichbar: %s" % e.reason)
    except (ValueError, OSError) as e:
        raise ApiError("Ungültige Antwort der Proxmox-API: %s" % e)


def collect():
    cfg = load_config()
    ctx = ssl.create_default_context()
    if os.environ.get("PROXWEB_VERIFY_SSL", "0") != "1":
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

    status = api_get(cfg, "cluster/status", ctx)
    nodes = api_get(cfg, "nodes", ctx)
    resources = api_get(cfg, "cluster/resources?type=vm", ctx)

    cluster = {"name": "Standalone", "status": "Einzelner Node"}
    for item in status:
        if item.get("type") == "cluster":
            cluster = {
                "name": item.get("name", ""),
                "status": "Quorum vorhanden" if item.get("quorate") else "Kein Quorum",
            }
            break

    return {
        "ok": True,
        "cluster": cluster,
        "nodes": [
            {"node": n.get("node", ""), "online": n.get("status") == "online",
             "status": n.get("status", "unknown")}
            for n in nodes
        ],
        "vms": [
            {"vmid": v.get("vmid"), "name": v.get("name", ""), "type": v.get("type", ""),
             "status": v.get("status", "unknown"), "node": v.get("node", "")}
            for v in sorted(resources, key=lambda r: r.get("vmid", 0))
        ],
    }


def main():
    try:
        result = collect()
        code = 0
    except ApiError as e:
        result, code = {"ok": False, "error": str(e)}, 1
    except Exception as e:
        result, code = {"ok": False, "error": "Unerwarteter Fehler: %s" % e}, 1
    print(json.dumps(result, ensure_ascii=False))
    return code


if __name__ == "__main__":
    sys.exit(main())
