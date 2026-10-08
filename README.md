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
- `apache/prox-web.conf` – Beispiel-VirtualHost (inkl. Basic-Auth für `/admin`)

Voraussetzungen: Apache 2.4 mit PHP ≥ 7.4; `data/` muss für den Webserver-Benutzer beschreibbar sein
(`chown www-data data`). Zum lokalen Test: `php -S localhost:8000 -t public`.
