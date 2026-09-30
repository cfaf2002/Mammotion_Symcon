# Mammotion Open API für IP-Symcon

[![Version](https://img.shields.io/badge/version-2.1-blue.svg)](https://github.com/cfaf2002/MammotionOpenAPI)
[![Build](https://img.shields.io/badge/build-2-blue.svg)](https://github.com/cfaf2002/MammotionOpenAPI)
[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-9.0%2B-orange.svg)](https://www.symcon.de/)
[![PHP](https://img.shields.io/badge/PHP-8.x-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![Mammotion Open API](https://img.shields.io/badge/Mammotion-Open%20API-success.svg)](https://developer.mammotion.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Integration von Mammotion-Mährobotern in IP-Symcon über die offizielle Mammotion Open API.

Version **2.1** (Build 2) baut auf der Neuentwicklung 2.0 auf. Sie ist eine vollständige Neuentwicklung mit der für IP-Symcon üblichen Aufteilung in Cloud-, Geräte- und Konfigurator-Instanz. Sie ist **nicht** mit den 0.x- und 1.x-Instanzen kompatibel (neue Modul-GUIDs). Alte Instanzen vor der Installation löschen, siehe [Umstieg von 1.x](#umstieg-von-1x).

## Inhaltsverzeichnis

- [Aufbau](#aufbau)
- [Funktionsumfang](#funktionsumfang)
- [Voraussetzungen](#voraussetzungen)
- [Projektstruktur](#projektstruktur)
- [Installation](#installation)
- [Einrichtung](#einrichtung)
- [Mammotion Cloud](#mammotion-cloud)
- [Mammotion Konfigurator](#mammotion-konfigurator)
- [Mammotion Mäher](#mammotion-mäher)
- [Dashboard](#dashboard)
- [PHP-Funktionen](#php-funktionen)
- [Verwendete API-Endpunkte](#verwendete-api-endpunkte)
- [Fehlerbehandlung und Wiederholungen](#fehlerbehandlung-und-wiederholungen)
- [Sicherheitshinweise](#sicherheitshinweise)
- [Fehlerbehebung](#fehlerbehebung)
- [Umstieg von 1.x](#umstieg-von-1x)
- [Bekannte Einschränkungen](#bekannte-einschränkungen)
- [Nutzungshinweis](#nutzungshinweis)
- [Lizenz](#lizenz)
- [Haftung und Markenhinweis](#haftung-und-markenhinweis)

## Aufbau

```text
Mammotion Cloud (I/O)            Anmeldung, Token, alle HTTP-Anfragen
├── Mammotion Konfigurator       listet die Mäher des Kontos und legt Instanzen an
├── Mammotion Mäher "Horst"      Status, Werte, Steuerung, Dashboard
└── Mammotion Mäher "..."        weitere Mäher desselben Kontos
```

Die Zugangsdaten werden nur einmal in der Cloud-Instanz hinterlegt. Alle Mäher desselben Kontos teilen sich einen Token. Mehrere Mammotion-Konten sind über mehrere Cloud-Instanzen möglich.

## Funktionsumfang

### Mammotion Cloud

- Nutzungshinweis, der vor der ersten Anfrage bestätigt werden muss
- Schalter **Instanz aktiv** und Status-Block im Konfigurationsformular
- OAuth2-Anmeldung über Client-ID und Client-Secret
- Token-Cache mit Erneuerung fünf Minuten vor Ablauf, Refresh-Token mit Fallback auf Client-Credentials
- Token-Abruf gegen parallele Anfragen mehrerer Mäher abgesichert
- einmalige Neuanmeldung nach HTTP 401
- Einordnung jeder Störung (vorübergehend, Anmeldung, API, Mäher offline) für die Mäher-Instanzen
- automatischer neuer Anmeldeversuch alle 10 Minuten nach fehlgeschlagener Anmeldung

### Mammotion Konfigurator

- listet alle Mäher des Kontos mit Name, Modell, Device-ID und Online-Status
- legt Mäher-Instanzen mit hinterlegter Device-ID per Klick an, Name „Mähroboter“ (bei mehreren Mähern „Mähroboter <Nickname>“)
- zeigt vorhandene Instanzen und Instanzen, deren Mäher nicht mehr im Konto ist

### Mammotion Mäher

- Schalter **Instanz aktiv** und Status-Block im Konfigurationsformular
- Online-Status, Betriebsstatus, API-Rohstatus, Akku, Ladestatus, Firmware
- WLAN-Signal, WLAN-IP, Mobilfunk-Signal, Mähhöhe, Geschwindigkeitscode
- Aufgaben aus der Mammotion-App, eigene Aufgabenliste je Mäher
- Steuerung: Aufgabe starten, Pause, Fortsetzen, Stop, zur Ladestation, Heimfahrt abbrechen
- Schreibbefehle standardmäßig gesperrt
- Statusabruf fünf Sekunden nach jedem Befehl
- Systemzustand, Diagnose und Zeitstempel
- Sperre gegen parallele Abrufe mit automatischer Freigabe
- zwei Wiederholungen bei vorübergehenden Fehlern
- Premium-Dashboard, in der Instanz aktivier- und deaktivierbar
- sauberer Start nach einem Neustart von IP-Symcon

## Voraussetzungen

- IP-Symcon 9.0 oder neuer
- Mammotion-Konto mit mindestens einem in der Mammotion-App eingerichteten Mäher
- registrierte Open-API-Anwendung im [Mammotion Developer Portal](https://developer.mammotion.com/) mit Client-ID und Client-Secret
- ausgehender HTTPS-Zugriff auf `https://id.mammotion.com` und `https://api-open.mammotion.com`

Es werden keine zusätzlichen PHP-Bibliotheken benötigt.

## Projektstruktur

```text
MammotionOpenAPI/
├── .gitignore
├── CHANGELOG.md
├── CONTRIBUTING.md
├── LICENSE
├── README.md
├── library.json
├── MammotionCloud/
│   ├── form.json
│   ├── module.json
│   └── module.php
├── MammotionConfigurator/
│   ├── form.json
│   ├── module.json
│   └── module.php
└── MammotionMower/
    ├── form.json
    ├── module.json
    └── module.php
```

## Installation

1. In IP-Symcon **Kerninstanzen → Modules** öffnen.
2. **Hinzufügen** wählen und diese Adresse eintragen:

```text
https://github.com/cfaf2002/MammotionOpenAPI.git
```

Die Installation über die Repository-Adresse wird empfohlen. Sie ermöglicht Updates über die Modulverwaltung.

> **Hinweis für Synology:** Nicht per ZIP in einen freigegebenen Ordner kopieren. Die Synology legt dort versteckte `@eaDir`-Ordner an, die IP-Symcon als ungültige Module meldet.

## Einrichtung

1. Instanz **Mammotion Konfigurator** anlegen. IP-Symcon legt die übergeordnete **Mammotion Cloud** automatisch mit an.
2. Die Cloud-Instanz öffnen (Zahnrad am Konfigurator oder unter *I/O Instanzen*), den **Nutzungshinweis** lesen und bestätigen, Client-ID und Client-Secret eintragen, **Übernehmen**.
3. **Verbindung testen** drücken. Erwartet: `ERFOLG: 1 Mäher gefunden (Horst)`.
4. Den Konfigurator öffnen und den gewünschten Mäher mit **Erstellen** anlegen.
5. In der Mäher-Instanz **Jetzt aktualisieren** drücken und Systemzustand sowie Diagnose prüfen.
6. **Schreibbefehle freigeben** erst aktivieren, wenn die Werte stimmen, und zuerst mit einer unkritischen Aktion testen.

Ein erfolgreicher Zustand sieht so aus:

```text
Cloud:  Verbindungsstatus = Verbunden
Mäher:  Systemzustand = Betriebsbereit, Online = Ja
```

## Mammotion Cloud

### Einstellungen

| Einstellung | Bedeutung |
|---|---|
| Gelesen – Nutzung auf eigene Verantwortung | bestätigt den Nutzungshinweis; ohne Bestätigung keine Anfragen |
| Instanz aktiv | schaltet alle Cloudzugriffe dieses Kontos ab (alle Mäher pausieren) |
| Client ID | aus dem Mammotion Developer Portal |
| Client Secret | aus dem Mammotion Developer Portal |

Der Block **Status** zeigt beim Öffnen der Instanz Verbindung, Anzahl der verbundenen Mäher, Token-Ablauf, letzte erfolgreiche Anfrage und gegebenenfalls den letzten Fehler.

### Variablen

| Variable | Inhalt |
|---|---|
| Verbindungsstatus | Nicht angemeldet, Verbunden, Gestört, Deaktiviert, Anmeldung fehlgeschlagen, Hinweis nicht bestätigt |
| Token gültig bis | Ablaufzeit des aktuellen Access-Tokens |
| Letzte erfolgreiche Anfrage | Zeitpunkt der letzten erfolgreichen Cloudanfrage |
| Letzter Fehler | Zeitpunkt und Text der letzten Störung; wird geleert, sobald die Verbindung wieder funktioniert |

Access-Token, Refresh-Token und Client-Secret werden nur intern als Attribute gespeichert.

### Instanzstatus

| Code | Bedeutung |
|---:|---|
| 102 | aktiv |
| 104 | Instanz deaktiviert |
| 200 | Client-ID oder Client-Secret fehlt |
| 201 | Anmeldung fehlgeschlagen, neuer Versuch alle 10 Minuten |
| 202 | Nutzungshinweis noch nicht bestätigt |

Vorübergehende Störungen wie Timeouts oder HTTP 5xx ändern den Instanzstatus nicht, damit die Mäher-Instanzen weiter abrufen und sich selbst erholen können.

## Mammotion Konfigurator

Der Konfigurator fragt beim Öffnen die Mäherliste über die Cloud-Instanz ab.

| Spalte | Inhalt |
|---|---|
| Name | Nickname aus der Mammotion-App, sonst technischer Gerätename |
| Modell | Modellbezeichnung laut API |
| Device ID | eindeutige Gerätekennung |
| Online | Online-Status laut Geräteliste |

Bereits angelegte Mäher sind mit ihrer Instanz verknüpft. Instanzen, deren Device-ID im Konto nicht mehr vorkommt, erscheinen zusätzlich in der Liste und können dort gelöscht werden.

## Mammotion Mäher

### Einstellungen

| Einstellung | Bedeutung | Standard |
|---|---|---|
| Device ID | vom Konfigurator gesetzt; leer = erster Mäher des Kontos | leer |
| Abfrageintervall | zyklische Aktualisierung, mindestens 30 Sekunden | 60 |
| Instanz aktiv | für Wartung, Transport oder Einwinterung ausschalten | an |
| Dashboard-Kachel (HTML) aktiv | legt die HTML-Variable an oder entfernt sie | an |
| Schreibbefehle freigeben | erlaubt reale Steuerbefehle | aus |

Der Block **Status** zeigt beim Öffnen der Instanz Cloud-Verbindung, Mäher mit Nickname, Modell und Device-ID, Systemzustand, Diagnose, letzte Aktualisierung, letzten Befehl und ob Schreibbefehle freigegeben sind.

### Objektbaum

```text
Mammotion Mäher
├── Dashboard
├── Online
├── Betriebsstatus
├── Status (Rohwert)
├── Akku
├── Ladestatus (Code)
├── Mähhöhe
├── Geschwindigkeit (Code)
├── Firmware
├── WLAN RSSI
├── WLAN IP
├── Mobilfunk RSSI
├── Steuerung
├── Aufgabe starten
├── Systemzustand
├── Diagnose
├── Letzter Befehl
├── Letzte erfolgreiche Aktualisierung
└── Letzter Abrufversuch
```

### Systemzustand

| Wert | Bezeichnung | Bedeutung |
|---:|---|---|
| 0 | Initialisierung | Konfiguration übernommen, erster Abruf folgt nach zwei Sekunden |
| 1 | Prüfung läuft | Abruf läuft oder Wiederholung ist geplant |
| 2 | Betriebsbereit | alle Daten erfolgreich gelesen |
| 3 | Teilweise verfügbar | Basisdaten gelesen, Arbeitsparameter oder Aufgaben fehlgeschlagen |
| 4 | Offline | Mäher ausgeschaltet oder nicht erreichbar |
| 5 | Fehler | Anmeldung, Cloud, API oder Gerätezuordnung fehlgeschlagen |
| 6 | Deaktiviert | Abruf für diesen Mäher ausgeschaltet |

### Betriebsstatus

| Wert | Bezeichnung |
|---:|---|
| 0 | Offline |
| 1 | Bereit |
| 2 | Mäht |
| 3 | Pausiert |
| 4 | Lädt |
| 5 | Heimfahrt |
| 6 | Gerätefehler |
| 7 | API/Cloud-Fehler |
| 8 | Unbekannt |

Der Betriebsstatus wird aus dem API-Rohstatus abgeleitet. Ist ein Rohwert nicht zuordenbar, steht er in **Status (Rohwert)**. Rückmeldungen zu weiteren Werten sind willkommen.

### Instanzstatus

| Code | Bedeutung |
|---:|---|
| 102 | aktiv |
| 104 | Abruf deaktiviert |
| 200 | Abfrageintervall ungültig |
| 201 | API- oder Cloudfehler, Details in **Diagnose** |
| 202 | Mäher im Mammotion-Konto nicht gefunden |
| 203 | Cloud-Instanz nicht verbunden, nicht aktiv oder Nutzungshinweis nicht bestätigt |

### Steuerung

Die Variable **Steuerung** bietet Pause, Fortsetzen, Stop, Zur Ladestation und Heimfahrt abbrechen. **Aufgabe starten** enthält die in der Mammotion-App gespeicherten Aufgaben. Jeder Mäher hat ein eigenes Aufgabenprofil (`MAMMO.Tasks.<InstanzID>`), das beim Löschen der Instanz mit entfernt wird.

Schreibbefehle benötigen:

```text
Nutzungshinweis bestätigt = Ja (Cloud-Instanz)
Instanz aktiv = Ja             (Cloud-Instanz)
Instanz aktiv = Ja             (Mäher-Instanz)
Schreibbefehle freigeben = Ja  (Mäher-Instanz)
```

Das Ergebnis steht in **Letzter Befehl**. Fünf Sekunden nach einem Befehl wird der Status automatisch neu gelesen.

## Dashboard

Die Variable **Dashboard** (Profil `~HTMLBox`) zeigt Nickname, Modell, Gerätebild, Betriebsstatus, Akku-Ring, Mähhöhe, WLAN-Qualität, Firmware und die letzte Aktualisierung. Die Darstellung passt sich an Desktop, Tablet und Smartphone an.

Der Name wird in dieser Reihenfolge bestimmt: Nickname aus der Mammotion-App, technischer Gerätename, Instanzname, `MAMMOTION`.

| Zustand | Farbe |
|---|---|
| Bereit, Mäht | Grün |
| Lädt | Blau |
| Pausiert | Gelb |
| Heimfahrt | Violett |
| Gerätefehler, Systemfehler | Rot |
| API- oder Cloudfehler | Orange |
| Offline, deaktiviert | Grau |

Das Dashboard ist eine reine Anzeige. Gesteuert wird über die Variablen **Steuerung** und **Aufgabe starten**.

## PHP-Funktionen

### Mammotion Cloud (`MAMCLOUD_`)

```php
MAMCLOUD_TestConnection($InstanceID);   // "ERFOLG: ..." oder "FEHLER: ..."
MAMCLOUD_RenewToken($InstanceID);       // Token sofort neu anfordern
```

### Mammotion Mäher (`MAMMO_`)

```php
MAMMO_Refresh($InstanceID);                   // true/false
MAMMO_RefreshWithResult($InstanceID);         // Ergebnistext
MAMMO_GetTasks($InstanceID);                  // JSON-Liste der Aufgaben
MAMMO_StartTask($InstanceID, 'Vorgarten');
MAMMO_Pause($InstanceID);
MAMMO_Resume($InstanceID);
MAMMO_Stop($InstanceID);
MAMMO_ReturnToDock($InstanceID);
MAMMO_CancelReturn($InstanceID);
MAMMO_ExecuteAction($InstanceID, 'PAUSE');    // PAUSE, RESUME, STOP, RETURN, CANCEL_RETURN
```

Schreibende Funktionen werfen eine Exception, wenn Schreibbefehle nicht freigegeben sind oder die Cloud nicht erreichbar ist.

## Verwendete API-Endpunkte

```http
POST https://id.mammotion.com/oauth2/token
GET  /v1/mowers
GET  /v1/mower/{deviceId}
GET  /v1/mower/{deviceId}/work-params
GET  /v1/mower/{deviceId}/plan
POST /v1/mower/action
```

Die Cloud-Instanz leitet nur `GET`- und `POST`-Anfragen auf `/v1/…` weiter.

## Fehlerbehandlung und Wiederholungen

| Fehlerart | Beispiel | Verhalten |
|---|---|---|
| vorübergehend | Timeout, HTTP 5xx, HTTP 429 | Wiederholung nach 5 und 15 Sekunden, danach Fehler |
| Anmeldung | `invalid_client`, erneut HTTP 401 | keine Wiederholung, Cloud-Status 201, neuer Anmeldeversuch alle 10 Minuten |
| API | HTTP 4xx, API-Code ungleich 0 | keine Wiederholung, Details in **Diagnose** |
| Mäher offline | „device is offline“ | kein Fehler, Systemzustand **Offline** |
| Gerät unbekannt | Device-ID nicht im Konto | Status 202, keine Wiederholung |

Scheitern nur Arbeitsparameter oder Aufgaben, bleiben die Basisdaten gültig und der Systemzustand wird **Teilweise verfügbar**.

Ein Abruf sperrt weitere Abrufe derselben Instanz. Bleibt die Sperre durch einen Skriptabbruch oder Neustart stehen, wird sie nach 180 Sekunden automatisch übernommen. **Übernehmen** gibt sie sofort frei.

## Sicherheitshinweise

- Client-Secret und Access-Token niemals veröffentlichen, auch nicht in Screenshots, Issues oder Debugausgaben.
- Schreibbefehle sind standardmäßig gesperrt und erst nach erfolgreichem Test freizugeben.
- Vor realen Steuerbefehlen sicherstellen, dass der Arbeitsbereich frei ist.
- Nur vertrauenswürdigen Personen Zugriff auf die Visualisierung geben.
- Für Wartung, Transport oder Einwinterung **Instanz aktiv** in der Mäher-Instanz ausschalten.
- Das Modul ersetzt keine Sicherheitsfunktionen des Mähroboters.

## Fehlerbehebung

### IP-Symcon meldet „ungültiges Module @eaDir“

Die Synology hat einen Indexordner im Modulverzeichnis angelegt. Den Ordner `@eaDir` löschen, Module neu laden und künftig über die Repository-Adresse installieren.

### Konfigurator zeigt keine Mäher

- In der Cloud-Instanz **Verbindung testen** ausführen.
- Prüfen, ob der Mäher in der Mammotion-App mit demselben Konto verbunden ist, zu dem die Open-API-Anwendung gehört.

### Cloud: Anmeldung fehlgeschlagen

Client-ID und Client-Secret prüfen und **Übernehmen** drücken. Danach **Token jetzt erneuern** ausführen. Der Grund steht in **Letzter Fehler**.

### Mäher: Status 203

Die Mäher-Instanz ist nicht mit einer aktiven Cloud-Instanz verbunden. Prüfen, ob in der Cloud-Instanz der Nutzungshinweis bestätigt und **Instanz aktiv** eingeschaltet ist. Über **Gateway ändern** lässt sich die richtige Cloud-Instanz wählen.

### Mäher: Teilweise verfügbar

Arbeitsparameter oder Aufgaben konnten nicht gelesen werden. Die **Diagnose** nennt den Schritt und die API-Meldung.

### Mäher: Offline

Mäher einschalten, WLAN oder Mobilfunk prüfen. Im Energiesparzustand meldet die Cloud den Mäher ebenfalls als offline.

### Steuerbefehle werden abgelehnt

**Schreibbefehle freigeben**, **Instanz aktiv** und **Online** prüfen. Die Antwort steht in **Letzter Befehl**.

### Detaillierte Analyse

In der Cloud-Instanz das Debug-Fenster öffnen. Es zeigt jede Anfrage und Antwort (ohne Token).

## Umstieg von 1.x

Version 2.0 verwendet neue Modul-GUIDs. Alte Instanzen werden nicht übernommen.

1. Client-ID, Client-Secret und Device-ID aus der alten Instanz notieren.
2. Alte Mammotion-Instanz löschen.
3. Optional die alten Profile `MAMMO.Tasks` und `MAMMO.Control` löschen. Sie werden bei Bedarf neu angelegt.
4. Modul in der Modulverwaltung aktualisieren oder neu hinzufügen.
5. [Einrichtung](#einrichtung) durchführen.

Skripte mit `MAMMO_Pause`, `MAMMO_StartTask` usw. funktionieren weiter, die Instanz-ID ändert sich jedoch. `MAMMO_StartCheck` und `MAMMO_RenewToken` entfallen. Stattdessen `MAMMO_RefreshWithResult` beziehungsweise `MAMCLOUD_RenewToken` verwenden.

## Bekannte Einschränkungen

- Die Work-Report-Endpunkte waren während der Entwicklung nicht zuverlässig nutzbar. Mähhistorie und Flächenstatistiken fehlen daher noch.
- **Aufgabe starten** sendet den Aufgabennamen (`taskName`). Die Aufgaben-ID wird mitgespeichert und kann genutzt werden, sobald die API-Dokumentation das bestätigt.
- Die Zuordnung des Betriebsstatus beruht auf den bisher beobachteten Rohwerten.

## Nutzungshinweis

Privates, inoffizielles Projekt – nicht von Mammotion. Das Modul nutzt die Mammotion Open API mit dem eigenen Entwicklerzugang. Mammotion kann die API jederzeit ändern oder einschränken. Steuerbefehle bewegen einen realen Mähroboter. Die Nutzung erfolgt auf eigene Verantwortung. Der Hinweis wird in der Cloud-Instanz einmalig bestätigt.

## Lizenz

Dieses Projekt steht unter der [MIT-Lizenz](LICENSE). Hinweise zum Mitwirken stehen in [CONTRIBUTING.md](CONTRIBUTING.md).

## Haftung und Markenhinweis

Dieses Projekt ist ein unabhängiges Open-Source-Projekt und steht nicht in Verbindung mit Mammotion oder IP-Symcon. Produkt- und Markennamen gehören den jeweiligen Rechteinhabern.

Die Nutzung erfolgt in eigener Verantwortung. Es wird keine Gewährleistung für Verfügbarkeit, Kompatibilität oder fehlerfreien Betrieb übernommen.
