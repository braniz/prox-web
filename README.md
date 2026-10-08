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

## Proxmox-Clusteransicht

- `public/index.php` – angemeldete Benutzer sehen Clusterstatus, Knoten sowie VMs und Container
- `python/proxmox.py` – Python-Client für die schreibgeschützte Proxmox-API
- `public/admin/users.php` – Adminbereich: Benutzerverwaltung
- `public/admin/api.php` – Adminbereich: API-Info Eingabe
- `src/bootstrap.php` – gemeinsame Hilfsfunktionen
- `data/` – JSON-Speicher (für Apache gesperrt)
- `public/login.php`, `public/logout.php` – Anmeldung/Abmeldung (PHP-Sessions)
- `public/profile.php` – eigene Benutzerseite mit Passwortänderung
- `apache/prox-web.conf` – Beispiel-VirtualHost (ohne Basic-Auth; `data/` und `src/` gesperrt)

Voraussetzungen: Apache 2.4 mit PHP ≥ 7.4 und Python 3; `data/` muss für den Webserver-Benutzer
beschreibbar sein (`chown www-data data`). PHP startet `python3 python/proxmox.py` lokal und übergibt
die API-Zugangsdaten über die Standardeingabe. Der Webserver-Benutzer benötigt daher Zugriff auf
Python 3 und ausgehenden HTTPS-Zugriff auf den Proxmox-Host. Die TLS-Zertifikatsprüfung bleibt aktiv;
das Proxmox-Zertifikat muss vom System als vertrauenswürdig erkannt werden. In der Admin-Seite
`/admin/api.php` Hostname oder IP (ohne Schema), Port, TLS (`ja`/`nein`) und Proxmox-API-Token hinterlegen.
Zum lokalen Test: `php -S localhost:8000 -t public`.

Der Python-Client nutzt ausschließlich die Python-Standardbibliothek, fragt `/cluster/status`,
`/nodes` und `/cluster/resources` ab und nimmt keine Änderungen am Cluster vor. Bei fehlender
Konfiguration oder API-/Netzwerkfehlern erscheint eine konkrete Fehlermeldung anstelle von Dummy-Daten.
Die Python-Tests lassen sich mit `python3 -m unittest discover -s tests` ausführen.



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
