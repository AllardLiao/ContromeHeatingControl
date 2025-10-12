
# ContromeGateway

Module description: Gateway for connecting IP-Symcon to the Controme Mini-Server.

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

- Connection to the Controme Mini-Server

## 2. Requirements

- IP-Symcon version 7.1 or higher
- Controme API license

## 3. Installation

- Install the 'Controme Gateway' module via the Module Store.
- Alternatively, add the following URL in Module Control: https://github.com/AllardLiao/ContromeHeatingControl.git

## 4. Instance Setup in IP-Symcon

You can find the 'ContromeGateway' splitter instance using the quick filter under 'Add Splitter Instance'.

For more information on adding instances, see the [IP-Symcon documentation](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen).

__Configuration Page__:

| Name                       | Description                                                                                                   |
|----------------------------|--------------------------------------------------------------------------------------------------------------|
| Credentials                | User account (username / password)                                                                            |
| Device IP                  | IP address of the Controme Mini-Server (local IP recommended; Controme does not support credential encryption) |
| Expert Settings            |                                                                                                              |
| - House id                 | Identifier of the house (usually 1, rarely 2 according to Controme)                                           |
| - Use HTTPS                | DO NOT ACTIVATE - currently (Oct 2025) not implemented by Controme                                            |
| Controme Instance Creation |                                                                                                               |
| - Target category          | Category where new instances (room thermostat or central control) will be placed                              |
| - Button "CREATE CC"       | Creates a central control instance                                                                            |
| - List "rooms"             | List of rooms configured in the Controme Mini-Server configuration                                            |
| - Button "CREATE RT"       | Creates a room thermostat instance for the selected room                                                      |

## 5. Status Variables and Profiles

n/a

#### Statusvariablen

n/a

#### Profile

n/a

### 6. Visualisierung

There is not visualisation - only the configuration form:

![Configuration form](../libs/assets/CONGW_Form.jpeg)


### 7. PHP-Befehlsreferenz

* `string CONGW_FetchRooms();`
Holt über das Controme API konfigurierte Räumen.

Beispiel:
`CONGW_FetchRooms();`

Return:
JSON encoded list of rooms

* `string CONGW_FetchSystemInfo();`
Holt über das Controme API Systeminformationen des Mini-Servers.

Beispiel:
`CONGW_SystemInfo();`

Return:
JSON encoded system information

* `string CONGW_GetTempDataForRoom(int room-id);`
Holt über das Controme API Daten zu dem Raum mit Nummer room-id vom Controme Mini-Servers.

Beispiel:
`CONGW_SystemInfo();`

Return:
JSON encoded temperature information

* `string CONGW_CheckConnection();`
Checks the connection to the Controme Gateway (IPS) and the Controme Mini-Server.

Beispiel:
`CONGW_CheckConnection();`
Returns JSON:
{
    "success" => success/fail
    "msg" => Information message
    "payload" => addtl. information
}


### 8. Lizenz

This project is licensed under the
[Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International License](https://creativecommons.org/licenses/by-nc-sa/4.0/).
