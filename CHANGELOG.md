# Changelog

Alle relevanten Änderungen werden in dieser Datei dokumentiert.

## [2.1] - 2026-09-30

Build 2.

### Hinzugefügt

- **Nutzungshinweis** in der Cloud-Instanz mit Schalter „Gelesen – Nutzung auf eigene Verantwortung“. Solange er nicht bestätigt ist, sendet das Modul keine Anfragen (Cloud-Status 202).
- Schalter **Instanz aktiv** in Cloud- und Mäher-Instanz
- Status-Block in Cloud- und Mäher-Instanz mit Verbindung, Token, Mäher, Systemzustand, Diagnose und letztem Befehl
- eigenes Profil `MAMMO.Online` (Online/Offline) statt `~Switch`

### Geändert

- Konfigurator legt neue Mäher als „Mähroboter“ an (bei mehreren Mähern „Mähroboter <Nickname>“)
- Formulare der Mäher-Instanz in Einstellungen und Steuerung gegliedert, Dashboard-Schalter heißt „Dashboard-Kachel (HTML) aktiv“

### Korrigiert

- „Letzter Fehler“ der Cloud wird geleert, sobald die Verbindung wieder funktioniert oder die Konfiguration übernommen wird

### Hinweis zum Update

Nach dem Update ist die Cloud-Instanz gestoppt, bis der Nutzungshinweis einmal bestätigt und **Übernehmen** gedrückt wurde.

## [2.0] - 2026-09-30

Build 1. Vollständige Neuentwicklung. Nicht kompatibel mit 0.x/1.x-Instanzen (neue Modul-GUIDs).

### Neue Struktur

- **Mammotion Cloud** (I/O, Präfix `MAMCLOUD`): Anmeldung, Token-Cache und alle HTTP-Anfragen für ein Mammotion-Konto
- **Mammotion Mäher** (Gerät, Präfix `MAMMO`): eine Instanz je Mähroboter
- **Mammotion Konfigurator** (Präfix `MAMCONF`): listet die Mäher des Kontos und legt Instanzen per Klick an

### Hinzugefügt

- mehrere Mäher und mehrere Mammotion-Konten
- ein gemeinsamer Token je Konto, Token-Abruf per Semaphore gegen parallele Anfragen abgesichert
- Fehlerarten (vorübergehend, Anmeldung, API, offline) werden von der Cloud an die Mäher weitergegeben
- automatischer Anmeldeversuch alle 10 Minuten nach fehlgeschlagener Anmeldung
- eigenes Aufgabenprofil je Mäher, wird beim Löschen der Instanz entfernt
- Statusabruf 5 Sekunden nach jedem Steuerbefehl
- Variable **Letzter Befehl**
- Dashboard abschaltbar
- Profile mit Einheiten für Mähhöhe (mm) und Signalstärke (dBm)
- sauberer Start nach Neustart von IP-Symcon (`IPS_KERNELSTARTED`)
- Timer über `IPS_RequestAction` statt öffentlicher Hilfsfunktionen
- Cloud leitet nur `GET`/`POST` auf `/v1/…` weiter

### Geändert

- Zugangsdaten und Verbindungsschalter liegen in der Cloud-Instanz, der Mäher hat einen eigenen Schalter **Abruf aktiv**
- Token- und Cloudvariablen liegen in der Cloud-Instanz
- **Letzte Startprüfung**, **API-Status** und **Letztes API-Ergebnis** entfallen, zusammengefasst in **Systemzustand**, **Diagnose** und **Letzter Befehl**
- `MAMMO_StartCheck` entfällt, stattdessen `MAMMO_RefreshWithResult`
- `MAMMO_RenewToken` wird zu `MAMCLOUD_RenewToken`
- Refresh-Sperre mit Semaphore und automatischer Freigabe nach 180 Sekunden

## [1.0] - 2026-09-30

Build 1. Erste Hauptversion auf Basis von 0.7e1.

### Korrigiert

- Refresh-Sperre kann nicht mehr dauerhaft hängen bleiben. Die bisherige Boolean-Sperre `RefreshRunning` wird durch einen Zeitstempel mit Semaphore ersetzt. Eine Sperre, die älter als 300 Sekunden ist (z. B. nach Skript-Timeout oder Neustart), wird automatisch übernommen. `ApplyChanges()` setzt die Sperre immer zurück.
- Parallele Abrufe über Timer und Button werden über `IPS_SemaphoreEnter` atomar verhindert.
- Variable „Token gültig bis“ steht wieder direkt unter „Tokenstatus“ (Position 113, Diagnose 114). Bestehende Instanzen werden beim Übernehmen automatisch korrigiert.
- Dashboard zeigt API- und Cloudfehler wie dokumentiert in Orange statt als roten „Systemfehler“.
- Ungültige Zugangsdaten beim Token-Abruf (`invalid_client`, `unauthorized_client`) lösen keine Wiederholungsversuche mehr aus.
- Bei Offline-Erkennung bleibt der Grund in „Diagnose“ und die Offline-Meldung in „Letztes API-Ergebnis“ erhalten.

### Dokumentation

- README um `MAMMO_RefreshWithResult`, `MAMMO_RenewTokenWithResult`, `MAMMO_ExecuteAction` und `MAMMO_RetryRefresh` ergänzt.
- Fehlerbehebung zur Refresh-Sperre ergänzt.

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
