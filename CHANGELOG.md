# Changelog

Alle relevanten Änderungen werden in dieser Datei dokumentiert.

## [1.0] - 2026-09-30

Build 1. Vollständige Neuentwicklung. Nicht kompatibel mit den Instanzen früherer Versionen (neue Modul-GUIDs).

### Neue Struktur

- **Mammotion Cloud** (I/O, Präfix `MAMCLOUD`): Nutzungshinweis, Anmeldung, Token-Cache und alle HTTP-Anfragen für ein Mammotion-Konto
- **Mammotion Mäher** (Gerät, Präfix `MAMMO`): eine Instanz je Mähroboter mit eigener Kachel
- **Mammotion Konfigurator** (Präfix `MAMCONF`): listet die Mäher des Kontos und legt sie als „Mähroboter“ an

### Hinzugefügt

- Nutzungshinweis in der Cloud-Instanz; ohne Bestätigung werden keine Anfragen gesendet
- Schalter **Instanz aktiv** und Status-Block in Cloud- und Mäher-Instanz
- eigene Kachel für die Kachel-Visualisierung (HTML-SDK) mit Live-Updates, zustandsabhängigen Buttons, Bestätigung vor Start und Weiterfahrt, Akku-Ring, Signalbalken und Mähanimation; zeigt Modell statt App-Nickname, lässt Platz für Instanzname und Vergrößern-Symbol, passt sich an die Kachelgröße an; in der Instanz abschaltbar
- Statistik (`work-reports/summary`): Einsätze, gemähte Fläche, Zeitersparnis, CO₂-Einsparung
- letzter Einsatz (`work-reports/search` und Detail): Zeitpunkt, Ergebnis, Art, Fläche, Dauer, Fortschritt, Energie, Mähhöhe und Geschwindigkeit
- Fehlerprotokoll (`error-codes/search`): letzter Gerätefehler mit Code und Beschreibung, Anzahl der letzten 30 Tage; frische Fehler (24 h) erscheinen in der Kachel
- Schalter „Statistik, Einsatzverlauf und Fehlerprotokoll abrufen“
- Aufgaben, Statistik, Verlauf und Fehler werden alle 15 Minuten abgefragt (nach Fehler nach 5 Minuten, 2 Minuten nach Einsatzende, bei manuellem Abruf sofort)
- Betriebsstatus „In der Station“ bei Standby mit Ladestatus ungleich 0
- Cloud akzeptiert API-Code 0 und 200 als Erfolg

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
