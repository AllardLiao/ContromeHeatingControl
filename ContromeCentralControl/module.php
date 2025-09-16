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
        $this->RegisterPropertyBoolean("ShowSystemInfo", true);
        $this->RegisterPropertyBoolean("ShowRooms", true);
        $this->RegisterPropertyBoolean("ShowRoomData", true);
        $this->RegisterPropertyBoolean("ShowRoomOffsets", false);
        $this->RegisterPropertyBoolean("ShowVTR", false);
        $this->RegisterPropertyBoolean("ShowTimer", false);
        $this->RegisterPropertyBoolean("ShowCalendar", false);

        //Konfigurationselemente der zyklischen Abfrage
        $this->RegisterPropertyInteger("UpdateInterval", 5); // in Minuten
        $this->RegisterPropertyBoolean("AutoUpdate", true);

        // Konfigurationselemente für Testabfragen
        $this->RegisterpropertyInteger("RoomID", 1);

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
        // Rooms vorbereiten/anlegen
        if ($this->ReadPropertyBoolean("ShowRooms") || $this->ReadPropertyBoolean("ShowRoomData")) {
            $this->registerRoomCategory();
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
                $result = $this->WriteSetpoint(floatval($value));
                break;
            case ACTIONs::TEST_READ_ROOM_DATA:
                $this->TestReadRoomData();
                break;
            case 'visu_setpoint':
                //$this->WriteSetpoint((float)$value);
                //$this->updateVisualization();
                break;
        default:
                throw new Exception("Invalid function call to CONRTROME Room Thermostat. RequestAction: " . $ident);
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
            //Existienz der VAriablen sicherstellen
            $this->registerRoomCategory();

            $this->SendDebug(__FUNCTION__, "Room data found: " . print_r($data[ACTIONs::DATA_ROOMS], true), 0);

            $rooms = $data[ACTIONs::DATA_ROOMS];
            if (!is_array($rooms)) {
                $this->SendDebug(__FUNCTION__, "Rooms is not array: " . print_r($rooms, true), 0);
            }
            else {
                // Root-Kategorie für Räume sicherstellen
                $catRoomsID = @IPS_GetObjectIDByIdent("Rooms", $this->InstanceID);
                if ($catRoomsID === false) {
                    $catRoomsID = IPS_CreateCategory();
                    IPS_SetName($catRoomsID, "Rooms");
                    IPS_SetIdent($catRoomsID, "Rooms");
                    IPS_SetParent($catRoomsID, $this->InstanceID);
                }

                // Räume durchgehen
                foreach ($rooms as $floor) {
                    if (!isset($floor['raeume']) || !is_array($floor['raeume'])) continue;

                    $floorID   = $floor['id'] ?? 0;
                    $floorName = $floor['etagenname'] ?? "Unbekannt";

                    // Kategorie für Etage
                    $floorCatIdent = "Floor_" . $floorID;
                    $floorCatId = @IPS_GetObjectIDByIdent($floorCatIdent, $catRoomsID);
                    if ($floorCatId === false) {
                        $floorCatId = IPS_CreateCategory();
                        IPS_SetName($floorCatId, $floorName);
                        IPS_SetIdent($floorCatId, $floorCatIdent);
                        IPS_SetParent($floorCatId, $catRoomsID);
                    } else {
                        IPS_SetName($floorCatId, $floorName);
                    }

                    // Etagen-Variablen
                    $floorIdVar   = $this->ensureVariable("FloorID", "Etagen-ID", VARIABLETYPE_INTEGER, "", 5, $floorCatId);
                    $floorNameVar = $this->ensureVariable("FloorName", "Etagenname", VARIABLETYPE_STRING, "", 6, $floorCatId);

                    SetValue($floorIdVar, (int) $floorID);
                    SetValue($floorNameVar, (string) $floorName);

                    // Räume dieser Etage durchgehen
                    foreach ($floor['raeume'] as $room) {
                        $roomID   = $room['id'] ?? 0;
                        $roomName = $room['name'] ?? "Unbekannt";

                        $roomCatIdent = "Room_" . $roomID;
                        $roomCatId = @IPS_GetObjectIDByIdent($roomCatIdent, $floorCatId);
                        if ($roomCatId === false) {
                            $roomCatId = IPS_CreateCategory();
                            IPS_SetName($roomCatId, $roomName);
                            IPS_SetIdent($roomCatId, $roomCatIdent);
                            IPS_SetParent($roomCatId, $floorCatId);
                        } else {
                            IPS_SetName($roomCatId, $roomName);
                        }

                        // Raum-Variablen
                        $roomIdVar   = $this->ensureVariable("RoomID", "Raum-ID", VARIABLETYPE_INTEGER, "", 10, $roomCatId);
                        $roomNameVar = $this->ensureVariable("RoomName", "Raumname", VARIABLETYPE_STRING, "", 20, $roomCatId);

                        SetValue($roomIdVar, (int) $roomID);
                        SetValue($roomNameVar, (string) $roomName);

                        if (isset($data[ACTIONs::DATA_TEMPERATURS])){
                            $tempId    = $this->ensureVariable("Temperature", "Temperatur", VARIABLETYPE_FLOAT, "~Temperature", 30, $roomCatId);
                            $targetId  = $this->ensureVariable("Target", "Solltemperatur", VARIABLETYPE_FLOAT, "~Temperature", 40, $roomCatId);
                            $stateId   = $this->ensureVariable("State", "Status", VARIABLETYPE_STRING, "", 50, $roomCatId);
                            $humidityId = $this->ensureVariable("Humidity", "Luftfeuchte", VARIABLETYPE_FLOAT, "~Humidity.F", 60, $roomCatId);
                            SetValue($tempId, floatval($room['temperatur']));
                            SetValue($targetId, floatval($room['solltemperatur']));
                            SetValue($stateId, $room['betriebsart']);
                            SetValue($humidityId, floatval($room['luftfeuchte']));
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
        $parentId = $this->InstanceID;

        // Kategorie "SystemInfo" sicherstellen
        $sysCatId = @IPS_GetObjectIDByName("SystemInfo", $parentId);
        if ($sysCatId === false || $sysCatId === 0) {
            $sysCatId = IPS_CreateCategory();
            IPS_SetName($sysCatId, "SystemInfo");
            IPS_SetParent($sysCatId, $parentId);

            // Variablen in der Kategorie anlegen
            // Hilfsfunktion zum sicheren Anlegen
            $this->ensureVariable("SysInfo_HW", "Hardware", VARIABLETYPE_STRING, "", 10, $sysCatId);
            $this->ensureVariable("SysInfo_SWDate", "Software Datum", VARIABLETYPE_STRING, "", 11, $sysCatId);
            $this->ensureVariable("SysInfo_Branch", "Branch", VARIABLETYPE_STRING, "", 12, $sysCatId);
            $this->ensureVariable("SysInfo_OS", "Betriebssystem", VARIABLETYPE_STRING, "", 13, $sysCatId);
            $this->ensureVariable("SysInfo_FBI", "Filesystem Build", VARIABLETYPE_STRING, "", 14, $sysCatId);
            $this->ensureVariable("SysInfo_AppCompat", "App kompatibel", VARIABLETYPE_BOOLEAN, "~Switch", 15, $sysCatId);
        }
    }

    private function registerRoomCategory(): void
    {
        $parentId = $this->InstanceID;

        // Kategorie "Rooms" sicherstellen
        $roomsCatId = @IPS_GetObjectIDByIdent("Rooms", $this->InstanceID);
        if ($roomsCatId === false) {
            $roomsCatId = IPS_CreateCategory();
            IPS_SetName($roomsCatId, "Rooms");
            IPS_SetIdent($roomsCatId, "Rooms");
            IPS_SetParent($roomsCatId, $this->InstanceID);
        }
    }

    private function ensureVariable(string $ident, string $name, int $type, string $profile, int $position, int $parentId): int
    {
        $varId = @IPS_GetObjectIDByIdent($ident, $parentId);
        if ($varId === false) {
            $varId = IPS_CreateVariable($type);
            IPS_SetName($varId, $name);
            IPS_SetIdent($varId, $ident);
            IPS_SetParent($varId, $parentId);
            if ($profile != "") {
                IPS_SetVariableCustomProfile($varId, $profile);
            }
            IPS_SetPosition($varId, $position);
        } else {
            // Optional: vorhandene Variablen aktualisieren
            IPS_SetName($varId, $name);
            IPS_SetPosition($varId, $position);
            if ($profile != "") {
                IPS_SetVariableCustomProfile($varId, $profile);
            }
        }
        return $varId;
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
        $sysHtml = '';
        foreach ($sysInfo as $key => $value) {
            $sysHtml .= '<div><strong>' . $key . ':</strong> ' . ($value ?? '--') . '</div>';
        }
        // ========================
        // 4. Raumtiles HTML
        $roomTilesHtml = '';
        foreach ($rooms as $room) {
            $roomTilesHtml .= '<div class="room-tile" id="room_' . $room['id'] . '">'
                . '<span class="room-name">' . $room['name'] . '</span>'
                . '<span class="room-temp">' . ($room['temperature'] ?? '--') . '</span>'
                . '<span class="room-target">' . ($room['target'] ?? '--') . '</span>'
                . '<span class="room-humidity">' . ($room['humidity'] ?? '--') . '</span>'
                . '<span class="room-state">' . ($room['state'] ?? '--') . '</span>';
            // nur anzeigen, wenn remaining_time > 0
            if (!empty($room['remaining_time']) && $room['remaining_time'] > 0) {
                $hours = floor($room['remaining_time'] / 60);
                $minutes = $room['remaining_time'] % 60;
                $hoursMinutes = sprintf("%02d:%02d", $hours, $minutes);
                $roomTilesHtml .= '<span class="room-remaining">' . $hoursMinutes . '</span>'
                    . '<span class="room-perm-soll">' . ($room['perm_solltemperatur'] ?? '--') . '</span>';
            }
            $roomTilesHtml .= '</div>';
        }
        // ========================
        // 6. HTML Template laden & Platzhalter ersetzen
        $html = file_get_contents(__DIR__ . '/module.html');
        $html = str_replace('<!--MODE_OPTIONS-->', $modeOptions, $html);
        $html = str_replace('<!--FLOOR_ROOM_OPTIONS-->', $roomOptions, $html);
        $html = str_replace('<!--ROOM_TILES-->', $roomTilesHtml, $html);
        $html = str_replace('<!--SYS_INFO-->', $sysHtml, $html);
        $html = str_replace('<!--MAX_TEMP-->', number_format($maxTemp, 2, '.', ''), $html);
        $this->SendDebug(__FUNCTION__, "HTML: " . $html, 0);
        return $html;
    }

    private function GetRoomData(): array
    {
        $rooms = [];
        $catRoomsID = @IPS_GetObjectIDByIdent("Rooms", $this->InstanceID);
        if ($catRoomsID === false) return $rooms;

        $floorIDs = IPS_GetChildrenIDs($catRoomsID);
        foreach ($floorIDs as $floorID) {
            $roomIDs = IPS_GetChildrenIDs($floorID);
            foreach ($roomIDs as $roomID) {
                $rooms[] = [
                    'id' => $this->GetValueByParent('RoomID', $roomID),
                    'name' => $this->GetValueByParent('RoomName', $roomID),
                    'temperature' => $this->GetValueByParent('Temperature', $roomID),
                    'target' => $this->GetValueByParent('Target', $roomID),
                    'humidity' => $this->GetValueByParent('Humidity', $roomID),
                    'state' => $this->GetValueByParent('State', $roomID),
                    'remaining_time' => $this->GetValueByParent('RemainingTime', $roomID) ?? 0,
                    'perm_solltemperatur' => $this->GetValueByParent('PermSolltemperatur', $roomID) ?? 0
                ];
            }
        }
        return $rooms;
    }

    private function GetSystemInfo(): array
    {
        $catSysID = @IPS_GetObjectIDByIdent("SystemInfo", $this->InstanceID);
        if ($catSysID === false) return [];

        return [
            'Hardware' => $this->GetValueByParent('SysInfo_HW', $catSysID),
            'Software Datum' => $this->GetValueByParent('SysInfo_SWDate', $catSysID),
            'Branch' => $this->GetValueByParent('SysInfo_Branch', $catSysID),
            'OS' => $this->GetValueByParent('SysInfo_OS', $catSysID),
            'Filesystem Build' => $this->GetValueByParent('SysInfo_FBI', $catSysID),
            'App kompatibel' => $this->GetValueByParent('SysInfo_AppCompat', $catSysID)
        ];
    }

    // Hilfsfunktion, um Wert einer Variable anhand Ident in einer Kategorie zu holen
    private function GetValueByParent(string $ident, int $parentID)
    {
        $varId = @IPS_GetObjectIDByIdent($ident, $parentID);
        if ($varId === false) return null;
        return GetValue($varId);
    }
}
