# prox-web

**prox-web** ist eine webbasierte Anwendung zur Anzeige und Verwaltung von Informationen rund um ein **Proxmox-Cluster**. Das Projekt stellt zentrale Daten zu **Hosts**, **VMs** und dem **Cluster-Status** in einer übersichtlichen Oberfläche dar.

## Funktionen

- Übersicht über das Proxmox-Cluster
- Anzeige relevanter Informationen zu virtuellen Maschinen
- Zentrale Weboberfläche für den schnellen Zugriff auf Cluster-Daten
- Klar strukturierte Darstellung für eine einfache Nutzung

## Ziel des Projekts

Ziel von **prox-web** ist es, wichtige Informationen aus der Proxmox-Umgebung schnell und komfortabel im Browser verfügbar zu machen. Dadurch soll die Verwaltung und Überwachung des Clusters vereinfacht werden.

## Hinweis

Dieses Projekt wurde **mithilfe von KI generiert**.

## Projektstatus

Das Projekt befindet sich in der Weiterentwicklung und kann bei Bedarf um weitere Funktionen ergänzt werden.

## Erste Phase: Apache-Grundgerüst (ohne Proxmox)

- `public/index.php` – Benutzer-Seite (zeigt nur „wird gearbeitet“)
- `public/admin/users.php` – Adminbereich: Benutzerverwaltung
- `public/admin/api.php` – Adminbereich: API-Info Eingabe
- `src/bootstrap.php` – gemeinsame Hilfsfunktionen
- `data/` – JSON-Speicher (für Apache gesperrt)
- `public/login.php`, `public/logout.php` – Anmeldung/Abmeldung (PHP-Sessions)
- `public/profile.php` – eigene Benutzerseite mit Passwortänderung
- `apache/prox-web.conf` – Beispiel-VirtualHost (ohne Basic-Auth; `data/` und `src/` gesperrt)

Voraussetzungen: Apache 2.4 mit PHP ≥ 7.4; `data/` muss für den Webserver-Benutzer beschreibbar sein
(`chown www-data data`). Zum lokalen Test: `php -S localhost:8000 -t public`.

## Anmeldung und Benutzer

- Alle Seiten erfordern eine Anmeldung (Passwörter werden nur mit `password_hash` gespeichert).
- **Initiale Zugangsdaten: `admin` / `admin`.** Sie werden automatisch angelegt, wenn noch kein Benutzer existiert.
  Das Passwort **muss** bei der ersten Anmeldung geändert werden; bis dahin wird man auf die Profilseite umgeleitet.
- Rollen: `admin` (Zugriff auf `/admin/*`) und `user`.
- Benutzerverwaltung (`/admin/users.php`): Anlegen mit Username, Vorname, Nachname, E-Mail, Passwort und Gruppe;
  darunter Tabelle `Username | Nachname, Vorname | Email | Gruppenname` mit Löschen-Funktion.
  Neue Benutzer müssen ihr Passwort bei der ersten Anmeldung ändern. Der letzte Admin kann nicht gelöscht werden.
- Eigene Seite (`/profile.php`): Passwort ändern (aktuelles Passwort, neu, Wiederholung; min. 8 Zeichen).
- Fehlanmeldungen werden pro IP gedrosselt (5 Versuche, dann 5 Minuten Sperre).
- Apache: Der bisherige Basic-Auth-Schutz für `/admin` entfällt; `apache/prox-web.conf` sperrt nur noch `data/` und `src/`.

## Proxmox-Info (Python-Bridge)

- `python/proxmox_info.py` liest per Proxmox-API (API-Token aus `data/api.json`, gepflegt unter `/admin/api.php`)
  Cluster-, Node- und VM-Daten und gibt JSON aus. Nur Python-Standardbibliothek, Python ≥ 3.6 (`apt install python3`).
- `public/proxmox.php` (für alle angemeldeten Benutzer) ruft das Skript über `proxmox_fetch()` in `src/bootstrap.php` auf
  und rendert die Daten. Zugangsdaten bleiben serverseitig und werden nie an den Browser gesendet.
- Fehler (Python fehlt, Token ungültig, Proxmox nicht erreichbar) werden als Meldung angezeigt.
- Optionale Umgebungsvariablen (z. B. per Apache `SetEnv`/PHP-FPM `env[...]`):
  `PROX_PYTHON` (Interpreter, Default `python3`), `PROX_API_FILE`, `PROX_CA_FILE` (CA für selbstsigniertes
  Zertifikat), `PROX_INSECURE=1` (TLS-Prüfung aus, nicht empfohlen), `PROX_TIMEOUT` (Sekunden, Default 10).
- Der Token braucht die Rechte `Sys.Audit` und `VM.Audit`. Manueller Test: `python3 python/proxmox_info.py`.
