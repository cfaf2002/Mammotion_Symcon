# Mammotion Open API für IP-Symcon

[![IP-Symcon ab 9.0](https://img.shields.io/badge/IP--Symcon-ab_9.0-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.1 (Build 2)](https://img.shields.io/badge/Modul--Version-1.1_(Build_2)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/Mammotion_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Mammotion_Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprache: Deutsch](https://img.shields.io/badge/Sprache-Deutsch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Mammotion Open API](https://img.shields.io/badge/Mammotion-Open%20API-success.svg)](https://developer.mammotion.com/)

Integration von Mammotion-Mährobotern in IP-Symcon über die offizielle Mammotion Open API.

Version **1.0** (Build 1) ist eine vollständige Neuentwicklung mit der für IP-Symcon üblichen Aufteilung in Cloud-, Geräte- und Konfigurator-Instanz sowie einer eigenen Kachel für die Kachel-Visualisierung. Sie nutzt die aktuelle Technik von IP-Symcon 9.0: die Basisklasse `IPSModuleStrict`, Variablen-Darstellungen statt Profilen und das HTML-SDK der Kachel-Visualisierung (siehe [Technik](#technik-ip-symcon-90)). Sie ist **nicht** mit den Instanzen früherer Versionen kompatibel (neue Modul-GUIDs). Alte Instanzen vor der Installation löschen, siehe [Umstieg von früheren Versionen](#umstieg-von-früheren-versionen).

## Inhaltsverzeichnis

- [Aufbau](#aufbau)
- [Funktionsumfang](#funktionsumfang)
- [Technik (IP-Symcon 9.0)](#technik-ip-symcon-90)
- [Geschwindigkeit](#geschwindigkeit)
- [Voraussetzungen](#voraussetzungen)
- [Projektstruktur](#projektstruktur)
- [Installation](#installation)
- [Einrichtung](#einrichtung)
- [Mammotion Cloud](#mammotion-cloud)
- [Mammotion Konfigurator](#mammotion-konfigurator)
- [Mammotion Mäher](#mammotion-mäher)
- [Kachel](#kachel)
- [PHP-Funktionen](#php-funktionen)
- [Verwendete API-Endpunkte](#verwendete-api-endpunkte)
- [Fehlerbehandlung und Wiederholungen](#fehlerbehandlung-und-wiederholungen)
- [Sicherheitshinweise](#sicherheitshinweise)
- [Fehlerbehebung](#fehlerbehebung)
- [Umstieg von früheren Versionen](#umstieg-von-früheren-versionen)
- [Bekannte Einschränkungen](#bekannte-einschränkungen)
- [Nutzungshinweis](#nutzungshinweis)
- [Lizenz](#lizenz)
- [Haftung und Markenhinweis](#haftung-und-markenhinweis)

## Aufbau

```text
Mammotion Cloud (I/O)            Anmeldung, Token, alle HTTP-Anfragen
├── Mammotion Konfigurator       listet die Mäher des Kontos und legt Instanzen an
├── Mähroboter                   Status, Werte, Steuerung, Kachel
└── Mähroboter <Name>            weitere Mäher desselben Kontos
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
- WLAN-Signal, WLAN-IP, Mobilfunk-Signal, Mähhöhe und Geschwindigkeit des letzten Einsatzes
- Statistik (Einsätze, Fläche, Zeitersparnis, CO₂), letzter Einsatz mit Ergebnis, Dauer und Energie, Fehlerprotokoll der letzten 30 Tage
- Aufgaben aus der Mammotion-App, eigene Aufgabenliste je Mäher
- Steuerung: Aufgabe starten, Pause, Fortsetzen, Stop, zur Ladestation, Heimfahrt abbrechen
- Schreibbefehle standardmäßig gesperrt
- Statusabruf fünf Sekunden nach jedem Befehl
- Systemzustand, Diagnose und Zeitstempel
- Sperre gegen parallele Abrufe mit automatischer Freigabe
- zwei Wiederholungen bei vorübergehenden Fehlern
- eigene Kachel mit Live-Status und Bedienung, in der Instanz aktivier- und deaktivierbar
- sauberer Start nach einem Neustart von IP-Symcon

## Technik (IP-Symcon 9.0)

| Technik | Umsetzung im Modul |
|---|---|
| **`IPSModuleStrict`** | Alle drei Module nutzen die seit IP-Symcon 8.1 empfohlene Basisklasse mit strengen Typen. `IPSModule` soll laut Symcon für neue Module nicht mehr verwendet werden. Modulvariablen lassen sich damit nur noch vom Modul selbst schreiben. |
| **Datenfluss über die Konsole** | Statt `ConnectParent` melden Mäher und Konfigurator über `GetCompatibleParents()`, dass sie an eine vorhandene oder neue Mammotion-Cloud-Instanz gehören. Die Verwaltungskonsole übernimmt das Verbinden. |
| **Darstellungen statt Profile** | Alle Variablen nutzen Variablen-Darstellungen (Wertanzeige mit Intervallen für Statuscodes, Aufzählung für Bedienung, Datum/Uhrzeit für Zeitstempel). Es werden keine globalen Profile mehr angelegt. Große Werte werden automatisch umgerechnet (m² → ha, min → h, Wh → kWh, kg → t). |
| **Aufgabenliste je Variable** | Die Aufgaben aus der Mammotion-App stehen direkt in der Darstellung der Variable **Aufgabe starten**. Das frühere Profil je Instanz entfällt. |
| **HTML-SDK** | Die Mäher-Instanz ist selbst eine Kachel (`GetVisualizationTile`, `UpdateVisualizationValue`), siehe [Kachel](#kachel). |
| **Timer über `RequestAction`** | Timer rufen `IPS_RequestAction` auf. Es gibt keine öffentlichen Hilfsfunktionen nur für Timer. |
| **PHP 8.5** | IP-Symcon 9.0 nutzt PHP 8.5. Das ab PHP 8.5 veraltete `curl_close()` wird nicht mehr aufgerufen. |
| **Kernel-Start** | Die Initialisierung wartet auf `IPS_KERNELSTARTED`. |

Profile früherer Versionen (`MAMMO.*`, `MAMCLOUD.State`) werden nach dem Update einmalig gelöscht, sofern keine Variable sie mehr verwendet.

## Geschwindigkeit

| Maßnahme | Wirkung |
|---|---|
| Ein API-Aufruf je Abruf | Das Gerätedetail enthält Status, Online-Flag, Modell und Bild. Die Geräteliste wird nur zur Ermittlung der Device-ID oder bei einem Fehler abgefragt. Das halbiert die Anfragen gegenüber zwei Aufrufen je Minute. |
| Zusatzdaten seltener | Aufgaben, Statistik, Verlauf und Fehler alle 15 Minuten, Einsatzdetails nur einmal je neuem Einsatz. |
| Buffer statt Attribute | Sperre, Wiederholungen und Takt der Zusatzdaten liegen im Arbeitsspeicher (`SetBuffer`). Attribute werden sofort auf die Festplatte geschrieben und deshalb nur noch bei echten Änderungen geschrieben. Im Normalbetrieb schreibt ein Abruf kein einziges Attribut. |
| Kachel nur bei Änderung | Die Kachel bekommt nur dann ein Update, wenn sich ihr Inhalt geändert hat. Das Hintergrundbild wird nur beim Öffnen übertragen. |
| Komprimierte Antworten | HTTP-Antworten werden komprimiert angenommen. |
| Ein Token je Konto | Alle Mäher eines Kontos teilen sich den Token der Cloud-Instanz, parallele Erneuerungen sind per Semaphore ausgeschlossen. |

## Voraussetzungen

- IP-Symcon 9.0 oder neuer
- Mammotion-Konto mit mindestens einem in der Mammotion-App eingerichteten Mäher
- registrierte Open-API-Anwendung im [Mammotion Developer Portal](https://developer.mammotion.com/) mit Client-ID und Client-Secret
- ausgehender HTTPS-Zugriff auf `https://id.mammotion.com` und `https://api-open.mammotion.com`

Es werden keine zusätzlichen PHP-Bibliotheken benötigt.

## Projektstruktur

```text
Mammotion_Symcon/
├── .gitignore
├── CHANGELOG.md
├── CONTRIBUTING.md
├── LICENSE
├── README.md
├── library.json
├── libs/
│   └── PresentationHelper.php   gemeinsame Hilfen für Darstellungen
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
    ├── tile.html               Kachel (HTML-SDK)
    ├── module.json
    └── module.php
```

## Installation

1. In IP-Symcon **Kerninstanzen → Modules** öffnen.
2. **Hinzufügen** wählen und diese Adresse eintragen:

```text
https://github.com/cfaf2002/Mammotion_Symcon.git
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
| Abfrageintervall | zyklische Aktualisierung von Status, Akku und Netz, mindestens 30 Sekunden | 60 |
| Instanz aktiv | für Wartung, Transport oder Einwinterung ausschalten | an |
| Kachel-Visualisierung (HTML) aktiv | zeigt die Instanz als eigene Kachel; aus = Standardkachel mit Variablenliste | an |
| Statistik, Einsatzverlauf und Fehlerprotokoll abrufen | legt die Variablen dafür an und fragt sie alle 15 Minuten ab | an |
| Schreibbefehle freigeben | erlaubt reale Steuerbefehle | aus |

Der Block **Status** zeigt beim Öffnen der Instanz Cloud-Verbindung, Mäher mit Modell, Name in der App und Device-ID, Systemzustand, Diagnose, letzte Aktualisierung, letzten Einsatz, Gerätefehler, letzten Befehl und ob Schreibbefehle freigegeben sind.

### Abfragetakt

| Daten | Takt |
|---|---|
| Status, Akku, Ladestatus, Netz | im Abfrageintervall (Standard 60 Sekunden), ein API-Aufruf |
| Aufgaben, Statistik, Einsatzverlauf, Fehlerprotokoll | alle 15 Minuten, nach Fehler nach 5 Minuten, 2 Minuten nach Ende eines Einsatzes und bei **Jetzt aktualisieren** sofort |
| Details eines Einsatzes (Energie, Mähhöhe, Geschwindigkeit) | einmal je neuem Einsatz |

Verlauf und Fehlerprotokoll umfassen die letzten 30 Tage. Da die API keine Sortierung garantiert, wählt das Modul selbst den jüngsten Eintrag.

Die Work-Report-Endpunkte lehnen je nach Konto oder Gerät manche Anfragen mit Code 40200 ab. Das Modul probiert deshalb mehrere gültige Anfrageformen und merkt sich die funktionierende (sichtbar im Debug der Mäher-Instanz). Lehnt die API alle ab, erscheint in der Diagnose „derzeit nicht bereitgestellt“, ohne gelbe Warnung. Neuer Versuch nach 6 Stunden oder sofort mit **Jetzt aktualisieren**.

### Sicherheitshinweis zu den Arbeitsparametern

Die API bietet `GET /v1/mower/{deviceId}/work-params` für die aktuellen Arbeitsparameter. Laut der Home-Assistant-Integration für die Mammotion Open API hat ein LUBA 2 nach einem Aufruf dieses Endpunkts unerwartet mit dem Mähen begonnen. **Das Modul ruft diesen Endpunkt deshalb nicht auf.** Mähhöhe und Geschwindigkeit stammen stattdessen aus dem Bericht des letzten Einsatzes und zeigen die dort verwendeten Werte.

### Objektbaum

```text
Mammotion Mäher
├── Online
├── Betriebsstatus
├── Status (Rohwert)
├── Akku
├── Ladestatus (Code)
├── Mähhöhe (letzter Einsatz)
├── Geschwindigkeit (Code)
├── Firmware
├── WLAN RSSI
├── WLAN IP
├── Mobilfunk RSSI
├── Steuerung
├── Aufgabe starten
├── Letzter Einsatz                      (Zeitpunkt Ende)
├── Letzter Einsatz – Ergebnis           (Läuft, Pausiert, Vom Nutzer gestoppt, Unterbrochen, Abgeschlossen)
├── Letzter Einsatz – Art                (Einzeleinsatz, Zeitplan, Punktmähen, Fortsetzung)
├── Letzter Einsatz – Fläche             (m²)
├── Letzter Einsatz – Dauer              (min)
├── Letzter Einsatz – Fortschritt        (%)
├── Letzter Einsatz – Energie            (Wh)
├── Einsätze gesamt
├── Gemähte Fläche gesamt                (m²)
├── Zeitersparnis gesamt                 (h)
├── CO₂-Einsparung gesamt                (kg)
├── Letzter Gerätefehler                 (Code und Beschreibung)
├── Letzter Gerätefehler – Zeitpunkt
├── Gerätefehler (30 Tage)
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
| 4 | In der Station (Standby mit Ladestatus ungleich 0 oder Rohstatus „Charging“) |
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

Die Variable **Steuerung** bietet Pause, Fortsetzen, Stop, Zur Ladestation und Heimfahrt abbrechen. **Aufgabe starten** enthält die in der Mammotion-App gespeicherten Aufgaben. Sie stehen direkt in der Darstellung der Variable und werden aktualisiert, sobald sich die Aufgaben in der App ändern.

Schreibbefehle benötigen:

```text
Nutzungshinweis bestätigt = Ja (Cloud-Instanz)
Instanz aktiv = Ja             (Cloud-Instanz)
Instanz aktiv = Ja             (Mäher-Instanz)
Schreibbefehle freigeben = Ja  (Mäher-Instanz)
```

Das Ergebnis steht in **Letzter Befehl**. Fünf Sekunden nach einem Befehl wird der Status automatisch neu gelesen.

## Kachel

Die Mäher-Instanz bringt eine eigene Kachel für die Kachel-Visualisierung mit (HTML-SDK). Die Instanz einfach in die Visualisierung ziehen, eine zusätzliche Variable ist nicht nötig. Die Kachel aktualisiert sich live nach jedem Abruf und jedem Befehl.

**Inhalt:**

- Modell und Firmware, Gerätebild aus der Mammotion-Cloud (sonst Mäher-Symbol)
- Status-Badge, pulsierend beim Mähen, Laden und bei der Heimfahrt
- Akku-Ring mit Farbwechsel (grün, gelb unter 40 %, rot unter 20 %)
- Mähhöhe, WLAN und Mobilfunk mit Signalbalken und Qualitätsbewertung
- kurze Hinweise bei Fehlern, Offline oder unbekanntem Rohstatus, gelber Hinweis-Chip wenn Zusatzdaten gerade nicht abrufbar sind
- Gerätefehler aus dem Fehlerprotokoll: rot nur bei aktivem Fehlerzustand oder wenn die Meldung höchstens 30 Minuten alt ist; ältere Meldungen der letzten 24 Stunden als Zeile „Meldung heute 13:09 · …“
- „Aktualisiert vor x Min.“ und letzter Befehl
- animierte Mähbahnen im Hintergrund, solange der Mäher mäht

**Bedienung** (nur mit **Schreibbefehle freigeben**, sonst Hinweis „Steuerung gesperrt“):

- **Zeile 1:** Aufgabe wählen und **Starten**
- **Zeile 2:** alle Befehle als Leiste: **Pause**, **Fortsetzen**, **Stop**, **Zur Ladestation**, **Heimfahrt abbrechen**

Alle Befehle sind immer verfügbar. Die zum aktuellen Zustand passenden werden hervorgehoben:

| Zustand | Hervorgehoben |
|---|---|
| Bereit | Starten, Zur Ladestation |
| In der Station | Starten |
| Mäht | Pause, Zur Ladestation |
| Pausiert | Fortsetzen, Stop, Zur Ladestation |
| Heimfahrt | Heimfahrt abbrechen |

**Starten** und **Fortsetzen** setzen den Mäher in Bewegung und müssen deshalb mit einem zweiten Tipp auf „Wirklich?“ bestätigt werden (4 Sekunden Zeit). Offline oder bei einem Fehler werden keine Befehle angeboten.

**Farbschema und Hintergrund** (Instanz → Block „Kachel-Hintergrund“):

Das **Farbschema der Kachel** ist in allen Modulen gleich (siehe [STYLEGUIDE.md](STYLEGUIDE.md)): *Symcon-Design* übernimmt Schrift- und Akzentfarbe der Visualisierung und passt sich hellen wie dunklen Designs an, *Dunkel* und *Hell* setzen einen festen Hintergrund.

| Hintergrund | Wirkung |
|---|---|
| Schimmer in der Zustandsfarbe (Standard) | leichter Schimmer in der Farbe des Zustands über dem Farbschema |
| Bild aus Medienobjekt | eigenes Bild als Hintergrund, mit einstellbarer Abdunklung (0–90 %, Standard 55 %), Schrift immer hell |
| Keiner (nur Farbschema) | kein Schimmer, nur das Farbschema |

So wird ein Bild eingebunden, wie in IP-Symcon üblich:

1. Im Objektbaum **Objekt hinzufügen → Medien → Bild** wählen und das Bild hochladen (JPG oder WebP empfohlen, höchstens ca. 2 MB).
2. In der Mäher-Instanz unter **Kachel-Hintergrund** „Bild aus Medienobjekt“ wählen und das Medienobjekt auswählen.
3. **Übernehmen** drücken und die Kachel neu öffnen.

Das Medienobjekt wird als Referenz der Instanz eingetragen, IP-Symcon warnt deshalb vor dem Löschen. Das Bild wird nur beim Laden der Kachel übertragen, die laufenden Aktualisierungen bleiben klein. Ist das Medienobjekt kein Bild, fehlt es oder ist es zu groß, verwendet die Kachel den Farbverlauf, und der Status-Block nennt den Grund.

**Größen:** Die Kachel passt sich an. Auf schmaleren Kacheln zeigt die Befehlsleiste nur Symbole (Name als Tooltip). Auf niedrigeren Kacheln werden nacheinander Verlaufszeilen, Kennzahlen, Aufgabenzeile, Fußzeile und Befehle ausgeblendet. Auf sehr kleinen Kacheln bleiben Name, Akku-Ring und Status.

| Zustand | Farbe |
|---|---|
| Bereit, Mäht | Grün |
| Lädt | Blau |
| Pausiert | Gelb |
| Heimfahrt | Violett |
| Gerätefehler, Systemfehler | Rot |
| API- oder Cloudfehler | Orange |
| Offline, deaktiviert | Grau |

Der Instanzname (zum Beispiel „Mähroboter“) wird von IP-Symcon oben links in der Kachel angezeigt. Die Kachel selbst zeigt deshalb das Modell und nicht den Nickname aus der Mammotion-App. Oben bleibt Platz für Instanzname und Vergrößern-Symbol.

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
GET  /v1/mower/{deviceId}/plan
POST /v1/mower/work-reports/summary
POST /v1/mower/work-reports/search
GET  /v1/mower/{deviceId}/work-reports/{workId}
POST /v1/mower/error-codes/search
POST /v1/mower/action
```

Die Cloud-Instanz leitet nur `GET`- und `POST`-Anfragen auf `/v1/…` weiter.

Die offizielle Spezifikation (abrufbar unter `https://api-open.mammotion.com/api-docs`) enthält weitere Endpunkte, die das Modul noch nicht nutzt: Karten- und Materialdaten (`/v1/mower/material/fetch`), Abonnements mit Live-Daten per SSE (`/v1/devices/subscriptions`, laut Spezifikation nur für LUBA 3 AWD), ein Ticket für eine lokale WebSocket-Verbindung zum Mäher (`/v1/mower/ws/ticket`) und die lokale Netzwerkkonfiguration für Home-Automation (`/v1/ha/local-network/…`). Bewusst nicht genutzt wird `/v1/mower/{deviceId}/work-params` (siehe Sicherheitshinweis).

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
- Client-Secret, Access- und Refresh-Token liegen, wie bei IP-Symcon üblich, in den Einstellungen der Instanz. Backups von IP-Symcon deshalb geschützt aufbewahren.

**Technische Schutzmaßnahmen im Modul:**

| Bereich | Schutz |
|---|---|
| Verbindung | nur HTTPS, Zertifikat und Hostname werden immer geprüft, keine Weiterleitungen, Antworten höchstens 5 MB |
| Weiterleitung von Anfragen | die Cloud-Instanz leitet nur `GET` und `POST` auf `/v1/…` weiter, mit Prüfung auf erlaubte Zeichen und ohne `..` |
| Arbeitsparameter | `GET /v1/mower/{deviceId}/work-params` wird nie aufgerufen (siehe [Sicherheitshinweis](#sicherheitshinweis-zu-den-arbeitsparametern)) |
| Steuerung | Schreibbefehle standardmäßig gesperrt, nur bekannte Befehle und Aufgaben, Start und Fortsetzen in der Kachel mit Bestätigung |
| Nutzungshinweis | ohne Bestätigung sendet die Cloud-Instanz keine Anfragen |
| Kachel | alle Texte werden als Text eingefügt (kein HTML aus der Cloud), Gerätebild nur über HTTPS, Hintergrundbild nur JPG, PNG, WebP oder GIF (kein SVG) |
| Variablen | mit `IPSModuleStrict` kann nur das Modul selbst seine Variablen schreiben |
| Debug | Token und Client-Secret erscheinen nie im Debug |

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

## Umstieg von früheren Versionen

Version 1.0 (Build 1) verwendet neue Modul-GUIDs. Alte Instanzen werden nicht übernommen.

1. Client-ID, Client-Secret und Device-ID aus der alten Instanz notieren.
2. Alte Mammotion-Instanz löschen.
3. Alte Profile (`MAMMO.*`, `MAMCLOUD.State`) muss niemand mehr von Hand löschen. Das Modul entfernt sie nach dem Update selbst, sofern keine Variable sie mehr verwendet.
4. Modul in der Modulverwaltung aktualisieren oder neu hinzufügen.
5. [Einrichtung](#einrichtung) durchführen.

Skripte mit `MAMMO_Pause`, `MAMMO_StartTask` usw. funktionieren weiter, die Instanz-ID ändert sich jedoch. `MAMMO_StartCheck` und `MAMMO_RenewToken` entfallen, ebenso die Variable **Dashboard** (ersetzt durch die Kachel). Stattdessen `MAMMO_RefreshWithResult` beziehungsweise `MAMCLOUD_RenewToken` verwenden.

## Bekannte Einschränkungen

- Karten, Live-Position und Push-Daten (SSE, lokaler WebSocket) sind noch nicht umgesetzt.
- Der Ladestatus ist laut Spezifikation 0 oder 1. Auf echten Geräten wurden weitere Werte beobachtet; sie werden als Rohwert angezeigt.
- **Aufgabe starten** sendet laut Spezifikation den Aufgabennamen (`taskName`).
- Die Zuordnung des Betriebsstatus beruht auf den bisher beobachteten Rohwerten.

## Nutzungshinweis

Privates, inoffizielles Projekt – nicht von Mammotion. Das Modul nutzt die Mammotion Open API mit dem eigenen Entwicklerzugang. Mammotion kann die API jederzeit ändern oder einschränken. Steuerbefehle bewegen einen realen Mähroboter. Die Nutzung erfolgt auf eigene Verantwortung. Der Hinweis wird in der Cloud-Instanz einmalig bestätigt.

## Changelog

Alle Änderungen stehen in [CHANGELOG.md](CHANGELOG.md).

## Lizenz

Dieses Projekt steht unter der [MIT-Lizenz](LICENSE), Copyright (c) 2026 Armin Frohwerk. Jede Quelldatei trägt den Kennzeichner `SPDX-License-Identifier: MIT`. Hinweise zum Mitwirken stehen in [CONTRIBUTING.md](CONTRIBUTING.md).

Fremdbestandteile:

- Das Modul enthält keinen Code und keine Bibliotheken Dritter.
- Die Symbole in der Kachel sind eigene SVG-Grafiken. Die Symbolnamen der Variablen-Darstellungen verweisen auf die Symbole, die IP-Symcon selbst mitbringt.
- Das Gerätebild wird zur Laufzeit von der Mammotion-Cloud geladen und ist nicht Teil dieses Repositorys.
- Die Badges werden von [shields.io](https://shields.io/) erzeugt.

## Haftung und Markenhinweis

Dieses Projekt ist ein unabhängiges Open-Source-Projekt und steht nicht in Verbindung mit Mammotion oder IP-Symcon. Produkt- und Markennamen gehören den jeweiligen Rechteinhabern.

Die Nutzung erfolgt in eigener Verantwortung. Es wird keine Gewährleistung für Verfügbarkeit, Kompatibilität oder fehlerfreien Betrieb übernommen.
