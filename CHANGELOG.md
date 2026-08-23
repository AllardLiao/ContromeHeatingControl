# Changelog

Alle nennenswerten Änderungen an diesem Repository, gruppiert nach Version und Modul.

## 1.0.3

### Controme Gateway
- Setpoint/Modus werden nach erfolgreichem Schreiben über die Controme-API sofort an das
  betroffene Raum-Thermostat gepusht, statt auf den nächsten regulären Controme-Sendezyklus
  (bis zu 15 Minuten) zu warten.
- `CheckConnection()` konnte bei fehlendem `solltemperatur`-Feld in der Controme-Antwort
  versehentlich 0 °C als Sollwert an einen echten Raum zurückschreiben (toter `??`-Fallback) – behoben.
- Zwei `??`-Operator-Vorrangfehler behoben, durch die Fallback-Fehlermeldungen nie griffen.
- Vollständige deutsche/spanische Übersetzung ergänzt.

### Controme Central Control
- Verbindungsfehler vom Gateway (z. B. Mini-Server offline) wurden bisher als gültige, aber leere
  Daten behandelt und der Status blieb fälschlich "aktiv" – wird jetzt korrekt als Fehler erkannt.
- Mehrraum-Aktionen ("Alle Räume") brachen bisher beim ersten fehlgeschlagenen Raum komplett ab;
  jetzt werden alle Räume versucht und Fehler gesammelt zurückgemeldet.
- `ReceiveData()` validiert eingehende Payloads jetzt vor dem Zugriff.
- Vollständige deutsche/spanische Übersetzung ergänzt.

### Controme Room Thermostat
- Fallback-Temperatur-/Feuchte-Sensor wird jetzt vor dem Lesen auf Existenz geprüft, um einen
  Absturz bei gelöschter oder typfalscher Variable zu vermeiden.
- Empfängt jetzt optimistische Setpoint-/Modus-Updates vom Gateway und zeigt sie sofort an.
- Vollständige deutsche/spanische Übersetzung ergänzt.

### Controme Configurator
- `??`-Operator-Vorrangfehler behoben, durch den der Fallback-Raumname ("Unknown Room") nie griff.
- Vollständige deutsche/spanische Übersetzung ergänzt.

### Bibliotheksweit
- Mehrere `locale.json`-Dateien enthielten doppelt escapte `\n`/`\"`-Sequenzen, wodurch vorhandene
  Übersetzungen nie zu den tatsächlichen Formulartexten passten und stillschweigend ignoriert wurden – behoben.
- `isError()`/`isSuccess()`/`getResponseMessage()`/`getResponsePayload()` (libs/ReturnWrapper.php)
  werfen nicht mehr uncaught, wenn `SendDataToParent()`/`SendDataToChildren()` `false` liefert
  (kein Empfänger), sondern behandeln dies korrekt als Fehlerfall.

### Bekannter, bewusst offener Punkt
- Neu über den Configurator erstellte Instanzen verbinden sich weiterhin automatisch mit
  irgendeinem kompatiblen Gateway/Splitter statt mit dem tatsächlich gewünschten. Eine spätere
  Überarbeitung des Configurators soll das automatische Verbinden gezielt abschalten, damit
  stattdessen die IP-Symcon-Konsole den Verbindungsvorgang steuert. Für dieses Release bewusst
  nicht behoben.

## 1.0.2

### Controme Configurator
- Neues Modul eingeführt: Instanzerstellung/-verwaltung erfolgt jetzt über ein natives
  IP-Symcon-Configurator-Element statt über manuelle Buttons im Gateway.
- Ruft Raumliste sowie bestehende Central-Control-/Room-Thermostat-Instanzen dynamisch über den
  Datenfluss vom Gateway ab (`GetConfigurationForm`, `GetRoomThermostatInstanceID`, ...).
- Fehlerbehandlung für den Fall ergänzt, dass der Controme Mini-Server nicht erreichbar ist.

### Controme Gateway
- Alte, manuelle Instanzerstellung (Buttons "CREATE CC"/"CREATE RT" im Gateway-Formular) entfernt
  zugunsten des neuen Configurators.
- HTTPS-Option im Formular deaktiviert (von Controme aktuell nicht unterstützt).
- Größeres Aufräumen ungenutzter, geerbter Hilfsklassen (EventHelper, FormatHelper, ProfileHelper,
  TranslationHelper, VariableHelper, VersionHelper, WebhookHelper, WidgetHelper) entfernt.

### Controme Central Control
- Temperatur-/Feuchte-Abfrage auf den Datenfluss über das Gateway umgestellt.
- Dokumentation und Übersetzungen aktualisiert.

### Controme Room Thermostat
- Temperatur-/Feuchte-Abfrage auf den Datenfluss über das Gateway umgestellt
  ("Umbau: Temp/Hum Abfrage über Gateway").
- Dokumentation und Übersetzungen aktualisiert.

## 1.0.1

### Controme Gateway
- Verbindungs- und Statusmeldungen sind jetzt tatsächlich über `Translate()` übersetzt.
- Anzeigename der Instanz bereinigt ("ContromeGateway" → "Controme Gateway").
- Formulartexte/Layout kleinere Korrekturen.

### Controme Central Control
- Anzeigename der Instanz bereinigt ("ContromeCentralControl" → "Controme Central Control").

### Controme Room Thermostat
- Fix: Das "Hinweis"-Icon wurde bei jedem Datenupdate neu gesetzt statt nur bei Neuanlage der
  Variable – jetzt wird `IPS_SetIcon()` nur aufgerufen, wenn `MaintainVariable()` eine neue
  Variable angelegt hat (respektiert die Icon-Hoheit des Benutzers).
- Anzeigename der Instanz bereinigt ("ContromeRoomThermostat" → "Controme Room Thermostat").
- Deutsche/spanische Übersetzungen ergänzt.

*(Controme Configurator existierte zu diesem Zeitpunkt noch nicht.)*

## 1.0.0

Initial Published Version.
