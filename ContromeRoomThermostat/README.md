
# ContromeRoomThermostat

Module description: Room thermostat for Controme heating systems in IP-Symcon.

## Table of Contents

1. [Features](#1-features)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Instance Setup in IP-Symcon](#4-instance-setup-in-ip-symcon)
5. [Status Variables and Profiles](#5-status-variables-and-profiles)
6. [Visualization](#6-visualization)
7. [PHP Command Reference](#7-php-command-reference)
8. [License](#8-license)

## 1. Features

- Representation of a room thermostat for the Controme heating system

## 2. Requirements

- IP-Symcon version 7.1 or higher
- Controme Mini-Server with API license

## 3. Installation

- Install the 'ContromeHeatingControl' module via the Module Store.
- Alternatively, add the following URL in Module Control: https://github.com/AllardLiao/ContromeHeatingControl.git

## 4. Instance Setup in IP-Symcon

You can find the 'ContromeRoomThermostat' instance using the quick filter under 'Add Instance'.

For more information on adding instances, see the [IP-Symcon documentation](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen).

__Configuration Page__:

| Name                          | Description                                                                 |
|-------------------------------|-----------------------------------------------------------------------------|
| Room assignment               | Floor id, room id, floor name, room name                                    |
| Advanced settings             |                                                                             |
| - Automatically update values | Automatically get values from Controme API / Mini-Server; default = true    |
| - Update interval             | Update interval for automatic updates; default = 1 min                      |
| Sensor fallback settings      |                                                                             |
| - Use fallback temperature sensors       | Use fallback sensor for temperature                                 |
| - Alternative fixed fallback temperature | If fallback sensor is off or unavailable, set a fixed temperature   |
| - Use fallback humidity sensors          | Use fallback sensor for humidity                                    |
| - Alternative fixed fallback humidity    | If fallback sensor is off or unavailable, set a fixed humidity      |

## 5. Status Variables and Profiles

Status variables are created automatically. Deleting individual variables may cause malfunctions.

#### Statusvariablen

Name              | Typ     | Beschreibung
----------------- | ------- | ----------------------------------------------------------------
Room temperatur   | float   | Temperature of the room
Setpoint          | float   | Setpoint of the room
Humidity          | float   | Humidity of the room
Operation Mode    | int     | Operation mode
Note              | string  | Note, indication e.g. if the backup values are currently taken

#### Profile

Name                  | Typ
--------------------- | -----------------------------------------------------------------
Controme.Betriebsart  | int (0-Kühlen, 1-Aus, 2-Heizen, 3-An) according to Controme-API

### 6. Visualisierung

Standard IP Symcon "Thermostat" visualisation tile.
You might need to swich the appearance in the visualisation setup:

![Visualisation options](../libs/assets/CONRT_Visu.jpeg)

### 7. PHP-Befehlsreferenz

* `string CONRT_WriteSetpoint(float SETPOINT);`
Writes the SETPOINT the Controme Mini-Server for the configured room of the instance.

Beispiel:
`CONRT_WriteSetpoint(22.1);`
Returns JSON:
{
    "success" => success/fail
    "msg" => e.g.: "Setpoint set to 22.1 °C"
    "payload" => []
}

* `string CONRT_CheckConnection();`
Checks the connection to the Controme Gateway (IPS) and the Controme Mini-Server.

Beispiel:
`CONRT_CheckConnection();`
Returns JSON:
{
    "success" => success/fail
    "msg" => Information message
    "payload" => addtl. information
}

* `string CONRT_GetEffectiveTemperature();`
Returns an JSON with information about the temperature of the room.
In case the Controme Mini-Server does not deliver a value, it is taken from the defined Backup-Sensor.

Beispiel:
`CONRT_GetEffectiveTemperature();`
Returns JSON, e.g.:
{
    "success" => success
    "msg" => Temperature for room 1 is 22.1 °C (fallback)
    "payload" => ["RoomID" => 1, "Temperature" => 22.1];
}

* `string CONRT_GetEffectiveHumidity();`
Returns an JSON with information about the humidity of the room.
In case the Controme Mini-Server does not deliver a value, it is taken from the defined Backup-Sensor.

Beispiel:
`CONRT_GetEffectiveHumidity();`
Returns JSON, e.g.:
{
    "success" => success
    "msg" => Humidity for room 1 is 46.8%
    "payload" => ["RoomID" => 1, "Humidity" => 46.8];
}

NOTE:
The returned JSON include also a "salt" string leading the keys, e.g. "RW88_" => "RW88_success"

### 8. Lizenz

This project is licensed under the
[Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International License](https://creativecommons.org/licenses/by-nc-sa/4.0/).
