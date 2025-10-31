# Controme Heating Control

Folgende Module beinhaltet das Controme Heating Control Repository:

- __Controme Gateway__ ([Documentation](ContromeGateway))
- __Controme Configurator__ [Documentation](ContromeConfigurator)
- __Controme Central Control__ ([Documentation](ContromeCentralControl))
- __Controme Room Thermostat__ ([Documentation](ContromeRoomThermostat))

IP-Symcon Modul zur lokalen Steuerung und Überwachung von Controme-Heizsystemen.

Automatisches Anlegen von Räumen, Sensoren, Ist-/Soll-Temperaturen, Luftfeuchte und Betriebsart.

Unterstützt Lesen und Schreiben über die Controme API - V2 Branch.
API Informatiaon can be found at https://support.controme.com/api/
HINWEIS: Entwickelt in der V2 Branch von Controme OS - Kompatibilität mit V1 nicht gewährleiste.

Vollständig in IPS integrierbar mit Timer, Variablenprofilen, Tiles für die Visualisierung.

![Screenshot Controme Heating Control](libs/assets/Controme_Heating_Control.png)

## Installation

1. Erstelle zuerst eine Controme Konfigurator-Instanz. Bei der Erstellung des Konfigurators wird ein Gateway automatisch angelegt.
2. Konfiguriere das Gateway. Dieses stellt die Verbindung zum Controme Mini-Server her.
3. Erstelle mit der Konfigurator-Instanz zentrale Steuereinheit(en)
4. Erstelle mit der Konfigurator-Instanz (je Raum ein) Raum-Thermostat

Mehrere zentrale Steuereinheiten sind möglich - hierüber können End-User Steuerungen mit unterschiedlichen
Berechtigungen erzeugt werden - wie z.B. das Umschalten zwischen Heiz- und Kühl-Betrieb in der einen Instanz
möglich, in der anderen nicht (und je nach Visu wird das eine oder andere eingeblendet).

Alle Controme-Instanzen benötigen das Gateway um mit dem Controme Mini-Server via API kommunizieren zu können:

```
Controme Gateway (type=2, parent)
    |
    ├── Controme Configurator (type=4, child)
    |
    ├── Controme Central Control #1 (type=3, child)
    |
    ├── Controme Room Thermostat #1 (type=3, child)
    |
    ├── Controme Room Thermostat #2 (type=3, child)
    .
    .
    .
```

Hinweise:
Die Configuration und Benennung der Räume und Sensoren im Controme Mini-Servers sollten final abgeschlossen sein.
Wird dies nach Verbindung dieses Moduls in der Controme-App angepasst, ändern sich die Namen Räume in der Konfigurator-Instanz und werden ggf. nicht korrekt als schon angelegt erkannt.

Tipp:
Controme verwendet das EnOcean Protokoll, um Geräte an den Mini-Server anzubinden. Jedoch nur einen eingeschränkten EEP-Satz. (Stand 10/2025 z.B. für Fensterkontakt EEP D5-00-01, für Temperatur & Luftfeuchte EEP A5-04-01, EEP A5-02-05, A5-02-13 und für Bewegungsmelder A5-07-03.)
Mit dem [EnOcean Converter](https://github.com/AllardLiao/EnOceanConverter.git) können von IP Symcon aus EnOcean-Telegramme an Controme gesendet werden und darüber z.B. auch EEP A5-04-02 nach A5-04-01 übersetzt werden.

Damit lassen sich aktuelle Daten zu Temperaturen, Fensterzuständen und Bewegung an der API vorbei an Controme senden.

## License

This project is licensed under the [CC BY-NC-SA 4.0 License](https://creativecommons.org/licenses/by-nc-sa/4.0/).

### Third-party Licenses

- This module uses **traits from the [StylePHP](https://github.com/symcon/StylePHP) project** by Symcon GmbH,
  licensed under [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/).

- This module uses **traits from Heiko Wilknitz** ([wilkware.de](https://wilkware.de)),
  licensed under [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/).

## Credits & Acknowledgments

This project was developed to integrate **Controme Smart Heating** systems into IP-Symcon.
Special thanks to:

- **Controme GmbH** ([controme.com](https://www.controme.com)) for supporting the project. API Informatiaon can be found at https://support.controme.com/api/
- **Symcon GmbH** for the [StylePHP](https://github.com/symcon/StylePHP) project, which served as a basis for parts of this module, as well as for review and support.
- **Heiko Wilknitz** ([wilkware.de](https://wilkware.de)) for providing open-source traits under [CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/).

This module is an independent community project and is not officially affiliated with or endorsed by Controme GmbH nor by Symcon GmbH.
