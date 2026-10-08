#!/usr/bin/env python3
"""Liest Cluster-, Node- und VM-Infos aus der Proxmox-API und gibt JSON auf stdout aus.

Zugangsdaten stammen aus data/api.json (Host, Port, Token-ID, Token-Secret) und
verlassen den Server nie. Nur Python-Standardbibliothek (>= 3.6).

Umgebungsvariablen:
  PROX_API_FILE   Pfad zur api.json (Default: ../data/api.json)
  PROX_CA_FILE    CA-Zertifikat zum Prüfen eines selbstsignierten Proxmox-Zertifikats
  PROX_INSECURE   "1" deaktiviert die TLS-Prüfung (nicht empfohlen)
  PROX_TIMEOUT    Timeout in Sekunden (Default: 10)
"""
import json
import os
import ssl
import sys
import urllib.error
import urllib.request

BASE = os.path.dirname(os.path.abspath(__file__))


class ProxError(Exception):
    pass


def load_config():
    path = os.environ.get("PROX_API_FILE") or os.path.join(BASE, "..", "data", "api.json")
    try:
        with open(path, encoding="utf-8") as f:
            cfg = json.load(f)
    except (OSError, ValueError):
        raise ProxError("API-Konfiguration fehlt oder ist ungültig (Admin: API-Info).")
    for key in ("host", "token_id", "token_secret"):
        if not cfg.get(key):
            raise ProxError("API-Konfiguration unvollständig: '%s' fehlt." % key)
    return cfg


def ssl_context():
    if os.environ.get("PROX_INSECURE") == "1":
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        return ctx
    return ssl.create_default_context(cafile=os.environ.get("PROX_CA_FILE") or None)


def api_get(cfg, path, ctx):
    url = "https://%s:%d/api2/json%s" % (cfg["host"], int(cfg.get("port") or 8006), path)
    req = urllib.request.Request(url, headers={
        "Authorization": "PVEAPIToken=%s=%s" % (cfg["token_id"], cfg["token_secret"]),
    })
    timeout = float(os.environ.get("PROX_TIMEOUT", "10"))
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            return json.load(resp).get("data")
    except urllib.error.HTTPError as e:
        if e.code in (401, 403):
            raise ProxError("Anmeldung an Proxmox fehlgeschlagen (Token ungültig oder keine Rechte).")
        raise ProxError("Proxmox-API antwortete mit HTTP %d." % e.code)
    except ssl.SSLError as e:
        raise ProxError("TLS-Fehler: %s (siehe PROX_CA_FILE / PROX_INSECURE)." % e)
    except (urllib.error.URLError, OSError) as e:
        raise ProxError("Proxmox nicht erreichbar: %s" % getattr(e, "reason", e))
    except ValueError:
        raise ProxError("Ungültige Antwort der Proxmox-API.")


def collect():
    cfg = load_config()
    ctx = ssl_context()
    status = api_get(cfg, "/cluster/status", ctx) or []
    nodes = api_get(cfg, "/nodes", ctx) or []
    resources = api_get(cfg, "/cluster/resources?type=vm", ctx) or []
    cluster = next((s for s in status if s.get("type") == "cluster"), {})
    return {
        "cluster": {
            "name": cluster.get("name"),
            "quorate": bool(cluster.get("quorate")),
            "nodes": cluster.get("nodes"),
        },
        "nodes": [{
            "name": n.get("node"), "status": n.get("status"),
            "cpu": n.get("cpu"), "maxcpu": n.get("maxcpu"),
            "mem": n.get("mem"), "maxmem": n.get("maxmem"), "uptime": n.get("uptime"),
        } for n in nodes],
        "vms": [{
            "vmid": r.get("vmid"), "name": r.get("name"), "node": r.get("node"),
            "type": r.get("type"), "status": r.get("status"),
            "cpu": r.get("cpu"), "maxcpu": r.get("maxcpu"),
            "mem": r.get("mem"), "maxmem": r.get("maxmem"), "uptime": r.get("uptime"),
        } for r in sorted(resources, key=lambda r: r.get("vmid") or 0)],
    }


def main():
    try:
        out = {"ok": True, "data": collect()}
    except ProxError as e:
        out = {"ok": False, "error": str(e)}
    except Exception:
        out = {"ok": False, "error": "Unerwarteter Fehler im Python-Helper."}
    print(json.dumps(out))
    return 0 if out["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
