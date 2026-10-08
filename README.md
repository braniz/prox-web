# prox-web

prox-web ist eine PHP-basierte Weboberfläche für die Verwaltung und Übersicht eines Proxmox-Clusters. Die Anwendung zeigt Cluster-, Knoten- und Ressourceninformationen an, ermöglicht die Bearbeitung lokaler Benutzer und stellt Detailansichten für QEMU-VMs und LXC-Container zur Verfügung.

## Überblick

Das Projekt kombiniert:

- eine PHP-Weboberfläche mit Login, Rollenverwaltung und Navigation
- einen Python-Client für Proxmox-API-Abfragen
- Detailseiten für einzelne Ressourcen mit CPU-, RAM-, Disk- und Netzwerkinformationen
- eine lokale Dateiverwaltung für Host-Informationen und Kommentare
- eine Admin-Schnittstelle für API-Konfiguration und Benutzerverwaltung

## Kernfunktionen

- Übersicht über den Proxmox-Cluster und die Knoten
- Darstellung von VMs, LXC-Containern und Docker-Objekten in einer Ressourcenübersicht
- Detailseite pro Ressource mit:
  - Status, Typ, Namen, IP-Adressen und Host-Informationen
  - QEMU- und LXC-spezifischen Auswertungen
  - Gruppierten Infos nach CPU, RAM, Disk, Netzwerk, Prozessen, Uptime und HA
  - Lesbaren Werten in menschlich verständlichen Einheiten (MB/GB, Zeitdarstellungen usw.)
- Verwaltung lokaler Benutzer mit Rollen `admin` und `user`
- Konfiguration der Proxmox-API mit Host, Port, TLS, Zertifikatsprüfung und API-Token
- Speicherung von Host-Kommentaren und Host-Info-Texten im Webserver-Dateisystem statt direkt im Gast
- Robustheit bei fehlender Konfiguration, API-Fehlern oder Netzwerkproblemen mit konkreten Fehlermeldungen

## Projektstruktur

- `public/` – Web-Frontend und Seiten
  - `public/index.php` – Cluster-Übersicht nach Login
  - `public/resources.php` – Gesamtübersicht aller Ressourcen
  - `public/resource.php` – Detailansicht für einzelne VM/LXC/Docker-Objekte
  - `public/login.php` – Login
  - `public/logout.php` – Abmeldung
  - `public/profile.php` – Passwortänderung des eigenen Kontos
  - `public/admin/users.php` – Benutzerverwaltung
  - `public/admin/api.php` – API-Konfiguration
- `src/bootstrap.php` – zentrale Hilfsfunktionen, Authentifizierung, Menü, Datenabruf und gemeinsame Render-Logik
- `python/proxmox.py` – Python-Schnittstelle zur Proxmox-API
- `data/` – lokale JSON- und Kommentardateien
  - `data/api.json` – Proxmox-API-Konfiguration
  - `data/users.json` – Benutzer
  - `data/login_attempts.json` – Sperrung von Fehlversuchen
  - `data/host_comments/` – Kommentare zu Hosts/Ressourcen auf dem Webserver
- `tests/test_proxmox.py` – automatisierte Regressionstests
- `apache/prox-web.conf` – Beispielkonfiguration für Apache

## Voraussetzungen

- Apache 2.4 oder ein anderer PHP-fähiger Webserver
- PHP 7.4 oder höher
- Python 3
- Zugriff auf den Proxmox-Host bzw. dessen API auf dem Netzwerk
- Schreibzugriff auf `data/` für den Webserver-Benutzer

Empfohlener lokaler Start:

```bash
php -S localhost:8000 -t public
```

## Proxmox-API konfigurieren

Die Konfiguration wird in `data/api.json` gespeichert. Wichtige Felder:

```json
{
  "host": "proxmox.example.com",
  "port": 8006,
  "tls": true,
  "verify_certificate": true,
  "token_id": "user@pam!prox-web",
  "token_secret": "xxxxx"
}
```

Der Admin-Bereich unter `Admin -> API-Info` erlaubt die Eingabe von:

- Host oder IP des Proxmox-Servers
- Port
- TLS aktiv/inaktiv
- Zertifikatsprüfung aktiv/inaktiv
- API-Token-ID
- API-Token-Secret

Die Logik berücksichtigt dabei die Standardwerte für TLS und Zertifikatsprüfung. Wenn TLS aktiviert ist, wird `https://` verwendet. Wenn TLS deaktiviert ist, wird `http://` verwendet.

## Login und Benutzerverwaltung

### Standardzugang

Beim ersten Start, wenn noch keine Benutzer existieren, wird automatisch ein Admin-Benutzer angelegt:

- Benutzername: `admin`
- Passwort: `admin`

Bei der ersten Anmeldung muss das Passwort geändert werden. Bis dahin wird der Benutzer auf die Profilseite weitergeleitet.

### Rollen

- `admin` – Zugriff auf Administratorfunktionen
- `user` – normaler Benutzerzugriff

### Benutzerfunktionen

- Benutzer können sich anmelden und die Cluster-Übersicht ansehen
- Admins können Benutzer anlegen, löschen und verwalten
- Das Profil erlaubt die Änderung des eigenen Passworts
- Fehlversuche werden pro IP geblockt (5 Versuche, dann Sperre für 5 Minuten)

## Ressourcenansichten

### Ressourcenübersicht

In `public/resources.php` werden alle gefundenen Objekte aus Proxmox aufgelistet, inklusive:

- QEMU-VMs
- LXC-Container
- Docker-Objekte

Dabei werden passende IP-Adressen ermittelt und die Detailansicht über die Ressource verlinkt.

### Detailseite für einzelne Ressourcen

`public/resource.php` stellt für eine ausgewählte Ressource eine Detailansicht bereit. Je nach Typ werden unterschiedliche Datenquellen verwendet:

- QEMU: `qm guest cmd`-Aufrufe sowie Proxmox-Status/Config-Daten
- LXC: `pvesh get /nodes/<node>/lxc/<id>/status/current` und `pvesh get /nodes/<node>/lxc/<id>/config`

Darauf basierend werden Werte wie CPU, RAM, Festplatten, Netzwerk, Prozesse und Uptime in lesbarer Form dargestellt.

## Host-Info und Kommentare

Die Detailseite enthält zusätzlich:

- Host-Info aus `/srv/info/host.info` oder der angepassten Datei im Gast
- Kommentarbereich für den Host
- Historie der Kommentare aus `data/host_comments/`

Wichtiger Punkt: Kommentare und Host-Notizen werden auf dem Webserver gespeichert und nicht direkt im Proxmox-Gast-System. Dafür wird das lokale Dateisystem unter `data/host_comments/` verwendet.

## Proxmox-Integration

Die Python-Komponente in `python/proxmox.py` verwendet nur die Standardbibliothek und ruft Proxmox-APIs ab, ohne Änderungen am Cluster vorzunehmen. Sie liest unter anderem:

- `/cluster/status`
- `/nodes`
- `/cluster/resources`
- Status und Konfiguration von QEMU/LXC-Objekten
- `qm guest cmd`-Ergebnisse für Gastinformationen
- Dateien aus dem Gast, soweit geeignet

Bei fehlender Konfiguration, fehlerhaften Token, fehlendem Netzwerk oder API-Fehlern wird eine verständliche Fehlermeldung ausgegeben.

## Sicherheit und Betriebsmodell

- Passwörter werden als Hash gespeichert
- Nutzer werden nur nach erfolgreichem Login auf Seiten zugelassen
- Rollen erlauben eine klare Trennung zwischen Standardbenutzern und Admins
- Der Apache-Block `apache/prox-web.conf` schützt die sensiblen Bereiche `data/` und `src/`
- Die bisherige Basic-Auth für `/admin` wurde durch die eigene Login-Logik ersetzt

## Tests

Die automatisierten Tests werden mit folgendem Befehl ausgeführt:

```bash
PYTHONPATH=python python3 -m unittest tests.test_proxmox
```

oder:

```bash
python3 -m unittest discover -s tests
```

## Hinweise zur Bedienung

1. API-Zugang im Adminbereich hinterlegen.
2. Login mit Admin- oder Benutzerkonto.
3. Über die Navigation die Übersicht oder die Ressource-Liste öffnen.
4. Eine Ressource auswählen, um die Detailseite mit den gruppierten Informationen zu öffnen.
5. Host-Kommentare und Host-Info-Dateien verwalten.

## Projektstatus

Das Projekt ist in aktiver Weiterentwicklung und enthält bereits eine vollständige Weboberfläche für Proxmox-Clusterübersicht, Benutzerverwaltung, API-Konfiguration, Ressourcen-Detailansichten und Host-Kommentar-Funktionen.

## Hinweis

Dieses Projekt wurde in einer KI-gestützten Entwicklungsarbeit erstellt und schrittweise an die tatsächliche Proxmox-Integration und UI-Anforderungen angepasst.
