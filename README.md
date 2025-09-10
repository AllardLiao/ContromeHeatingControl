# Controme Heating Control

Folgende Module beinhaltet das Controme Heating Control Repository:

- __Controme Heating Control__ ([Dokumentation](Controme%20Heating%20Control))

IP-Symcon Modul zur lokalen Steuerung und Überwachung von Controme-Heizsystemen.

Automatisches Anlegen von Räumen, Sensoren, Ist-/Soll-Temperaturen, Luftfeuchte und Betriebsart.

Unterstützt Lesen und Schreiben über die Controme API. Vollständig in IPS integrierbar mit Timer und Variablenprofilen.

Das Module installiert eine Controme I/O-Instanz, den Controme Socket.
Dieser stellt die Verbindung zum Controme Mini-Server her.
Wenn die Verbindung steht können nach Abruf der Räume zwei weitere Typen von Kontroll-Geräten erstellt werden:
eine Zentrale Steuereinheit und je Raum einen Raum-Thermostat.

Controme I/O (type=2)
  |
  +-- Controme Central (type=3, child)
  |
  +-- Controme Room #1 (type=3, child)
  |
  +-- Controme Room #2 (type=3, child)


Hinweis:
Die Configuration und Benennung der Räume und Sensoren im Controme Mini-Servers sollten final abgeschlossen sein.
Wird dies nach Verbindung dieses Moduls in der Controme-App angepasst, ändern sich die Namen der Kategorien und
der Sensor-Variablen im IP-Symcon.
Dies bedeutet insbesondere, dass die Namen über die Definition im Controme-Mini-Server abgeleitet wird. Beachte dies bei der
Benennnung der Räume und Sensoren im Controme Mini-Server

by Kai J. Oey
