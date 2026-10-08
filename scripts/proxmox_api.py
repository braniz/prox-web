#!/usr/bin/env python3
"""Fragt die Proxmox-API serverseitig ab und gibt JSON auf stdout aus.

Liest die Zugangsdaten aus data/api.json (host, port, token_id, token_secret).
"""
import json
import os
import ssl
import sys
import urllib.error
import urllib.request

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CONFIG = os.environ.get("PROXWEB_API_CONFIG", os.path.join(BASE, "data", "api.json"))


class ApiError(Exception):
    pass


def load_config():
    try:
        with open(CONFIG, "r", encoding="utf-8") as f:
            cfg = json.load(f)
    except (OSError, ValueError):
        raise ApiError("API-Konfiguration fehlt oder ist ungültig (Admin: API-Info).")
    for key in ("host", "token_id", "token_secret"):
        if not str(cfg.get(key, "")).strip():
            raise ApiError("API-Konfiguration unvollständig (Admin: API-Info).")
    return cfg


def make_getter(cfg):
    host = str(cfg["host"]).strip()
    for prefix in ("https://", "http://"):
        if host.startswith(prefix):
            host = host[len(prefix):]
    host = host.rstrip("/")
    port = int(cfg.get("port") or 8006)
    base = "https://%s:%d/api2/json" % (host, port)
    auth = "PVEAPIToken=%s=%s" % (cfg["token_id"].strip(), cfg["token_secret"])
    ctx = ssl.create_default_context()
    if os.environ.get("PROXWEB_VERIFY_SSL", "0") != "1":
        # Proxmox nutzt standardmäßig ein selbstsigniertes Zertifikat
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE

    def get(path):
        req = urllib.request.Request(
            base + path, headers={"Authorization": auth, "Accept": "application/json"}
        )
        try:
            with urllib.request.urlopen(req, context=ctx, timeout=15) as resp:
                return json.loads(resp.read().decode("utf-8")).get("data", [])
        except urllib.error.HTTPError as e:
            if e.code in (401, 403):
                raise ApiError("Zugangsdaten ungültig oder Berechtigung fehlt (HTTP %d)." % e.code)
            raise ApiError("Proxmox-API-Fehler: HTTP %d." % e.code)
        except urllib.error.URLError as e:
            raise ApiError("Proxmox-API nicht erreichbar: %s" % e.reason)
        except (OSError, ValueError) as e:
            raise ApiError("Ungültige Antwort der Proxmox-API: %s" % e)

    return get


def main():
    try:
        get = make_getter(load_config())
        status = get("/cluster/status")
        resources = get("/cluster/resources")
        cluster = {"name": "", "quorate": None}
        node_online = {}
        for item in status:
            if item.get("type") == "cluster":
                cluster = {"name": item.get("name", ""), "quorate": bool(item.get("quorate"))}
            elif item.get("type") == "node":
                node_online[item.get("name")] = bool(item.get("online"))
        nodes, guests = [], []
        for r in resources:
            if r.get("type") == "node":
                name = r.get("node", "")
                nodes.append({
                    "name": name,
                    "online": node_online.get(name, r.get("status") == "online"),
                    "cpu": r.get("cpu"),
                    "mem": r.get("mem"),
                    "maxmem": r.get("maxmem"),
                })
            elif r.get("type") in ("qemu", "lxc"):
                guests.append({
                    "vmid": r.get("vmid"),
                    "name": r.get("name", ""),
                    "type": r.get("type"),
                    "status": r.get("status", ""),
                    "node": r.get("node", ""),
                })
        if not nodes:
            nodes = [{"name": n, "online": o} for n, o in node_online.items()]
        guests.sort(key=lambda g: g["vmid"] or 0)
        out = {"ok": True, "cluster": cluster, "nodes": nodes, "guests": guests}
        code = 0
    except ApiError as e:
        out, code = {"ok": False, "error": str(e)}, 1
    except Exception as e:
        out, code = {"ok": False, "error": "Unerwarteter Fehler: %s" % e}, 1
    print(json.dumps(out, ensure_ascii=False))
    return code


if __name__ == "__main__":
    sys.exit(main())
