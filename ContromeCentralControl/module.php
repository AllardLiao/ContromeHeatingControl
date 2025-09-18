<?php
declare(strict_types=1);
/**
 * Controme Heating Control Module for IP-Symcon
 * Copyright (c) 2025 Kai J. Oey
 *
 * Licensed under the Creative Commons Attribution-NonCommercial-ShareAlike 4.0 International License.
 * See https://creativecommons.org/licenses/by-nc-sa/4.0/ for details.
 * See Read.me for Attribution
 */

// General functions
require_once __DIR__ . '/../libs/_traits.php';
// Bibliotheks-übergreifende Constanten einbinden
use Controme\GUIDs;
use Controme\ACTIONs;
use Controme\CONTROME_PROFILES;

class ContromeCentralControl extends IPSModuleStrict
{
    use DebugHelper;
    use EventHelper;
    use ProfileHelper;
    use VariableHelper;
    use VersionHelper;
    use FormatHelper;
    use WidgetHelper;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // Properties für die Konfiguration des Moduls
        $this->RegisterPropertyInteger("VisuColorMainTiles", 0xf5f5f5);
        $this->RegisterPropertyBoolean("ShowSystemInfo", true);
        $this->RegisterPropertyInteger("VisuColorSystemInfoTile", 0xf5f5f5);
        $this->RegisterPropertyBoolean("ShowRooms", true);
        $this->RegisterPropertyInteger("VisuColorRoomTiles", 0xf5f5f5);
        $this->RegisterPropertyBoolean("ShowRoomData", true);
        $this->RegisterPropertyBoolean("ShowRoomOffsets", false);
        $this->RegisterPropertyBoolean("ShowVTR", false);
        $this->RegisterPropertyBoolean("ShowTimer", false);
        $this->RegisterPropertyBoolean("ShowCalendar", false);

        //Konfigurationselemente der zyklischen Abfrage
        $this->RegisterPropertyInteger("UpdateInterval", 5); // in Minuten
        $this->RegisterPropertyBoolean("AutoUpdate", true);

        // Konfigurationselemente für Testabfragen
        $this->RegisterPropertyInteger("RoomID", 1);

        //Visu Type setzen:
        $this->SetVisualizationType(1);     // 1 = Tile Visu; 0 = Standard.

        // Timer für zyklische Abfrage (Voreingestellt: alle 5 Minuten)
        $this->RegisterTimer("UpdateContromeDataCentralControl" . $this->InstanceID, 5 * 60 * 1000, 'IPS_RequestAction(' . $this->InstanceID . ', "' . ACTIONs::UPDATE_DATA . '", true);');

        // Link zum Controme Gateway anpassen
        $ip = $this->RequestGatewayIPAddress();
        if ($ip !== null) {
            $this->UpdateFormField("ContromeIP", "caption", $ip . "/raumregelung-pro/");
        } else {
            $this->UpdateFormField("ContromeIP", "caption", "should be: ip-address-of-your-controme-gateway/raumregelung-pro/");
        }
    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        // Link zum Controme Gateway anpassen
        $ip = $this->RequestGatewayIPAddress();
        if ($ip !== null) {
            $this->UpdateFormField("ContromeIP", "caption", $ip . "/raumregelung-pro/");
        } else {
            $this->UpdateFormField("ContromeIP", "caption", "should be: ip-address-of-your-controme-gateway/raumregelung-pro/");
        }

        // Sicherstellen, dass die Variablen registriert sind
        // SystemInfo vorbereiten/anlegen
        if ($this->ReadPropertyBoolean("ShowSystemInfo")) {
            $this->registerSystemInfoVariables();
        }

        // Timer anpassen
        if ($this->ReadPropertyBoolean("AutoUpdate")) {
            $this->SetTimerInterval("UpdateContromeDataCentralControl" . $this->InstanceID, $this->ReadPropertyInteger("UpdateInterval") * 60 * 1000);
        } else {
            $this->SetTimerInterval("UpdateContromeDataCentralControl" . $this->InstanceID, 0);
        }
    }

    /**
     * Is called when, for example, a button is clicked in the visualization.
     *
     *  @param string $ident Ident of the variable
     *  @param string $value The value to be set
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        switch($ident) {
            case ACTIONs::UPDATE_DATA:
                $this->UpdateData();
                break;
            case ACTIONs::CHECK_CONNECTION:
                $this->CheckConnection();
                break;
            case ACTIONs::WRITE_SETPOINT:
                $result = $this->WriteSetpoint(floatval($value)); //TODO
                break;
            case ACTIONs::TEST_READ_ROOM_DATA:
                $this->TestReadRoomData();
                break;
            case 'Mode':
                $this->SetRoomMode((int)$value['roomId'], (int)$value['mode']);  //TODO
                break;
            case 'Temperature':
                $this->SetRoomTemperature((int)$value['roomId'], floatval($value['temperature'])); //TODO
                break;
            case 'Target':
                $this->SetRoomTemperatureTemp((int)$value['roomId'], floatval($value['target']), intval($value['duration'])); //TODO
                break;
            default:
                parent::RequestAction($ident, $value);
        }
    }

    private function updateVisualization(): void
    {
        // Daten für die Visualisierung aktualisieren
        $this->UpdateVisualizationValue(json_encode([
            'Setpoint'    => floatval($this->GetValue('Setpoint')),
            'Temperature' => floatval($this->GetValue('Temperature')),
            'Humidity'    => floatval($this->GetValue('Humidity')),
            'Mode'        => $this->GetValue('Mode')
        ]));
    }

    public function TestReadRoomData(): bool
    {
        $roomId  = $this->ReadPropertyInteger("RoomID");

        // Anfrage ans Gateway schicken
        $result = $this->SendDataToParent(json_encode([
            "DataID"  => GUIDs::DATAFLOW, // die gemeinsame DataFlow-GUID
            "Action"  => ACTIONs::GET_TEMP_DATA_FOR_ROOM,
            "RoomID"  => $roomId
        ]));

        if ($result === false) {
            $this->SendDebug(__FUNCTION__, "Fetching Data: no response from gateway!", 0);
            $this->LogMessage("TestReadRoomData: Fetching Data: no response from gateway", KL_ERROR);
            $this->UpdateFormField("ResultTestRead", "caption", "Fetching Data: no response from gateway");
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }

        $data = json_decode($result, true);
        if (isset($data['name'])) {
            $this->SendDebug(__FUNCTION__, "Fetching Data: Room $roomId found and data seems valid.", 0);
            $this->LogMessage("TestReadRoomData: Fetching Data: Room $roomId found and data seems valid. (Returned room name \"" . $data['name'] . "\" with temperature " . $data['temperatur'] . " °C.)", KL_MESSAGE);
            $this->UpdateFormField("ResultTestRead", "caption", "Fetching Data: Room $roomId found and data seems valid. (Returned room name \"" . $data['name'] . "\" with temperature " . $data['temperatur'] . " °C.)");
        } else {
            $this->SendDebug(__FUNCTION__, "Fetching Data ok, but room $roomId data not valid!", 0);
            $this->LogMessage("TestReadRoomData: Fetching Data ok, but room data not valid!", KL_ERROR);
            $this->UpdateFormField("ResultTestRead", "caption", "Fetching Data ok, but room data not valid");
            $this->SetStatus(IS_BAD_JSON);
            return false;
        }

        // Alles ok - also können wir auch direkt die Daten in Variablen Speichern.
        $this->SetStatus(IS_ACTIVE);
        return true;
    }
    // Funktion die zyklisch aufgerufen wird (wenn aktiv) und die Werte des Systems aktualisiert
    private function UpdateData(): bool
    {
        // Daten vom Gateway holen
        $result = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::GET_DATA_FOR_CENTRAL_CONTROL,
            ACTIONs::DATA_SYSTEM_INFO => $this->ReadPropertyBoolean("ShowSystemInfo"),
            ACTIONs::DATA_ROOMS       => $this->ReadPropertyBoolean("ShowRooms"),
            ACTIONs::DATA_ROOM_OFFSETS=> $this->ReadPropertyBoolean("ShowRoomOffsets"),
            ACTIONs::DATA_TEMPERATURS => $this->ReadPropertyBoolean("ShowRoomData"),
            ACTIONs::DATA_VTR         => $this->ReadPropertyBoolean("ShowVTR"),
            ACTIONs::DATA_TIMER       => $this->ReadPropertyBoolean("ShowTimer"),
            ACTIONs::DATA_CALENDAR    => $this->ReadPropertyBoolean("ShowCalendar")
        ]));

        if ($result === false) {
            $this->SendDebug(__FUNCTION__, "No data received!", 0);
            $this->LogMessage("Fetching Data returned no data!", KL_ERROR);
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, "Invalid data received: " . $result, 0);
            $this->LogMessage("Fetching Data returned invalid data!", KL_ERROR);
            $this->SetStatus(IS_BAD_JSON);
            return false;
        }

        $this->SendDebug(__FUNCTION__, "Room data updated", 0);
        $this->UpdateFormField("ResultUpdate", "caption", "Data updated at " . date("d.m.Y H:i:s"));
        $this->SetStatus(IS_ACTIVE);

        return $this->saveDataToVariables($data);
    }

    private function saveDataToVariables($data): bool
    {
        $this->SendDebug(__FUNCTION__, "Received data: " . print_r($data, true), 0);

        // ======================
        // System Info
        // ======================
        if ($this->ReadPropertyBoolean("ShowSystemInfo") && isset($data[ACTIONs::DATA_SYSTEM_INFO])) {
            //Existienz und bekanntheit der Variablen sicherstellen
            $this->registerSystemInfoVariables();

            $this->SendDebug(__FUNCTION__, "SystemInfo data found: " . print_r($data[ACTIONs::DATA_SYSTEM_INFO], true), 0);

            $info = $data[ACTIONs::DATA_SYSTEM_INFO];
            $this->SendDebug(__FUNCTION__, "Decoded info: " . print_r($info, true), 0);
            if (!is_array($info)) {
                $this->SendDebug(__FUNCTION__, "SystemInfo is not array: " . print_r($info, true), 0);
            }
            else {
                $this->SetValue("SysInfo_HW",           $info['hw'] ?? "");
                $this->SetValue("SysInfo_SWDate",       $info['sw-date'] ?? "");
                $this->SetValue("SysInfo_Branch",       $info['branch'] ?? "");
                $this->SetValue("SysInfo_OS",           $info['os'] ?? "");
                $this->SetValue("SysInfo_FBI",          $info['fbi'] ?? "");
                $this->SetValue("SysInfo_AppCompat",    $info['app-compatibility'] ?? false);
                $this->SendDebug(__FUNCTION__, "SystemInfo updated with values", 0);
            }
        } else {
            $this->SendDebug(__FUNCTION__, "SystemInfo not requested or not found in data: " . print_r($data, true), 0);
        }

        // ======================
        // Räume
        // ======================
        if (isset($data[ACTIONs::DATA_ROOMS]) || isset($data[ACTIONs::DATA_TEMPERATURS])) {

            //Für Reihenfolge im IPS-Baum. SysInfo 1-6
            $positionCounter = 10;

            $this->SendDebug(__FUNCTION__, "Room data found: " . print_r($data[ACTIONs::DATA_ROOMS], true), 0);

            $rooms = $data[ACTIONs::DATA_ROOMS];
            if (!is_array($rooms)) {
                $this->SendDebug(__FUNCTION__, "Rooms is not array: " . print_r($rooms, true), 0);
            }
            else {
                // Räume durchgehen
                foreach ($rooms as $floor) {
                    if (!isset($floor['raeume']) || !is_array($floor['raeume'])) continue;

                    $floorID   = $floor['id'] ?? 0;
                    $floorName = $floor['etagenname'] ?? "Unbekannt";
                    $floorVar = "Floor" . $floorID; // Name des Präfix der Variablen für Etagen im IPS Baum

                    // Variablen für Etage anlegen/pflegen
                    $this->MaintainVariable($floorVar . "ID",     $floorVar . "-ID",    VARIABLETYPE_INTEGER, "", $positionCounter++, true);
                    $this->SetValue(        $floorVar . "ID",     (int) $floorID);
                    $this->MaintainVariable($floorVar . "Name",   $floorVar . "-Name",   VARIABLETYPE_STRING, "", $positionCounter++, true);
                    $this->SetValue(        $floorVar . "Name",   (string) $floorName);

                    // Räume dieser Etage durchgehen
                    foreach ($floor['raeume'] as $room) {
                        $roomID   = $room['id'] ?? 0;
                        $roomName = $room['name'] ?? "Unbekannt";
                        $roomVar  = $floorVar . "Room" . $roomID; // Name des Präfix der Variablen für Räume im IPS Baum

                        // Raum-Variablen
                        $this->MaintainVariable($roomVar . "ID", $roomVar . "-ID", VARIABLETYPE_INTEGER, "", $positionCounter++, true);
                        $this->SetValue        ($roomVar . "ID", (int) $roomID);
                        $this->MaintainVariable($roomVar . "Name", $roomVar . "-Name", VARIABLETYPE_STRING, "", $positionCounter++, true);
                        $this->SetValue        ($roomVar . "Name", (string) $roomName);

                        if (isset($data[ACTIONs::DATA_TEMPERATURS])){
                            $this->MaintainVariable($roomVar . "Temperature",       $roomVar . "-Temperatur", VARIABLETYPE_FLOAT, "~Temperature", $positionCounter++, true);
                            $this->SetValue(        $roomVar . "Temperature",       floatval($room['temperatur']));
                            $this->MaintainVariable($roomVar . "Target",            $roomVar . "-Solltemperatur",  VARIABLETYPE_FLOAT, "~Temperature", $positionCounter++, true);
                            $this->SetValue(        $roomVar . "Target",            floatval($room['solltemperatur']));
                            $this->MaintainVariable($roomVar . "State",             $roomVar . "-Status",           VARIABLETYPE_STRING, "", $positionCounter++, true);
                            $this->SetValue(        $roomVar . "State",             $room['betriebsart']);
                            $this->MaintainVariable($roomVar . "Humidity",          $roomVar . "-Luftfeuchte",   VARIABLETYPE_FLOAT, "~Humidity.F", $positionCounter++, true);
                            $this->SetValue(        $roomVar . "Humidity",          floatval($room['luftfeuchte']));
                            $this->MaintainVariable($roomVar . "RemainingTime",     $roomVar . "-Restzeit",           VARIABLETYPE_INTEGER, "", $positionCounter++, true);
                            $this->SetValue(        $roomVar . "RemainingTime",     $room['remaining_time']);
                            $this->MaintainVariable($roomVar . "PermSolltemperatur", $roomVar . "-SolltemperaturNormal",   VARIABLETYPE_FLOAT, "~Temperature", $positionCounter++, true);
                            $this->SetValue(        $roomVar . "PermSolltemperatur", floatval($room['perm_solltemperatur']));
                        }
                    }
                }
            }
        } else {
            $this->SendDebug(__FUNCTION__, "Room info not requested or not found in data: " . print_r($data, true), 0);
        }

        return true;
    }

    private function RequestGatewayIPAddress(): ?string
    {
        // Check, ob Gateway eingerichtet ist.
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID == 0) {
            $this->SendDebug(__FUNCTION__, "Kein Gateway verbunden", 0);
            $this->LogMessage("Kein Gateway verbunden", KL_NOTIFY);
            $this->UpdateFormField("ContromeIP", "caption", "Kein Gateway verbunden...");
            return null;
        }

        $data = [
            'DataID'   => GUIDs::DATAFLOW,
            'Action'   => ACTIONs::GET_IP_ADDRESS
        ];

        $result = $this->SendDataToParent(json_encode($data));

        if (!is_string($result) || empty($result)) {
            $this->SendDebug(__FUNCTION__, "Keine IP-Adresse vom Gateway erhalten", 0);
            $this->LogMessage("Fehler: Gateway hat keine IP-Adresse geliefert", KL_NOTIFY);
            $this->UpdateFormField("ContromeIP", "caption", "IP konnte nicht abgerufen werden");
            return null;
        }

        $this->SendDebug(__FUNCTION__, "IP-Adresse vom Gateway: " . $result, 0);
        $this->LogMessage("Gateway-IP: " . $result, KL_MESSAGE);

        return $result;
    }

    private function registerSystemInfoVariables(): void
    {
        // Variablen System-Info anlegen/pflegen
        $this->MaintainVariable("SysInfo_HW",           "System_Hardware",          VARIABLETYPE_STRING, "", 1, true);
        $this->MaintainVariable("SysInfo_SWDate",       "System_Software Datum",    VARIABLETYPE_STRING, "", 2, true);
        $this->MaintainVariable("SysInfo_Branch",       "System_Branch",            VARIABLETYPE_STRING, "", 3, true);
        $this->MaintainVariable("SysInfo_OS",           "System_Betriebssystem",    VARIABLETYPE_STRING, "", 4, true);
        $this->MaintainVariable("SysInfo_FBI",          "System_Filesystem Build",  VARIABLETYPE_STRING, "", 5, true);
        $this->MaintainVariable("SysInfo_AppCompat",    "System_App kompatibel",    VARIABLETYPE_BOOLEAN, "~Switch", 6, true);
    }

    /**
     * Is called by pressing the button "Check Connection" from the instance configuration
     *
     * @return boolean
     */
    public function CheckConnection(): bool
    {
        // Einfache Abfrage über Gateway - das Gateway versucht für Raum 1 Daten zu bekommen und zu schreiben.
        $response = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::CHECK_CONNECTION
        ]));

        $result = json_decode($response, true);

        if ($result['success']) {
            $this->SendDebug(__FUNCTION__, "Connection to Gateway and Controme Mini-Server is working!", 0);
            $this->UpdateFormField("Result", "caption", "Connection to Gateway and Controme Mini-Server is working!\n");
            $this->LogMessage("Connection to Gateway and Controme Mini-Server is working!", KL_MESSAGE);
        } else {
            $this->SendDebug(__FUNCTION__, "Connection failed!", 0);
            $this->UpdateFormField("Result", "caption", "Connection failed!\n");
            $this->LogMessage("Connection failed!", KL_ERROR);
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }

        // Alles ok - also können wir auch direkt die Daten in Variablen Speichern.
        $this->SetStatus(IS_ACTIVE);
        return true;
    }

    public function GetVisualizationTile(): string
    {
        $rooms = $this->GetRoomData();  // holt alle Räume aus den Variablen
        $sysInfo = $this->GetSystemInfo();

        // ========================
        // 1. Mode-Options
        $modeOptions = '';
        foreach (CONTROME_PROFILES::$betriebsartMap as $id => $label) {
            $modeOptions .= '<option value="' . $id . '">' . $label . '</option>';
        }
        // ========================
        // 2. Dropdown für Räume: Alle Räume + Einzelräume sowie Max-Temp finden
        $roomOptions = '<option value="all">Alle Räume</option>';
        $maxTemp = 15.0;
        foreach ($rooms as $room) {
            if (!empty($room['name'])) {
                $roomOptions .= '<option value="' . $room['id'] . '">' . $room['name'] . '</option>';
            }
            if ((!empty($room['target'])) && ($maxTemp < floatval($room['target']))){
                $maxTemp = floatval($room['target']);
            }
        }
        // ========================
        // 3. Systeminfo HTML
        $sysHtml = '<div class="system-info-values">';
        foreach ($sysInfo as $key => $value) {
            $sysHtml .= '<div><strong>' . $key . ':</strong><span>' . ($value ?? '--') . '</span></div>';
        }
        $sysHtml .= '</div>';
        // ========================
        // 4. Raumtiles HTML
        $roomTilesHtml = '';
        foreach ($rooms as $room) {
            if (!empty($room['name'])) {
                $roomTilesHtml .= '<div class="room-tile" id="room_' . $room['id'] . '">'
                    . '<div class="room-header">' . $room['name'] . '</div>'
                    . '<div class="room-values">'
                        . '<div><strong>Ist:</strong><span>' . ($room['temperature'] ?? '--') . '°C</span></div>'
                        . '<div><strong>Soll:</strong><span>' . ($room['target'] ?? '--') . '°C</span></div>'
                        . '<div><strong>Luftfeuchte:</strong><span>' . ($room['humidity'] ?? '--') . '%</span></div>'
                        . '<div><strong>Status:</strong><span>' . ($room['state'] ?? '--') . '</span></div>'
                    . '</div>';

                if (!empty($room['remaining_time']) && $room['remaining_time'] > 0) {
                    $hours = floor($room['remaining_time'] / 60);
                    $minutes = $room['remaining_time'] % 60;
                    $hoursMinutes = sprintf("%02d:%02d", $hours, $minutes);
                    $roomTilesHtml .= '<div class="room-temp-schedule">'
                        . '<div><strong>Remaining:</strong><span>' . $hoursMinutes . '</span></div>'
                        . '<div><strong>Perm Soll:</strong><span>' . ($room['perm_solltemperatur'] ?? '--') . '°C</span></div>'
                    . '</div>';
                }
                $roomTilesHtml .= '</div>';
            }
        }
        // ========================
        // 5. Dropdown für Dauer in Stunden (0–24)
        $durationOptions = '';
        for ($h = 0; $h <= 24; $h++) {
            $durationOptions .= '<option value="' . $h . '">' . $h . 'h</option>';
        }
        // ========================
        // 6. HTML Template laden & Platzhalter ersetzen
        $html = file_get_contents(__DIR__ . '/module.html');
        $html = str_replace('<!--COLOR_MAIN_TILES-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorMainTiles")), 6, '0', STR_PAD_LEFT), $html);
        $html = str_replace('<!--COLOR_ROOM_TILES-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorRoomTiles")), 6, '0', STR_PAD_LEFT), $html);
        $html = str_replace('<!--COLOR_SYSTEM_INFO-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorSystemInfoTile")), 6, '0', STR_PAD_LEFT), $html);
        $html = str_replace('<!--MODE_OPTIONS-->', $modeOptions, $html);
        $html = str_replace('<!--FLOOR_ROOM_OPTIONS-->', $roomOptions, $html);
        $html = str_replace('<!--ROOM_TILES-->', $roomTilesHtml, $html);
        $html = str_replace('<!--SYS_INFO-->', $sysHtml, $html);
        $html = str_replace('<!--MAX_TEMP-->', number_format($maxTemp, 2, '.', ''), $html);
        $html = str_replace('<!--DURATION_OPTIONS-->', $durationOptions, $html);
        $this->SendDebug(__FUNCTION__, "HTML: " . $html, 0);
        return $html;
    }

    private function GetRoomData(): array
    {
        $rooms = [];
        $floorID = 1;

        while (@IPS_GetObjectIDByIdent("Floor{$floorID}ID", $this->InstanceID) !== false) {
            $roomID = 1;
            while (@IPS_GetObjectIDByIdent("Floor{$floorID}Room{$roomID}ID", $this->InstanceID) !== false) {
                $roomVar = "Floor{$floorID}Room{$roomID}";

                $rooms[] = [
                    'id' => $this->GetValue($roomVar . "ID"),
                    'name' => $this->GetValue($roomVar . "Name"),
                    'temperature' => $this->GetValue($roomVar . "Temperature"),
                    'target' => $this->GetValue($roomVar . "Target"),
                    'humidity' => $this->GetValue($roomVar . "Humidity"),
                    'state' => $this->GetValue($roomVar . "State"),
                    'remaining_time' => $this->GetValue($roomVar . "RemainingTime") ?? 0,
                    'perm_solltemperatur' => $this->GetValue($roomVar . "PermSolltemperatur") ?? 0
                ];
                $roomID++;
            }
            $floorID++;
        }
        return $rooms;
    }

    private function GetSystemInfo(): array
    {
        return [
            'Hardware' => $this->GetValue('SysInfo_HW'),
            'Software Datum' => $this->GetValue('SysInfo_SWDate'),
            'Branch' => $this->GetValue('SysInfo_Branch'),
            'OS' => $this->GetValue('SysInfo_OS'),
            'Filesystem Build' => $this->GetValue('SysInfo_FBI'),
            'App kompatibel' => $this->GetValue('SysInfo_AppCompat')
        ];
    }
}
