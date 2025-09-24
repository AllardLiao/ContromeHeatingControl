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
    use ReturnWrapper;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        // Properties für die Konfiguration des Moduls
        $this->RegisterPropertyBoolean("ShowMainElements", true);
        $this->RegisterPropertyInteger("VisuColorMainTiles", 0xd0cdcd);
        $this->RegisterPropertyBoolean("ShowSystemInfo", true);
        $this->RegisterPropertyInteger("VisuColorSystemInfoTile", 0xd6dbff);
        $this->RegisterPropertyBoolean("ShowRooms", true);
        $this->RegisterPropertyInteger("VisuColorRoomTiles", 0xf0f0f0);
        $this->RegisterPropertyInteger("VisuColorFloorTiles", 0xd9d9d9);
        $this->RegisterPropertyBoolean("ShowRoomData", true);
        $this->RegisterPropertyBoolean("ShowRoomOffsets", false);
        $this->RegisterPropertyBoolean("ShowRoomOffsetsOnlyActive", false);
        $this->RegisterPropertyBoolean("ShowRoomSensors", false);
        $this->RegisterPropertyBoolean("ShowVTR", false);
        $this->RegisterPropertyBoolean("ShowTimer", false);
        $this->RegisterPropertyBoolean("ShowCalendar", false);
        $this->RegisterPropertyInteger("DurationOfMessagePopup", 8);
        $this->RegisterPropertyInteger("VisuColorText", 0x000000);
        $this->RegisterPropertyInteger("VisuColorModeButton", 0x00a9f4);
        $this->RegisterPropertyInteger("VisuColorTempButtons", 0xfb4f2a);

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
        //$this->updateIPAddress();
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

        // Wenn die Instanz noch nicht fertigkonfiguriert ist - abbrechen.
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $this->LogMessage("Gateway Connection ID: " . $parentID, KL_NOTIFY);
        if ($parentID == 0) {
            $this->LogMessage("No gateway connected!", KL_WARNING);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        // Link zum Controme Gateway anpassen
        // $this->updateIPAddress();

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
                $this->updateData();
                break;
            case ACTIONs::CHECK_CONNECTION:
                $this->checkConnection();
                break;
            case ACTIONs::TEST_READ_ROOM_DATA:
                $this->testReadRoomData();
                break;
            case ACTIONs::VISU_CC_MODE:
                $response = $this->setRoomMode($value);
                $this->sendVisuAction($this->isError($response) ? "ERROR" : "SUCCESS", $this->getResponsePayload($response));
                $this->sendVisuAction("ENABLE_BUTTON", ['id' => "btn_set_mode"]);
                $this->updateData();
                break;
            case ACTIONs::VISU_CC_SETPOINT:
                $response = $this->setRoomTemperature($value);
                $this->sendVisuAction($this->isError($response) ? "ERROR" : "SUCCESS", $this->getResponsePayload($response));
                $this->sendVisuAction("ENABLE_BUTTON", ['id' => "btn_set_temp"]);
                $this->updateData();
                break;
            case ACTIONs::VISU_CC_TARGET:
                $response = $this->setRoomTemperatureTemp($value);
                $this->sendVisuAction($this->isError($response) ? "ERROR" : "SUCCESS", $this->getResponsePayload($response));
                $this->sendVisuAction("ENABLE_BUTTON", ['id' => "btn_set_target"]);
                $this->updateData();
                break;
            default:
                parent::RequestAction($ident, $value);
        }
    }

    public function testReadRoomData(): string
    {
        $roomId  = $this->ReadPropertyInteger("RoomID");

        // Anfrage ans Gateway schicken
        $result = $this->SendDataToParent(json_encode([
            "DataID"  => GUIDs::DATAFLOW, // die gemeinsame DataFlow-GUID
            "Action"  => ACTIONs::GET_TEMP_DATA_FOR_ROOM,
            "RoomID"  => $roomId
        ]));

        $errMsg = "Error fetching Data for Room " . $roomId;
        if ($this->isError($result)) {
            $this->UpdateFormField("ResultTestRead", "caption", $errMsg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $errMsg);
        }

        $data = json_decode($result, true);

        if (isset($data['name']) && isset($data['temperatur']) && is_numeric($data['temperatur'])) {
            $msg = "Room found and data seems valid. (Returned data: " . $data['name'] . ", " . $data['temperatur'] . " °C.)";
            $this->UpdateFormField("ResultTestRead", "caption", $msg);
            $this->SetStatus(IS_ACTIVE);
            return $this->wrapReturn(true, "Fetching Data: Room $roomId found and data seems valid.", $data);
        } else {
            $msg = "Getting data but data seems not valid. (Returned data: " . print_r($data, true) . ")";
            $this->UpdateFormField("ResultTestRead", "caption", $msg);
            $this->SetStatus(IS_BAD_JSON);
            return $this->wrapReturn(false, $msg);
        }
    }

    // Funktion die zyklisch aufgerufen wird (wenn aktiv) und die Werte des Systems aktualisiert
    private function updateData(): bool
    {
        // Daten vom Gateway holen
        $result = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::GET_DATA_FOR_CENTRAL_CONTROL,
            ACTIONs::DATA_SYSTEM_INFO   => $this->ReadPropertyBoolean("ShowSystemInfo"),
            ACTIONs::DATA_ROOMS         => $this->ReadPropertyBoolean("ShowRooms"),
            ACTIONs::DATA_ROOM_OFFSETS  => $this->ReadPropertyBoolean("ShowRoomOffsets"),
            ACTIONs::DATA_ROOM_SENSORS  => $this->ReadPropertyBoolean("ShowRoomSensors"),
            ACTIONs::DATA_EXTENDED      => $this->ReadPropertyBoolean("ShowRoomData"),
            ACTIONs::DATA_ROOM_SENSORS  => $this->ReadPropertyBoolean("ShowRoomSensors"),
            ACTIONs::DATA_VTR           => $this->ReadPropertyBoolean("ShowVTR"),
            ACTIONs::DATA_TIMER         => $this->ReadPropertyBoolean("ShowTimer"),
            ACTIONs::DATA_CALENDAR      => $this->ReadPropertyBoolean("ShowCalendar")
        ]));

        // Achtung - hier kommt ein ziemlich verschachteltes JSON zurück.
        // denn z. B. konnten die SystemInfos geladen werden, aber nicht der Raum
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

    private function saveDataToVariables(array $data): bool
    {
        $this->SendDebug(__FUNCTION__, "Received data: " . print_r($data, true), 0);

        //  Message an Visu vorbereiten
        $updatesVisu = [];

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
                //$updatesVisu[] = ['id' => "sysinfo_hw", 'value' => $info['hw'] ?? ""];lkhj
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
        // Räume, Temperaturen & Offsets
        // ======================
        if (isset($data[ACTIONs::DATA_ROOMS]) || isset($data[ACTIONs::DATA_EXTENDED]) || isset($data[ACTIONs::DATA_ROOM_OFFSETS])) {

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
                        $roomNote = ""; // Hinweise werden bei jedem Speichern neu generiert.
                        $roomVar  = $floorVar . "Room" . $roomID; // Name des Präfix der Variablen für Räume im IPS Baum

                        // Raum-Variablen
                        $this->MaintainVariable($roomVar . "ID", $roomVar . "-ID", VARIABLETYPE_INTEGER, "", $positionCounter++, true);
                        $this->SetValue        ($roomVar . "ID", (int) $roomID);
                        $this->MaintainVariable($roomVar . "Name", $roomVar . "-Name", VARIABLETYPE_STRING, "", $positionCounter++, true);
                        $this->SetValue        ($roomVar . "Name", (string) $roomName);
                        // Prüfen ob in einem der RT zu der Temperatur ggf. ein Fallback festgelegt ist:
                        $this->MaintainVariable($roomVar . "Temperature",       $roomVar . "-Temperatur", VARIABLETYPE_FLOAT, "~Temperature", $positionCounter++, true);
                        $temperature = isset($room['temperatur']) && is_numeric($room['temperatur']) ? floatval($room['temperatur']) : 0.0;
                        if (!isset($room['temperatur']) || is_null($room['temperatur']) || !is_numeric($room['temperatur'])) {
                            $this->SendDebug("CONCC - saveVariables", "Checking temperature fallback room: " . $roomID, 0);
                            $thermostats = IPS_GetInstanceListByModuleID(GUIDs::ROOM_THERMOSTAT);
                            foreach ($thermostats as $instID) {
                                $this->SendDebug("CONCC - saveVariables", "Checking temperature fallback with instance: " . $instID, 0);
                                $response = CONRT_GetEffectiveTemperature($instID);
                                $payload = $this->getResponsePayload($response);
                                if ((int)$payload["RoomID"] === (int)$roomID){
                                    // Es gibt eine Instanz das uns "korrektere" daten liefern kann
                                    $temperature = $payload["Temperature"];
                                    if (str_contains($this->getResponseMessage($response), "allback")) {
                                        $roomNote .= "Temperatur from fallback device. ";
                                    }
                                }
                            }
                        }
                        $this->SetValue($roomVar . "Temperature",       $temperature);
                        $updatesVisu[] = ['id' => "room_" . $roomID . "_temperature", 'value' => $temperature . " °C", "allowHtml" => true];

                        $this->MaintainVariable($roomVar . "Target",            $roomVar . "-Solltemperatur",  VARIABLETYPE_FLOAT, "~Temperature", $positionCounter++, true);
                        $this->SetValue(        $roomVar . "Target",            floatval($room['solltemperatur']));
                        $this->MaintainVariable($roomVar . "RemainingTime",     $roomVar . "-Restzeit",           VARIABLETYPE_INTEGER, "", $positionCounter++, true);
                        $this->SetValue(        $roomVar . "RemainingTime",     intval($room['remaining_time']));
                        $this->MaintainVariable($roomVar . "PermSolltemperatur", $roomVar . "-SolltemperaturNormal",   VARIABLETYPE_FLOAT, "~Temperature", $positionCounter++, true);
                        $this->SetValue(        $roomVar . "PermSolltemperatur", floatval($room['perm_solltemperatur']));
                        $this->MaintainVariable($roomVar . "OffsetTotal",        $roomVar . "-TotalOffset",   VARIABLETYPE_FLOAT, "~Temperature", $positionCounter++, true);
                        $this->SetValue(        $roomVar . "OffsetTotal",        isset($room['total_offset']) ? floatval($room['total_offset']) : 0.0);

                        // In der Anzeige wird bei temporärer Änderung die Darstellung gedreht und die Solltemp durchgestrichen
                        $hoursMinutes = "00:00";
                        if (!empty($room['remaining_time']) && $room['remaining_time'] > 0) {
                            $hours = floor($room['remaining_time'] / 3600); // Die Remaining Time wird vonder API in Sekunden geliefert, schreiben müssen wir aber in Minuten - Damit das einheitlich ist, Anzeige in Minuten.
                            $minutes = floor(($room['remaining_time'] % 3600) / 60);
                            $hoursMinutes = sprintf("%02d:%02d", $hours, $minutes);
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_target", 'value' => "<s>" . number_format(floatval($room['perm_solltemperatur']), 2, ',', '') . " °C</s>", "allowHtml" => true];
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_target_temp", 'value' => number_format(floatval($room['solltemperatur']), 2, ',', '') . " °C", "allowHtml" => true];
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_target_temp_time", 'value' => $hoursMinutes . " h", "allowHtml" => true];
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_target_temp_block", 'show' => true];
                        } else {
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_target", 'value' => number_format(floatval($room['solltemperatur']), 2, ',', '') . " °C", "allowHtml" => true];
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_target_temp", 'value' => number_format(floatval($room['perm_solltemperatur']), 2, ',', '') . " °C", "allowHtml" => true];
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_target_temp_block", 'show' => false];
                        }

                        $updatesVisu[] = ['id' => "room_" . $roomID . "_offset_sum", 'value' => number_format(isset($room['total_offset']) ? floatval($room['total_offset']) : 0.0, 2, ',', '') . " °C", "allowHtml" => true];

                        if (isset($data[ACTIONs::DATA_EXTENDED])){
                            $this->MaintainVariable($roomVar . "State",             $roomVar . "-Status",           VARIABLETYPE_STRING, "", $positionCounter++, true);
                            $this->SetValue(        $roomVar . "State",             $room['betriebsart']);
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_state", 'value' => $room['betriebsart'], "allowHtml" => true];
                            $this->MaintainVariable($roomVar . "Humidity",          $roomVar . "-Luftfeuchte",   VARIABLETYPE_FLOAT, "~Humidity.F", $positionCounter++, true);
                            $humidity = isset($room['luftfeuchte']) && is_numeric($room['luftfeuchte']) ? floatval($room['luftfeuchte']) : 0.0;
                            // Prüfen ob in einem der RT zu der Humidity ggf. ein Fallback festgelegt ist:
                            if (!isset($room['luftfeuchte']) || is_null($room['luftfeuchte']) || !is_numeric($room['luftfeuchte']) || (floatval($room['luftfeuchte']) <= 0) || (floatval($room['luftfeuchte']) > 100)) {
                                $this->SendDebug("CONCC - saveVariables", "Checking humidity fallback room: " . $roomID, 0);
                                $thermostats = IPS_GetInstanceListByModuleID(GUIDs::ROOM_THERMOSTAT);
                                foreach ($thermostats as $instID) {
                                    $response = CONRT_GetEffectiveHumidity($instID);
                                    $this->SendDebug(__FUNCTION__, "Check Fallback result: " . print_r($response, true));
                                    $payload = $this->getResponsePayload($response);
                                    if ((int)$payload["RoomID"] === (int)$roomID){
                                        // Es gibt eine Instanz das uns "korrektere" daten liefern kann
                                        $humidity = $payload["Humidity"];
                                        if (str_contains($this->getResponseMessage($response), "allback")) { // für Fallback und fallback
                                            $roomNote .= "Humidity from fallback device. ";
                                        }
                                    }
                                }
                            }
                            $this->SetValue(    $roomVar . "Humidity",          $humidity);
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_humidity",          'value' => number_format($humidity, 2, ',', '') . "%", "allowHtml" => true];
                        }
                        // ---------------------------
                        // Offsets verarbeiten (wenn vorhanden)
                        // API-Felder: 'offsets' (object mit Plugins => { "raum": x, "haus": y } oder {} ) =>> Offsets kommenals JSON!
                        if (isset($data[ACTIONs::DATA_ROOM_OFFSETS])){
                            // Offsets-JSON (komplette Struktur zur weiteren Auswertung)
                            $offsetsArray = [];
                            if (isset($room['offsets']) && is_array($room['offsets'])) {
                                $offsetsArray = $room['offsets'];
                            }
                            $this->MaintainVariable($roomVar . "OffsetsJSON", $roomVar . "-Offsets", VARIABLETYPE_STRING, "", $positionCounter++, true);
                            $this->SetValue(        $roomVar . "OffsetsJSON", json_encode($offsetsArray, JSON_UNESCAPED_UNICODE));

                            // Offsets-Summe berechnen
                            $sum = 0.0;
                            foreach ($offsetsArray as $values) {
                                $sum += isset($values['raum']) ? floatval($values['raum']) : 0;
                            }
                            $updatesVisu[] = ['id' => "room_" . $roomID . "_offset_sum", 'value' => number_format($sum, 2, ',', '') . " °C", "allowHtml" => true];
                            // Offsets-Details für Visu-Update
                            foreach ($room['offsets'] as $offsetName => $values) {
                                $raumVal = isset($values['raum']) ? floatval($values['raum']) : 0;
                                $updatesVisu[] = ['id' => "room_" . $roomID . "_offset_" . $offsetName . "_name", 'value' => htmlspecialchars($offsetName), "allowHtml" => true];
                                $updatesVisu[] = ['id' => "room_" . $roomID . "_offset_" . $offsetName . "_value", 'value' => number_format($raumVal, 2, ',', '') . " °C", "allowHtml" => true];
                            }
                            // Aktive Plugins: Anzahl und Summe der 'raum'-Offsets (nur die mit numerischem 'raum' zählen)
                            $activeCount = 0;
                            if (is_array($offsetsArray)) {
                                foreach ($offsetsArray as $pluginName => $pluginData) {
                                    if (is_array($pluginData) && isset($pluginData['raum']) && is_numeric($pluginData['raum']) && (abs($pluginData['raum']) > 0.00001)) $activeCount++;
                                }
                            }
                            $this->MaintainVariable($roomVar . "OffsetActiveCount", $roomVar . "-ActiveOffsetPluginsCount", VARIABLETYPE_INTEGER, "", $positionCounter++, true);
                            $this->SetValue($roomVar . "OffsetActiveCount", $activeCount);
                        }
                        // ---------------------------
                        // Sensoren verarbeiten (wenn vorhanden)
                        // API-Feld: 'sensoren' (array mit { name, beschreibung, wert, raumtemperatursensor, letzte_uebertragung }) =>> Offsets kommenals ARRAY!
                        if (isset($data[ACTIONs::DATA_ROOM_SENSORS])){
                            $sensorsArray = [];
                            if (isset($room['sensoren']) && is_array($room['sensoren'])) {
                                $sensorsArray = $room['sensoren'];
                            } elseif (isset($room['sensor']) && is_array($room['sensor'])) {
                                $sensorsArray = $room['sensor']; // fallback falls anders benannt
                            }
                            $sensorCount = count($sensorsArray);
                            $this->MaintainVariable($roomVar . "SensorCount", $roomVar . "-SensorCount", VARIABLETYPE_INTEGER, "", $positionCounter++, true);
                            $this->SetValue($roomVar . "SensorCount", $sensorCount);
                            $this->MaintainVariable($roomVar . "SensorsJSON", $roomVar . "-Sensors", VARIABLETYPE_STRING, "", $positionCounter++, true);
                            $this->SetValue($roomVar . "SensorsJSON", json_encode($sensorsArray, JSON_UNESCAPED_UNICODE));
                            // Wenn vorhanden, primären (Raum-)Temperatursensor extrahieren
                            $primaryName = "";
                            $primaryValue = 0.0;
                            $primaryLastInfo = "";
                            foreach ($sensorsArray as $s) {
                                if (is_array($s) && (!empty($s['raumtemperatursensor']) || (isset($s['raumtemperatursensor']) && $s['raumtemperatursensor'] === true))) {
                                    $primaryName = $s['beschreibung'] ?? ($s['name'] ?? "n/a");
                                    $primaryNameKey = preg_replace('/\s+/', '', strtolower($primaryName));
                                    $primaryValue = isset($s['wert']) ? floatval($s['wert']) : 0.0;
                                    $primaryLastInfo = $s['letzte_uebertragung'] ?? "n/a";
                                    $this->MaintainVariable($roomVar . "PrimarySensorName", $roomVar . "-PrimarySensorName", VARIABLETYPE_STRING, "", $positionCounter++, true);
                                    $this->SetValue($roomVar . "PrimarySensorName", (string)$primaryName);
                                    $this->MaintainVariable($roomVar . "PrimarySensorValue", $roomVar . "-PrimarySensorValue", VARIABLETYPE_FLOAT, "~Temperature", $positionCounter++, true);
                                    $this->SetValue($roomVar . "PrimarySensorValue", is_null($primaryValue) ? 0.0 : $primaryValue);
                                    $this->MaintainVariable($roomVar . "PrimarySensorLastInfo", $roomVar . "-PrimarySensorLastInfo", VARIABLETYPE_STRING, "", $positionCounter++, true);
                                    $this->SetValue($roomVar . "PrimarySensorLastInfo", (string)$primaryLastInfo);
                                    $updatesVisu[] = ['id' => "room_" . $roomID . "_primarysensor_" . $primaryNameKey . "_value", 'value' => number_format($primaryValue, 2, ',', '') . " °C", "allowHtml" => true];
                                    $updatesVisu[] = ['id' => "room_" . $roomID . "_primarysensor_" . $primaryNameKey . "_lastinfo", 'value' => htmlspecialchars($primaryLastInfo), "allowHtml" => true];
                                    break;
                                }
                            }
                            $otherSensors = array_filter($sensorsArray, function($s) use ($room, $primaryName) {
                                return $s['name'] !== $primaryName;
                            });
                            foreach ($otherSensors as $sensor) {
                                if (!$sensor['raumtemperatursensor']) {
                                    $sensorKey = preg_replace('/\s+/', '', strtolower($sensor['name']));
                                    $updatesVisu[] = ['id' => "room_" . $roomID . "_sensor_" . $sensorKey . "_value", 'value' => (isset($sensor['wert']) && is_numeric($sensor['wert']) ? number_format(floatval($sensor['wert']), 2, ',', '') . " °C" : 'n/a'), "allowHtml" => true];
                                    $updatesVisu[] = ['id' => "room_" . $roomID . "_sensor_" . $sensorKey . "_lastinfo", 'value' => (isset($sensor['letzte_uebertragung']) ? date('d.m. H:i', strtotime($sensor['letzte_uebertragung'])) : 'n/a'), "allowHtml" => true];
                                }
                            }
                        }
                        $this->MaintainVariable($roomVar . "Note",  $roomVar . "-Hinweis",   VARIABLETYPE_STRING, "", $positionCounter++, true);
                        $this->SetValue(        $roomVar . "Note",  $roomNote);
                        $updatesVisu[] = ['id' => "room_" . $roomID . "_note",  'value' => $roomNote, "allowHtml" => true];
                    }
                }
            }
        } else {
            $this->SendDebug(__FUNCTION__, "Room info not requested or not found in data: " . print_r($data, true), 0);
        }
        $this->sendVisuAction("REFRESH", [
            'updates' => $updatesVisu
        ]);
        return true;
    }

    private function requestGatewayIPAddress(): string
    {
        // Check, ob Gateway eingerichtet ist.
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID == 0) {
            $errMsg = "No gateway connected!";
            $this->UpdateFormField("ContromeIP", "caption", $errMsg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $errMsg);
        }
        $this->LogMessage("Gateway connected: " . $parentID);

        $result = $this->SendDataToParent(json_encode([
            'DataID'   => GUIDs::DATAFLOW,
            'Action'   => ACTIONs::GET_IP_ADDRESS
        ]));
        $this->LogMessage("Gateway returned: " . print_r($result, true));

        if (!is_string($result) || empty($result)) {
            $errMsg = "Please check gateway! Gateway did not return any information.";
            $this->UpdateFormField("ContromeIP", "caption", $errMsg);
            return $this->wrapReturn(false, $errMsg);
        }
        else {
            return $this->wrapReturn(true, "IPAddress", $result);
        }
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
    public function checkConnection(): string
    {
        // Gibt keine Voraussetzungen zu prüfen - diese sind im Gateway gespeichert.

        // Check, ob Gateway eingerichtet ist.
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID == 0) {
            $errMsg = "No gateway connected!";
            $this->UpdateFormField("ContromeIP", "caption", $errMsg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $errMsg);
        }
        $this->LogMessage("Gateway connected: " . $parentID);

        // Abfrage über Gateway - das Gateway versucht im Standard für Raum 1 Daten zu bekommen und zu schreiben.
        $response = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::CHECK_CONNECTION
        ]));

        if ($this->isError($response))
        {
            $msg = "Connection to gateway and Controme Mini-Server failed!";
            $this->UpdateFormField("Result", "caption", $msg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $msg);
        }
        else {
            $msg = "Connection to gateway and Controme Mini-Server is working!";
            $this->UpdateFormField("Result", "caption", $msg);
            $this->SetStatus(IS_ACTIVE);
            return $this->wrapReturn(true, $msg);
        }
    }

    public function getVisualizationTile(): string
    {
        // ========================================================================================================================
        // 1. Mode-Options
        $modeOptions = '';
        foreach (CONTROME_PROFILES::$betriebsartMap as $id => $label) {
            $modeOptions .= '<option value="' . $id . '">' . $label . '</option>';
        }
        // ========================================================================================================================
        // 2.Dropdown für Räume: Alle Räume + Einzelräume sowie Max-Temp finden
        $rooms = $this->getRoomData();  // holt alle Räume aus den Variablen
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
        // ========================================================================================================================
        // 3. Systeminfo HTML
        $sysInfo = $this->getSystemInfo();
        $this->SendDebug(__FUNCTION__, "Sysinfo: " . print_r($sysInfo, true), 0);
        $sysHtml = '<div class="system-info" id="system-info">'
                    .'<label>System Info</label>'
                    .'<div class="system-info-values">';
        foreach ($sysInfo as $key => $value) {
            $sysHtml .= '<div><strong>' . $key . ':</strong><span  id="room_' . $room['id'] . '_sysinfo_' . preg_replace('/\s+/', '', $key) . '">' . ($value ?? 'n/a') . '</span></div>';
            $this->SendDebug(__FUNCTION__, "Sysinfo Key: $key Value: $value", 0);
        }
        $sysHtml .= '</div>'
                    .'</div>';
        // ========================================================================================================================
        // 4. Raumtiles HTML mit Etagen-Gruppierung
        $floorGroups = [];
        foreach ($rooms as $room) {
            if (!empty($room['name'])) {
                $floor = $room['floor'] ?? 'Unbekannt'; // fallback
                if (!isset($floorGroups[$floor])) {
                    $floorGroups[$floor] = array();
                    $floorGroups[$floor]['floor'] = array('name' => $room['floorname'], 'id' => $room['floorid']);
                    $floorGroups[$floor]['roomsHtml'] = '';
                }
                // *************** B A S I C   R O O M   D A T A *******************
                $hoursMinutes = "00:00";
                if (!empty($room['remaining_time']) && $room['remaining_time'] > 0) {
                    $hours = floor($room['remaining_time'] / 3600); // Die Remaining Time wird vonder API in Sekunden geliefert, schreiben müssen wir aber in Minuten - Damit das einheitlich ist, Anzeige in Minuten.
                    $minutes = floor(($room['remaining_time'] % 3600) / 60);
                    $hoursMinutes = sprintf("%02d:%02d", $hours, $minutes);
                }

                $tempStatusOk = '<span class="temp-status" id="room_' . $room['id'] . '_temp_status temp-ok">✔️</span>';
                $tempStatusCold = '<span class="temp-status" id="room_' . $room['id'] . '_temp_status temp-cold">❄️</span>';
                $tempStatusWarm = '<span class="temp-status" id="room_' . $room['id'] . '_temp_status temp-warm">🔥</span>';
                $tempDiff = floatval($room['target']) - floatval($room['temperature']);
                if ($tempDiff < -1) {
                    $tempStatus = $tempStatusCold;
                } elseif ($tempDiff > 1) {
                    $tempStatus = $tempStatusWarm;
                } else {
                    $tempStatus = $tempStatusOk;
                }
                $roomHtml = '<div class="room-tile" id="room_' . $room['id'] . '">'
                    . '<div class="room-header">' . $room['name'] . $tempStatus . '</div>'
                    . '<div class="room-values">'
                    . '<div><strong>Ist:</strong><span id="room_' . $room['id'] . '_temperature" class="value-cell">' . (number_format(floatval($room['temperature']), 2, ',', '') ?? 'n/a') . ' °C</span></div>';

                $showNormal = " visible";
                $showTemp = " hidden";
                if (!empty($room['remaining_time']) && $room['remaining_time'] > 0) {
                    $showNormal = " hidden";
                    $showTemp = " visible";
                    $roomHtml .= '<div><strong>Soll:</strong><span id="room_' . $room['id'] . '_target" class="value-cell ' . $showTemp . '"><s>' . (number_format(floatval($room['perm_solltemperatur']), 2, ',', '') ?? 'n/a') . ' °C</s></span></div>';
                } else {
                    $roomHtml .= '<div><strong>Soll:</strong><span id="room_' . $room['id'] . '_target" class="value-cell ' . $showNormal . '">' . (number_format(floatval($room['target']), 2, ',', '') ?? 'n/a') . ' °C</span></div>';
                }

                $roomHtml .= '<div class="room-temp-schedule' . $showTemp . '" id="room_' . $room['id'] . '_target_temp_block">'
                            . '<div><strong>Temporär-Soll:</strong><span id="room_' . $room['id'] . '_target_temp" class="value-cell">' . (number_format(floatval($room['target']), 2, ',', '') ?? 'n/a') . ' °C</span></div>'
                            . '<div><strong>Restzeit:</strong><span id="room_' . $room['id'] . '_target_temp_time" class="value-cell">' . $hoursMinutes . ' h</span></div>'
                            . '</div>';

                if ($this->ReadPropertyBoolean('ShowRoomData'))
                {
                    $roomHtml .= '<div><strong>Luftfeuchte:</strong><span id="room_' . $room['id'] . '_humidity" class="value-cell">' . (number_format($room['humidity'], 2, ',', '') ?? 'n/a') . '%</span></div>'
                                . '<div><strong>Status:</strong><span id="room_' . $room['id'] . '_state" class="value-cell">' . ($room['state'] ?? 'n/a') . '</span></div>';
                }
                $roomHasNote = (isset($room['note']) && (strlen($room['note']) > 0));
                $roomHtml .= '<div class="room-footer" id="room_' . $room['id'] . '_note" class="' . ($roomHasNote ? 'visible' : 'hidden') . '">' . ($roomHasNote ? $room['note'] : '') . '</div>';
                $roomHtml .= '</div>';
                // *************** O F F S E T S *******************
                if ($this->ReadPropertyBoolean('ShowRoomOffsets')) {
                    if (!empty($room['offsets'])) {
                        $roomHtml .= '<hr class="room-separator" />';
                        $roomHtml .= '<div class="room-offsets">';
                        $roomHtml .= '<div class="room-section-title">Offsets</div>';
                        $roomHtml .= '<table class="room-offsets-table">';

                        // Summe berechnen
                        $sum = 0.0;
                        foreach ($room['offsets'] as $values) {
                            $sum += isset($values['raum']) ? floatval($values['raum']) : 0;
                        }

                        // Erste Zeile: Gesamt-Offset
                        $roomHtml .= '<tr class="offset-sum">'
                                . '<td>Gesamt-Offset</td>'
                                . '<td class="value-cell" id="room_' . $room['id'] . '_offset_sum"> ' . number_format($sum, 2, ',', '') . ' °C</td>'
                                . '</tr>';

                        // Doppelstrich als Trenner
                        $roomHtml .= '<tr><td colspan="2"><hr class="offset-sum-separator" /></td></tr>';

                        // Details
                        foreach ($room['offsets'] as $offsetName => $values) {
                            $raumVal = isset($values['raum']) ? floatval($values['raum']) : 0;
                            if (!$this->ReadPropertyBoolean("ShowRoomOffsetsOnlyActive") || ($this->ReadPropertyBoolean("ShowRoomOffsetsOnlyActive") && $raumVal > 0)){
                                $roomHtml .= '<tr>'
                                        . '<td id="room_' . $room['id'] . '_offset_' . $offsetName . '_name">' . htmlspecialchars($offsetName) . '</td>'
                                        . '<td id="room_' . $room['id'] . '_offset_' . $offsetName . '_value" class="value-cell">' . number_format($raumVal, 2, ',', '') . ' °C</td>'
                                        . '</tr>';
                            }
                        }

                        $roomHtml .= '</table>';
                        $roomHtml .= '</div>';
                    }
                }
                // *************** S E N S O R S *******************
                if ($this->ReadPropertyBoolean('ShowRoomSensors')) {
                    $hasPrimary = !empty($room['primary_sensor_name']);
                    $otherSensors = array_filter($room['sensors'], function($s) use ($room) {
                        return $s['name'] !== $room['primary_sensor_name'];
                    });

                    // Primary Sensor zuerst (ohne Überschrift)
                    if ($hasPrimary) {
                        $roomHtml .= '<hr class="room-separator" />';
                        $roomHtml .= '<div class="room-primary-sensor">';
                        $roomHtml .= '<div class="room-section-title">Thermostat</div>';
                        $roomHtml .= '<table class="room-sensor-table">';
                        $roomHtml .= '<tr>'
                                . '<td>' . htmlspecialchars($room['primary_sensor_name']) . '</td>'
                                . '<td id="room_' . $room['id'] . '_primarysensor_' . $offsetName . '_value" class="value-cell">' . ((isset($room['primary_sensor_value']) && is_numeric($room['primary_sensor_value']) && ($room['primary_sensor_value'] > 0)) ? number_format(floatval($room['primary_sensor_value']), 2, ',', '') . ' °C' : 'n/a') . '</td>'
                                . '<td id="room_' . $room['id'] . '_primarysensor_' . $offsetName . '_last_info" class="value-cell">' . ((isset($room['primary_sensor_last_info']) && (strtotime($room['primary_sensor_last_info']))) ? date('d.m. H:i', strtotime($room['primary_sensor_last_info'])) : 'n/a') . '</td>'
                                . '</tr>';
                        $roomHtml .= '</table>';
                        $roomHtml .= '</div>';
                    }

                    // Rücklaufsensoren, falls vorhanden
                    if (!empty($otherSensors)) {
                        $roomHtml .= '<hr class="room-separator" />';
                        $roomHtml .= '<div class="room-sensors">';
                        $roomHtml .= '<div class="room-section-title">Rücklaufsensoren</div>';
                        $roomHtml .= '<table class="room-sensor-table">';
                        foreach ($otherSensors as $sensor) {
                            if (!$sensor['raumtemperatursensor']) {
                                $roomHtml .= '<tr>'
                                        . '<td>' . htmlspecialchars($sensor['beschreibung'] ?? $sensor['name']) . '</td>'
                                        . '<td id="room_' . $room['id'] . '_sensor_' . $sensor['name'] . '_value" class="value-cell">' . (isset($sensor['wert']) && is_numeric($sensor['wert']) ? number_format(floatval($sensor['wert']), 2, ',', '') . ' °C' : 'n/a') . '</td>'
                                        . '<td id="room_' . $room['id'] . '_sensor_' . $sensor['name'] . '_last_info" class="value-cell">' . (isset($sensor['letzte_uebertragung']) ? date('d.m. H:i', strtotime($sensor['letzte_uebertragung'])) : 'n/a') . '</td>'
                                        . '</tr>';
                            }
                        }
                        $roomHtml .= '</table>';
                        $roomHtml .= '</div>';
                    }
                }

                $roomHtml .= '</div>'; // room-tile
                $floorGroups[$floor]['roomsHtml'] .= $roomHtml; // dem entsprechenden floor hinzufügen.
            }
        }
        // *************** B U I L D   R O O M   H T M L *******************
        $roomTilesHtml = '<div class="tile-container" id="floors-container">';
        foreach ($floorGroups as $floor => $oneFloor) {
            $roomTilesHtml .= '<div class="floor-tile" id="floor-' . $oneFloor['floor']['id'] . '">'
                . '<label>' . htmlspecialchars($oneFloor['floor']['name']) . '</label>'
                . '<div class="rooms-container">'
                    . $oneFloor['roomsHtml']
                . '</div>'
            . '</div>';
        }
        $roomTilesHtml .= '</div>';
        // ========================================================================================================================
        // 5. Dropdown für Dauer in Stunden (0–24)
        $durationOptions = '';
        for ($h = 1; $h <= 168; $h++) {
            if ($h >= 12 && $h < 72) $h += 12; // ab 12h in 12h-Schritten
            if ($h >= 72) $h += 24; // ab 72h in 24h-Schritten
            $durationOptions .= '<option value="' . $h . '">' . $h . ' h (= ' . number_format(($h / 24), 3, '.', '') . ' Tage)</option>';
        }
        // ========================================================================================================================
        // 6. HTML Template laden & Platzhalter ersetzen
        $html = file_get_contents(__DIR__ . '/module.html');

        // Farbinformationen einfügen
        $html = str_replace('<!--COLOR_MAIN_TILES-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorMainTiles")), 6, '0', STR_PAD_LEFT), $html);
        $html = str_replace('<!--COLOR_ROOM_TILES-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorRoomTiles")), 6, '0', STR_PAD_LEFT), $html);
        $html = str_replace('<!--COLOR_SYSTEM_INFO-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorSystemInfoTile")), 6, '0', STR_PAD_LEFT), $html);
        $html = str_replace('<!--COLOR_FLOOR_TILES-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorFloorTiles")), 6, '0', STR_PAD_LEFT), $html);
        $html = str_replace('<!--COLOR_TEXT-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorText")), 6, '0', STR_PAD_LEFT), $html);
        $html = str_replace('<!--COLOR_BUTTON_MODE-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorModeButton")), 6, '0', STR_PAD_LEFT), $html);
        $html = str_replace('<!--COLOR_BUTTON_TEMP-->', "#" . str_pad(dechex($this->ReadPropertyInteger("VisuColorTempButtons")), 6, '0', STR_PAD_LEFT), $html);

        // Optionsauswahlfelder / Wertvorgaben einfügen
        $html = str_replace('<!--MODE_OPTIONS-->', $modeOptions, $html);
        $html = str_replace('<!--FLOOR_ROOM_OPTIONS-->', $roomOptions, $html);
        $html = str_replace('<!--DURATION_OPTIONS-->', $durationOptions, $html);
        $html = str_replace('<!--MAX_TEMP-->', number_format($maxTemp, 2, '.', ''), $html);

        // Informationen einfügen
        if ($this->ReadPropertyBoolean("ShowRooms")) {
            $html = str_replace('<!--ROOM_TILES-->', $roomTilesHtml, $html);
            $this->SendDebug(__FUNCTION__, "HTML: " . $roomTilesHtml, 0);
        }
        if ($this->ReadPropertyBoolean("ShowSystemInfo")) {
            $html = str_replace('<!--SYSTEM_INFO-->', $sysHtml, $html);
        }

        $this->SendDebug(__FUNCTION__, "HTML: " . $html, 0);
        return $html;
    }

    private function getRoomData(): array
    {
        $rooms = [];
        $floorID = 1;

        while (@IPS_GetObjectIDByIdent("Floor{$floorID}ID", $this->InstanceID) !== false) {
            $roomID = 1;
            while (@IPS_GetObjectIDByIdent("Floor{$floorID}Room{$roomID}ID", $this->InstanceID) !== false) {
                $floorVar = "Floor{$floorID}";
                $roomVar = "Floor{$floorID}Room{$roomID}";

                $roomData = [
                    'id'   => $this->GetValue($roomVar . "ID"),
                    'name' => $this->GetValue($roomVar . "Name"),
                    'floorid'   => $this->GetValue($floorVar . "ID"),
                    'floorname' => $this->GetValue($floorVar . "Name"),
                    'target'     => $this->GetValue($roomVar . "Target"),
                    'remaining_time' => $this->GetValue($roomVar . "RemainingTime") ?? 0,
                    'perm_solltemperatur' => $this->GetValue($roomVar . "PermSolltemperatur") ?? 0,
                    'temperature' => $this->GetValue($roomVar . "Temperature"),
                    'note'        => $this->GetValue($roomVar . "Note") ?? ''
                ];

                // Nur wenn ShowRoomData = true → Luftfeuchte und Betriebsmode
                if ($this->ReadPropertyBoolean('ShowRoomData')) {
                    $roomData['state']       = $this->GetValue($roomVar . "State");
                    $roomData['humidity']    = $this->GetValue($roomVar . "Humidity");
                }

                // Nur wenn ShowRoomOffsets = true
                if ($this->ReadPropertyBoolean('ShowRoomOffsets')) {
                    $roomData['offset_total']        = $this->GetValue($roomVar . "OffsetTotal");
                    $roomData['offsets']             = json_decode($this->GetValue($roomVar . "OffsetsJSON") ?? '[]', true);
                    $roomData['offset_active_count'] = $this->GetValue($roomVar . "OffsetActiveCount");
                }

                // Nur wenn ShowRoomSensors = true
                if ($this->ReadPropertyBoolean('ShowRoomSensors')) {
                    $roomData['sensors']               = json_decode($this->GetValue($roomVar . "SensorsJSON") ?? '[]', true);
                    $roomData['sensor_count']          = $this->GetValue($roomVar . "SensorCount");
                    $roomData['primary_sensor_name']   = $this->GetValue($roomVar . "PrimarySensorName");
                    $roomData['primary_sensor_value']  = $this->GetValue($roomVar . "PrimarySensorValue");
                    $roomData['primary_sensor_last_info'] = $this->GetValue($roomVar . "PrimarySensorLastInfo");
                }

                $rooms[] = $roomData;
                $roomID++;
            }
            $floorID++;
        }
        return $rooms;
    }

    private function getSystemInfo(): array
    {
        return [
            'Hardware' => $this->GetValue('SysInfo_HW'),
            'Software Datum' => $this->GetValue('SysInfo_SWDate'),
            'Branch' => $this->GetValue('SysInfo_Branch'),
            'OS' => $this->GetValue('SysInfo_OS'),
            'Filesystem Build' => $this->GetValue('SysInfo_FBI'),
            'App kompatibel' => $this->GetValue('SysInfo_AppCompat') ? 'Yes' : 'No'
        ];
    }

    private function updateIPAddress(): string
    {
        // Link zum Controme Gateway anpassen
        $response = $this->RequestGatewayIPAddress();
        if ($this->isError($response)) {
            $this->UpdateFormField("ContromeIP", "caption", "should be: ip-address-of-your-controme-gateway/raumregelung-pro/");
            return $this->wrapReturn(false, "No valid IP from gateway.");
        }
        $ip = $this->getResponsePayload($response);

        // Prüfen, ob es eine gültige IP-Adresse ist
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->UpdateFormField("ContromeIP", "caption", "Invalid IP received from gateway!");
            return $this->wrapReturn(false, "Invalid IP delivered: " . $ip);
        }

        $this->UpdateFormField("ContromeIP", "caption", $ip . "/raumregelung-pro/");
        return $this->wrapReturn(true, "Valid IP delivered: " . $ip, $ip);
    }

    private function setRoomTemperatureTemp(mixed $params): string
    {
        // Absicherung: immer Array
        if (!is_array($params)) {
            $params = json_decode($params, true);;
        }
        // Pflicht-Parameter prüfen
        if (!isset($params['RoomIDs'], $params['Target'], $params['Duration']))
        {
            $payloadToVisu = [
                'msg' => "Error setting the mode: missing room id, temporary temperature or duration.",
                'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
            ];
            return $this->wrapReturn(false, 'Missing parameters in SetRoomTemperatureTemp', $payloadToVisu);
        }

        // Raumliste erstellen
        $roomIds = $params['RoomIDs'];
        if (!is_array($roomIds)) {
            $roomIds = [$roomIds]; // falls aus Versehen nur eine Zahl übergeben wurde
        }
        $target = (float) $params['Target'];
        $duration = (int) $params['Duration'];
        if (($target < 15 || $target > 25)) {
            $payloadToVisu = [
                'msg' => "Error setting the mode: invalid target temperature (15 - 25 °C).",
                'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
            ];
            return $this->wrapReturn(false, "Invalid target temperature: $target", $payloadToVisu);
        }
        if ($duration < 60) {
            $payloadToVisu = [
                'msg' => "Error setting the mode: invalid duration (min. 60 min).",
                'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
            ];
            return $this->wrapReturn(false, "Invalid duration: $duration");
        }
        // Raumliste durchgehen und schreiben.
        foreach ($roomIds as $roomId) {
            $this->SendDebug(__FUNCTION__, "Setze Temperatur $target °C für Raum-ID $roomId", 0);
            $response = $this->SendDataToParent(json_encode([
                'DataID'    => GUIDs::DATAFLOW,
                'Action'    => ACTIONS::SET_SETPOINT_TEMP,
                'RoomID'    => $roomId,
                'Target'    => $target,
                'Duration'  => $duration
            ]));
            if ($this->isError($response))
            {
                $payloadToVisu = [
                    'msg' => "Error setting the temporary setpoint for room id " . $roomId . " with temperature " . $target . ".",
                    'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
                ];
                return $this->wrapReturn(false, "Temporary setpoint not set for room " . $roomId . " and temperature " . $target . ".", $payloadToVisu);
            }
        }
        $payloadToVisu = [
            'msg' => "Temporary setpoint set for room id's " . implode(", ", $roomIds) . " with temperature " . $target . " for " . $duration . " minutes.",
            'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")  // Anzeigedauer
        ];
        $this->updateData();
        return $this->wrapReturn(true, "Target setpoint set successfully.", $payloadToVisu);
    }

    public function setRoomTemperature(mixed $params): string
    {
        // Absicherung: immer Array
        if (!is_array($params)) {
            $params = json_decode($params, true);
        }
        // Pflicht-Parameter prüfen
        if (!isset($params['RoomIDs'], $params['Target'])) {
            $payloadToVisu = [
                'msg' => "Error setting the temperature: missing room id or temperature value.",
                'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
            ];
            return $this->wrapReturn(false, 'Missing parameters in SetRoomTemperature', $payloadToVisu);
        }
        // Raumliste erstellen
        $roomIds = $params['RoomIDs'];
        if (!is_array($roomIds)) {
            $roomIds = [$roomIds]; // falls aus Versehen nur eine Zahl übergeben wurde
        }
        $target  = floatval($params['Target']);
        if ($target < 15 || $target > 25) {
            $payloadToVisu = [
                'msg' => "Error setting the temperature: invalid temperature value (15 - 25 °C).",
                'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
            ];
            return $this->wrapReturn(false, "Invalid target temperature: $target", $payloadToVisu);
        }
        foreach ($roomIds as $roomId) {
            $this->SendDebug(__FUNCTION__, "Setze permanente Temperatur für Raum $roomId auf $target °C", 0);

            $response = $this->SendDataToParent(json_encode([
                'DataID' => GUIDs::DATAFLOW, // ersetzen durch deine echte GUID
                'Action' => ACTIONS::SET_SETPOINT,
                'RoomID' => $roomId,
                'Setpoint' => $target
            ]));
            if ($this->isError($response))
            {
                $payloadToVisu = [
                    'msg' => "Error setting the setpoint for room id " . $roomId . " with temperature " . $target . ".",
                    'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
                ];
                return $this->wrapReturn(false, "Target setpoint not set for room " . $roomId . " and temperature " . $target . ".", $payloadToVisu);
            }
        }
        $payloadToVisu = [
            'msg' => "Setpoint set for room id's " . implode(", ", $roomIds) . " with temperature " . $target . ".",
            'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")  // Anzeigedauer
        ];
        $this->updateData();
        return $this->wrapReturn(true, "Permanent setpoint set successfully.", $payloadToVisu);
    }

    private function setRoomMode(mixed $params): string
    {
        // Absicherung: immer Array
        if (!is_array($params)) {
            $params = json_decode($params, true);
        }
        // Pflicht-Parameter prüfen
        if (!isset($params['RoomIDs'], $params['ModeID'])) {
            $payloadToVisu = [
                'msg' => "Error setting the mode: missing room id or mode id.",
                'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
            ];
            return $this->wrapReturn(false, 'Error: missing parameters in SetRoomMode: ' . print_r($params, true), $payloadToVisu);
        }
        // Raumliste erstellen
        $roomIds = $params['RoomIDs'];
        if (!is_array($roomIds)) {
            $roomIds = [$roomIds]; // falls nur eine Zahl übergeben wurde
        }
        $mode  = intval($params['ModeID']);
        if ($mode < 0 || $mode > 3) {
            $payloadToVisu = [
                'msg' => "Error setting the mode: invalid mode id.",
                'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
            ];
            return $this->wrapReturn(false, "Invalid target mode: $mode", $payloadToVisu);
        }
        foreach ($roomIds as $roomId) {
            $this->SendDebug(__FUNCTION__, "Setze Betriebsmodus für Raum $roomId auf Betriebs-ID $mode.", 0);

            $response = $this->SendDataToParent(json_encode([
                'DataID' => GUIDs::DATAFLOW, // ersetzen durch deine echte GUID
                'Action' => ACTIONS::SET_MODE,
                'RoomID' => $roomId,
                'ModeID' => $mode
            ]));
            if ($this->isError($response)){
                $payloadToVisu = [
                    'msg' => "Error setting the mode for room id " . $roomId . " with mode " . $mode . ".",
                    'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")
                ];
                return $this->wrapReturn(false, "Target mode not set for room " . $roomId . " and mode " . $mode . ".", $payloadToVisu);
            }
        }
        $payloadToVisu = [
            'msg' => "Mode set for room id's " . implode(", ", $roomIds) . " with mode " . $mode . ".",
            'duration' => $this->ReadPropertyInteger("DurationOfMessagePopup")  // Anzeigedauer
        ];
        $this->updateData();
        return $this->wrapReturn(true, "Mode set successfully.", $payloadToVisu);
    }

    private function sendVisuAction(string $action, array $payload)
    {
        $payload = array_merge([
            'action'   => $action,
            'payload'  => $payload
        ]);
        $this->SendDebug(__FUNCTION__, "Sending updates to visu. Data: " . print_r($payload, true));
        $json = json_encode($payload);
        $this->UpdateVisualizationValue($json);
    }
}
