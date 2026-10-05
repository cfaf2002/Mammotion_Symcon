# Changelog

Alle relevanten Änderungen werden in dieser Datei dokumentiert.

## [1.0] - 2026-10-05

Build 1. Vollständige Neuentwicklung. Nicht kompatibel mit den Instanzen früherer Versionen (neue Modul-GUIDs).

### Technik IP-Symcon 9.0

- alle Module auf die Basisklasse `IPSModuleStrict` umgestellt (typisierte Signaturen, Datenfluss über `GetCompatibleParents()` statt `ConnectParent`)
- Variablen-Darstellungen statt Profilen: Wertanzeige mit Intervallen für Statuscodes und Einheiten (inklusive Umrechnung m²/ha, min/h, Wh/kWh, kg/t), Aufzählung für Steuerung und Aufgaben, Datum/Uhrzeit für Zeitstempel
- Aufgabenliste direkt in der Darstellung der Variable, kein Profil je Instanz mehr
- Profile früherer Versionen werden einmalig entfernt, sofern unbenutzt
- PHP 8.5: veraltetes `curl_close()` entfernt
- gemeinsamer Helfer `libs/PresentationHelper.php`

### Geschwindigkeit

- ein API-Aufruf je Abruf statt zwei (Geräteliste nur noch zur Ermittlung der Device-ID oder bei Fehlern)
- flüchtige Zustände im Buffer statt in Attributen, Attribute nur bei Änderung: im Normalbetrieb keine Schreibzugriffe auf die Einstellungen
- Kachel-Updates nur bei geändertem Inhalt
- komprimierte HTTP-Antworten

### Sicherheit

- TLS-Prüfung explizit, nur HTTPS, keine Weiterleitungen, Antwortgröße begrenzt
- strengere Prüfung der weitergeleiteten API-Pfade
- Gerätebild nur über HTTPS, Kachel-Hintergrund nur als Rasterbild (kein SVG)

### Lizenz

- SPDX-Kennzeichner in allen Quelldateien, Lizenz- und Fremdbestandteile in der README dokumentiert

### Neue Struktur

- **Mammotion Cloud** (I/O, Präfix `MAMCLOUD`): Nutzungshinweis, Anmeldung, Token-Cache und alle HTTP-Anfragen für ein Mammotion-Konto
- **Mammotion Mäher** (Gerät, Präfix `MAMMO`): eine Instanz je Mähroboter mit eigener Kachel
- **Mammotion Konfigurator** (Präfix `MAMCONF`): listet die Mäher des Kontos und legt sie als „Mähroboter“ an

### Hinzugefügt

- Nutzungshinweis in der Cloud-Instanz; ohne Bestätigung werden keine Anfragen gesendet
- Schalter **Instanz aktiv** und Status-Block in Cloud- und Mäher-Instanz
- eigene Kachel für die Kachel-Visualisierung (HTML-SDK) mit Live-Updates, zustandsabhängigen Buttons, Bestätigung vor Start und Weiterfahrt, Akku-Ring, Signalbalken und Mähanimation; zeigt Modell statt App-Nickname, lässt Platz für Instanzname und Vergrößern-Symbol, passt sich an die Kachelgröße an; in der Instanz abschaltbar
- Kachel mit fester Befehlsleiste (Pause, Fortsetzen, Stop, Zur Ladestation, Heimfahrt abbrechen) zusätzlich zu „Aufgabe wählen + Starten“; passende Befehle hervorgehoben, Starten und Fortsetzen mit Bestätigung
- Kachel-Hintergrund wählbar: Farbverlauf, Bild aus einem Symcon-Medienobjekt (mit Abdunklung, als Referenz registriert) oder transparent für den Hintergrund der Kachel-Visualisierung
- Statistik (`work-reports/summary`): Einsätze, gemähte Fläche, Zeitersparnis, CO₂-Einsparung
- letzter Einsatz (`work-reports/search` und Detail): Zeitpunkt, Ergebnis, Art, Fläche, Dauer, Fortschritt, Energie, Mähhöhe und Geschwindigkeit
- Fehlerprotokoll (`error-codes/search`): letzter Gerätefehler mit Code und Beschreibung, Anzahl der letzten 30 Tage; in der Kachel rot nur bei aktivem Gerätefehler oder Meldungen der letzten 30 Minuten, ältere Meldungen der letzten 24 Stunden als Verlaufszeile mit Uhrzeit
- Schalter „Statistik, Einsatzverlauf und Fehlerprotokoll abrufen“
- Aufgaben, Statistik, Verlauf und Fehler werden alle 15 Minuten abgefragt (nach Fehler nach 5 Minuten, 2 Minuten nach Einsatzende, bei manuellem Abruf sofort)
- Betriebsstatus „In der Station“ bei Standby mit Ladestatus ungleich 0
- Cloud akzeptiert API-Code 0 und 200 als Erfolg
- Statistik und Verlauf probieren mehrere gültige Anfrageformen (mit und ohne Zeitraum und Seitenangabe) und merken sich die, die die API akzeptiert
- lehnt die API Statistik oder Verlauf fachlich ab (z. B. Code 40200), gilt das nicht als Fehler: Anzeige „derzeit nicht bereitgestellt“, neuer Versuch nach 6 Stunden oder bei „Jetzt aktualisieren“

### Sicherheit

- `GET /v1/mower/{deviceId}/work-params` wird nicht mehr aufgerufen. Laut der Home-Assistant-Integration hat ein LUBA 2 nach diesem Aufruf unerwartet zu mähen begonnen. Mähhöhe und Geschwindigkeit kommen jetzt aus dem Bericht des letzten Einsatzes.
- mehrere Mäher und mehrere Mammotion-Konten
- ein gemeinsamer Token je Konto, Token-Abruf per Semaphore abgesichert
- Fehlerarten (vorübergehend, Anmeldung, API, offline) werden von der Cloud an die Mäher weitergegeben
- automatischer Anmeldeversuch alle 10 Minuten nach fehlgeschlagener Anmeldung
- „Letzter Fehler“ der Cloud wird geleert, sobald die Verbindung wieder funktioniert
- Refresh-Sperre mit Semaphore und automatischer Freigabe nach 180 Sekunden
- zwei Wiederholungen bei vorübergehenden Fehlern (5 und 15 Sekunden)
- eigenes Aufgabenprofil je Mäher, wird beim Löschen der Instanz entfernt
- Statusabruf 5 Sekunden nach jedem Steuerbefehl, Variable **Letzter Befehl**
- Profile `MAMMO.Online` (Online/Offline), Mähhöhe in mm, Signalstärke in dBm
- sauberer Start nach Neustart von IP-Symcon

### Entfernt

- HTMLBox-Variable **Dashboard** (ersetzt durch die Kachel der Instanz)
- **Letzte Startprüfung**, **API-Status**, **Letztes API-Ergebnis** (zusammengefasst in **Systemzustand**, **Diagnose** und **Letzter Befehl**)
- `MAMMO_StartCheck` (stattdessen `MAMMO_RefreshWithResult`), `MAMMO_RenewToken` (jetzt `MAMCLOUD_RenewToken`)

## [0.7e1] - 2026-09-23

### Korrigiert

- `SystemState` wird bei jedem Refresh auf `Prüfung läuft` gesetzt.
- `CompleteSuccess()` aktualisiert den Systemzustand auch bei normalen Timer-Abrufen.
- Teilweise erfolgreiche Abrufe setzen den Zustand immer auf `Teilweise verfügbar`.
- Offline-Erkennung setzt den Zustand auch außerhalb der Startprüfung zuverlässig auf `Offline`.

### Dokumentation

- vollständige README erweitert
- Statusvariablen und Zeitstempel erklärt
- API-Endpunkte, Tokenverwaltung, FAQ und Fehlerbehebung dokumentiert

## [0.7e]

### Hinzugefügt

- Premium-Dashboard
- automatische Übernahme von Nickname, Modell und Gerätebild
- Akku-Ring und WLAN-Qualitätsanzeige

## [0.7c]

- Objektbaum bereinigt
- Entwickler-Variablen entfernt

## [0.7b]

- Verbindungsschalter
- deaktivierter Systemzustand
- Token-Ablaufanzeige
