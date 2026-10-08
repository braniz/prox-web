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

## Proxmox-Info

- `/proxmox.php` (für alle angemeldeten Benutzer) zeigt Cluster-Status, Nodes (online/offline) sowie VMs und Container.
- `scripts/proxmox_api.py` (nur Python-3-Standardbibliothek) fragt die Proxmox-API serverseitig ab und gibt JSON aus;
  `src/proxmox.php` ruft das Skript auf und meldet Fehler (kein Python, fehlende/ungültige API-Info, API nicht erreichbar).
- Zugangsdaten: die unter `/admin/api.php` gespeicherten Werte (`data/api.json`: Host, Port, Token-ID, Token-Secret).
  Sie verlassen den Server nie; das Secret wird nicht per Kommandozeile übergeben.
- Voraussetzungen: `apt install python3`; PHP-Funktion `proc_open` darf nicht deaktiviert sein.
  Der API-Token braucht Leserechte (z. B. Rolle `PVEAuditor`).
- TLS: Selbstsignierte Zertifikate werden standardmäßig akzeptiert; zur Prüfung `"verify_ssl": true` in `data/api.json` setzen.
- Apache: `scripts/` liegt außerhalb des Webroots und ist in `apache/prox-web.conf` zusätzlich gesperrt.
