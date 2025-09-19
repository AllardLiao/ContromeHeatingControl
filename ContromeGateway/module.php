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
use Controme\CONTROME_API;
use Controme\CONTROME_PROFILES;

class ContromeGateway extends IPSModuleStrict
{
    use DebugHelper;
    use EventHelper;
    use ProfileHelper;
    use VariableHelper;
    use VersionHelper;
    use FormatHelper;
    use WidgetHelper;
    use ReturnWrapper;

    private string $JSON_GET = "http://127.0.0.1/get/json/v1/1/"; // Controme unterstützt (momentan) kein HTTPS -> https://support.controme.com/api/
    private string $JSON_SET = "http://127.0.0.1/set/json/v1/1/";

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Konfigurationselemente
        $this->RegisterPropertyString("IPAddress", "127.0.0.1");
        $this->RegisterPropertyString("User", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyInteger("HouseID", 1);
        $this->RegisterPropertyBoolean("UseHTTPS", false);
        $this->RegisterPropertyString("Rooms", "[]"); // gem. Controme-API: get-rooms
        $this->RegisterPropertyInteger("Mode", 0);
        $this->RegisterPropertyInteger("TargetCategory", 0); // Zielkategorie für neue Instanzen
    }

    public function Destroy(): void
    {
        // Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Falls irgendwas nicht klappt, Instanz auf inaktiv setzen. Genauere Status werden ggf. im Laufe des ApplyChanges gesetzt.
        $this->SetStatus(IS_INACTIVE);

        // Variablenprofile/Presentationtemplates sicherstellen
        CONTROME_PROFILES::registerAllContromeProfilesAndTemplates();

        $ip = $this->ReadPropertyString("IPAddress");
        if ($ip == "") {
            $this->LogMessage("Missing IP address.", KL_ERROR);
            $this->SetStatus(IS_NO_CONNECTION);
            return;
        }

        $result = $this->PingGateway($ip);
        $msg = $ip . " not reachable. Please check.";
        if (!$this->isSuccess($result, KL_ERROR, $msg)) {
            $this->SetStatus(IS_NO_CONNECTION);
            return;
        }

        // JSON url anpassen
        $this->setJsonGet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));
        $this->setJsonSet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));

        // Alles hat geklappt - Instanze aktiv
        $this->SetStatus(IS_ACTIVE);
    }

    /**
     * Is called when, for example, a button is clicked in the visualization.
     *
     *  @param string $ident Ident of the variable
     *  @param string $value The value to be set
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        // JSON url anpassen
        $this->setJsonGet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));
        $this->setJsonSet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));

        switch($ident) {
            case ACTIONs::FETCH_ROOM_LIST:
                $this->SetRoomList(); // Räume abrufen und im Konfig-Form speichern
                break;
            case ACTIONs::CHECK_CONNECTION:
                $this->CheckConnection($value);
                break;
            case ACTIONs::WRITE_SETPOINT:
                $this->WriteSetpoint($value);
                break;
            case ACTIONs::CREATE_CENTRAL_CONTROL_INSTANCE:
                $this->CreateCentralControlInstance();
                break;
            case ACTIONs::CREATE_ROOM_THERMOSTAT_INSTANCE:
                $this->CreateRoomThermostatInstance($value);
                break;
            default:
                parent::RequestAction($ident, $value);
        }
    }

    //
    public function ForwardData($JSONString): string
    {
        // Auswertung für Aufrufe von Child-Instanzen
        $data = json_decode($JSONString, true);

        if (!is_array($data) || ($data['DataID'] ?? '') !== GUIDs::DATAFLOW) {
            return $this->wrapReturn(false, "Non-valid payload.");
        }

        // JSON url anpassen (Sicherheitshalber...)
        $this->setJsonGet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));
        $this->setJsonSet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));

        switch ($data['Action']) {
            case ACTIONs::GET_IP_ADDRESS:
                return $this->ReadPropertyString("IPAddress");

            case ACTIONs::CHECK_CONNECTION:
                $result = $this->CheckConnection($data);
                if ($this->isSuccess($result, KL_ERROR, "Connection to Controme Mini-Server."))
                {
                    return $this->wrapReturn(true, "Connection established.");
                }
                else {
                    return $this->wrapReturn(false, "Connection to Controme Mini-Server failed. " . $this->getResponseMessage($result));
                }

            case ACTIONs::GET_TEMP_DATA_FOR_ROOM: // liefert ein JSON mit den Räumen zurück.
                if (!isset($data['RoomID'])) {
                    return $this->wrapReturn(false, "Missing room id");
                }
                $roomId  = (int)$data['RoomID'];
                return $this->GetTempDataForRoom($roomId);

            case ACTIONs::GET_DATA_FOR_CENTRAL_CONTROL:
                // Wird von Child-Instanzen genutzt, aufruf ohne Parameter.
                // Hier werden mehrere API-Aufrufe durchgeführt. Wenn einzelne davon fehlerhaft sind, enthält das zurückgegebene Array in jeweiligen Teil die Fehlermeldung.
                $result = [];
                if (!empty($data[ACTIONs::DATA_SYSTEM_INFO])) {
                    $sysInfo = json_decode($this->fetchSystemInfo()); // Für den Fall, dass die SysInfo nicht gelesen werden können, wird das "Fehler"-JSON mit zurückgegeben.
                    $this->SendDebug(__FUNCTION__, "fetchSystemInfo returned: " . print_r($sysInfo, true), 0);
                    $result[ACTIONs::DATA_SYSTEM_INFO] = $sysInfo;
                }
                if (!empty($data[ACTIONs::DATA_ROOMS]) || !empty($data[ACTIONs::DATA_TEMPERATURS])) {
                    $rooms = json_decode($this->fetchRooms()); // Für den Fall, dass die Räume nicht gelesen werden können, wird das "Fehler"-JSON mit zurückgegeben.
                    $this->SendDebug(__FUNCTION__, "fetchRooms returned: " . print_r($rooms, true), 0);
                    $result[ACTIONs::DATA_ROOMS] = $rooms;
                    if (!empty($data[ACTIONs::DATA_TEMPERATURS])) {
                        $result[ACTIONs::DATA_TEMPERATURS] = true; //Merker: er nutzt den gleichen Payload von rooms.
                    }
                }
                $this->SendDebug(__FUNCTION__, "Returning result: " . print_r($result, true), 0);
                return json_encode($result);
                break;

            case ACTIONs::WRITE_SETPOINT:
                return $this->WriteSetpoint($data);

            default:
                $msg = "Unknown action: " . $data['Action'];
                return $this->wrapReturn(false, $msg);
        }
    }

    /**
     * Check connection status to Controme Mini-Server.
     * Executes a reading and a writing attempt via Controme API.
     * In case room id is provided with a number below 1, room id 1 is used.
     *
     *  @param mixed    $value      Expected is an array which includes assosciation 'RoomID' => X, x = the room id within Controme.
     */
    public function CheckConnection(mixed $value): string
    {
        $ip   = $this->ReadPropertyString("IPAddress");
        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        // 1. Test: Daten da?
        $result = $this->checkConnectionPrerequisites();
        $msg = "Missing connection information.";
        if (!$this->isSuccess($result, KL_ERROR, $msg))
        {
            $this->SetStatus(IS_INACTIVE);
            return $this->wrapReturn(false, $msg);
        } else {
            $this->SendDebug(__FUNCTION__, "Check 1 - all information given. ($ip, $user, $pass)", 0);
        }

        // 2. Test: Verbindung zum Gateway erreichbar?
        // Dafür nutzen wir schon die Datenabruf-Funktion, denn wir brauchen den Soll-Wert von Raum 1 um das Schreiben mit Passwort zu testen.
        $roomId = $value['RoomID'] ?? 1; //Raum 1 sollte es immer geben - wird genutzt, wenn der Testaufruf direkt im Gateway durhgeführt wird.
        if ($roomId <= 0) {
            $roomId = 1;
        }
        $currentData = $this->GetTempDataForRoom($roomId);

        // Sollte eigentlich klappen - Controme prüft beim get nicht das Passwort. Wenn es nicht klappt kann es fast nur die IP sein.
        if ($this->isError($currentData))
        {
            $msg = "No connection to Controme Mini-Server, please check IP: " . $ip;
            $this->UpdateFormField("Result", "caption", $msg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $this->getResponseMessage($currentData));
        }

        $roomData = json_decode($currentData, true);
        $roomName = $roomData['name'] ?? 'unknown';
        $roomTemp = $roomData['temperatur'] ?? 'unknown';
        $this->SendDebug(__FUNCTION__, "Check 2 - connection to Controme MiniServer at $ip established. ($roomName, $roomTemp °C)", 0);

        // 3. Test: Wird das Passwort akzeptiert? Dazu schreiben wir die eben ausgelesene Solltemperatur zurück.
        $sendData = Array('RoomID' => $roomId, 'Setpoint' => $roomTemp);
        $result = $this->WriteSetpoint($sendData);

        if ($this->isSuccess($result, KL_ERROR, "Connection for user " . $user . "."))
        {
            $msg = "Success - connection established for user " . $user;
            $this->UpdateFormField("Result", "caption", $msg);
            $this->SetStatus(IS_ACTIVE);
            return $this->wrapReturn(true, $msg);
        } else {
            $msg = "Failed - could not establish connection for user " . $user . " (" . $this->getResponseMessage($result) . ")";
            $this->UpdateFormField("Result", "caption", $msg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $msg);
        }
    }

    /**
     * Holt die Rohdaten der Räume vom Controme-API.
     * Rückgabe:
     *  - array (decoded JSON) bei Erfolg
     *  - false bei Fehler
     *
     */
    public function FetchRooms(): string
    {
        $result = $this->checkConnectionPrerequisites();
        $msg = "Missing connection information.";
        if (!$this->isSuccess($result, KL_ERROR, $msg))
        {
            $this->SetStatus(IS_INACTIVE);
            return $this->wrapReturn(false, $msg);
        }

        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        $url = $this->getJsonGet() . CONTROME_API::GET_TEMPERATURS;
        $opts = [
            "http" => [
                "method"        => "GET",
                "header"        => "Authorization: Basic " . base64_encode("$user:$pass"),
                "timeout"       => 5,
                "ignore_errors" => true // damit wir Header/Body auch bei 4xx/5xx lesen können
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);

        if ($json === false) {
            $msg = "HTTP request failed and no response header available.";
            if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
                $msg = $this->CheckHttpReponseHeader($http_response_header);
            }
            $msg = "Error calling {$url}: {$msg}";
            $this->UpdateFormField("StatusInstances", "caption", "Failed to read data.");
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $msg);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $msg = "Failed to decode data (invalid JSON).";
            $this->UpdateFormField("StatusInstances", "caption", $msg);
            $this->SetStatus(IS_BAD_JSON);
            return $this->wrapReturn(false, $msg);
        }

        // Alles gut — zurückgeben (Rohdaten, Struktur wie API liefert)
        return json_encode($data);
    }

    /**
     * Build the form list and update the module/form UI.
     * Liefert true bei Erfolg, false bei Fehler.
     */
    public function SetRoomList(): string
    {
        $data = $this->FetchRooms();

        if ($this->isError($data)) {
            $msg = "No data received from Controme API.";
            $this->UpdateFormField("StatusInstances", "caption", $msg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $msg);
        }
        else {
            $data = json_decode($data, true);
        }

        $formListJson = [];
        foreach ($data as $etage) {
            if (!isset($etage['raeume']) || !is_array($etage['raeume'])) continue;

            foreach ($etage['raeume'] as $raum) {
                $formListJson[] = [
                    "FloorID"           => $etage['id'] ?? 0,
                    "Floor"             => $etage['etagenname'] ?? "Haus",
                    "RoomID"            => $raum['id'] ?? 0,
                    "Room"              => $raum['name'] ?? "Raum"
                ];
            }
        }

        // Formular aktualisieren
        $msg = "Room list updated.";
        $this->UpdateFormField("Rooms", "values", json_encode($formListJson));
        $this->UpdateFormField("StatusInstances", "caption", $msg);
        $this->UpdateFormField("ExpansionPanelRooms", "expanded", "true");
        $this->UpdateFormField("ButtonCreateCentralInstance", "enabled", "true");
        $this->UpdateFormField("ButtonCreateRoomInstance", "enabled", "true");
        $this->SendDebug(__FUNCTION__, "Updated Controme Heating room data.", 0);
        $this->SetStatus(IS_ACTIVE);
        return $this->wrapReturn(true, $msg);
    }

    public function fetchSystemInfo(): string
    {
        $result = $this->checkConnectionPrerequisites();
        $msg = "Can not fetch data. Missing connection information.";
        if (!$this->isSuccess($result, KL_ERROR, $msg))
        {
            return $this->wrapReturn(false, $msg);
        }

        // URL zusammenbauen über Helper
        $url = $this->getJsonGet() . CONTROME_API::GET_SYSTEM_INFO;
        $this->SendDebug(__FUNCTION__, "Requesting URL for SysInfo: " . $url, 0);

        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        $opts = [
            'http' => [
                'method'  => "GET",
                "header" => "Authorization: Basic " . base64_encode("$user:$pass"),
                "timeout" => 5,
                "ignore_errors" => true
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);

        if ($json === false) {
            $error = error_get_last();
            $msg   = "Request failed for " . "$url: " . $error['message'] ?? "Unknown error";
            $this->UpdateFormField("StatusInstances", "caption", $msg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $msg);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $msg = "Invalid/unexpected response from Controme API (non JSON provided).";
            $this->UpdateFormField("StatusInstances", "caption", $msg);
            $this->SetStatus(IS_BAD_JSON);
            return $this->wrapReturn(false, $msg);
        }
        else {
            $this->SendDebug(__FUNCTION__, "Systeminfo received: " . print_r($data, true), 0);
            $this->SetStatus(IS_ACTIVE);
        }

        return json_encode($data);
    }

    private function checkConnectionPrerequisites()
    {
        $ip      = $this->ReadPropertyString("IPAddress");
        $user    = $this->ReadPropertyString("User");
        $pass    = $this->ReadPropertyString("Password");
        $houseId = $this->ReadPropertyInteger("HouseID");

        if (empty($ip) || empty($user) || empty($pass) || empty($houseId)) {
            $msg = "Conection check not possible: IP, User, Password or House-ID missing!";
            $this->UpdateFormField("StatusInstances", "caption", $msg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $msg);
        }

        return $this->wrapReturn(true, "All params existing.");
    }

    /**
     * Reads the temperature via Controme API for one room
     *
     * Returns a JSON wrapped data
     *
     * @param int    $roomID          Room id from Controme system
     */
    public function GetTempDataForRoom(int $roomId): string
    {
        $result = $this->checkConnectionPrerequisites();
        if (!$this->isSuccess($result))
        {
            return $this->wrapReturn(false, "Can not fetch data. Missing connection information.");
        }

        $user    = $this->ReadPropertyString("User");
        $pass    = $this->ReadPropertyString("Password");

        $url = $this->getJsonGet() . CONTROME_API::GET_TEMPERATURS . "$roomId/";
        $this->SendDebug(__FUNCTION__, "Requesting room/temp data: " . $url, 0);
        // Authentication hinzufügen

        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "Authorization: Basic " . base64_encode("$user:$pass"),
                "timeout" => 5,
                "ignore_errors" => true
            ]
        ];
        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $msg = "HTTP request failed and no response header available.";
            if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
                $msg = $this->CheckHttpReponseHeader($http_response_header);
            }
            $msg = "Failed to fetch room data from $url\n" . $msg;
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $msg);
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            $msg = "Invalid JSON response from " . $url;
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $msg);
        }

        // Wenn ein Array mit Objekten kommt, das nur das erste nehmen (Controme API liefert momentan ein Array mit einem Array drin. Sollte sich das Verhalten in Zukunft ändern, sicherheitshalber dies ergänzt...)
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        //Alle ist ok.
        $this->SendDebug(__FUNCTION__, "Room $roomId data received: " . print_r($data, true), 0);
        $this->SetStatus(IS_ACTIVE);
        return (json_encode($data));
    }

    /**
     * Writes the set point temperature to Controme
     *
     * Returns an JSON wrapped return (see DebugHelper Trait)
     *
     * @param mixed    $data          Assoziative array with fields int 'RoomID' => X and float 'Setpoint' => xx.xx
     * @return string                 JSON wrapped response
     */
    private function WriteSetpoint(mixed $data): string
    {
        // Interne Validierung
        if (!is_array($data)) {
            return $this->wrapReturn(false, "Invalid data format - array expected");
        }

        $roomId   = isset($data['RoomID'])   ? intval($data['RoomID'])     : null;
        $setpoint = isset($data['Setpoint']) ? floatval($data['Setpoint']) : null;

        if ($roomId === null || $setpoint === null) {
            return $this->wrapReturn(false, "Missing parameter RoomID or Setpoint");
        }

        $ip   = $this->ReadPropertyString('IPAddress');
        $user = $this->ReadPropertyString('User');
        $pass = $this->ReadPropertyString('Password');

        if (empty($ip) || empty($user) || empty($pass)) {
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, "Missing Controme API credentials");
        }

        $url = $this->getJsonSet() . CONTROME_API::SET_SETPOINT . "$roomId/";

        // POST-Daten (ggf. action/value anpassen nach der Controme-API für setzen der Solltemperatur)
        $postData = http_build_query([
            'user'     => $user,
            'password' => $pass,
            'soll'     => number_format($setpoint, 2, '.', '')
        ]);

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postData,
                'timeout' => 10
            ]
        ];
        $context = stream_context_create($opts);

        $this->SendDebug(__FUNCTION__, "POST $url\nData: " . $postData . "\nContext: " . $context, 0);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
                $message = $this->CheckHttpReponseHeader($http_response_header);
            }
            else {
                $message = "HTTP request failed and no response header available.";
            }
            // Etwas stimmt nicht bei der Abfrage
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, "Request failed: " . $message);
        }

        // Versuche JSON zu decodieren — falls die API was Kulantes zurückliefert
        $json = json_decode($response, true);
        if ($json === null) {
            // Wenn kein JSON, aber die API etwas liefert, ist das i.d.R. die HTML-Rückmeldung von Controme, dass etwas nicht geklappt hat und der Techniker informiert ist
            // Wir prüfen aber, ob Controme nicht ggf. "[]" oder "{}" oder "true" zurückgeleifert hat und implizieren für diesen Fall erfolg!
            if (strlen(trim($response)) > 4){
                $this->SetStatus(IS_INACTIVE);
                return $this->wrapReturn(false, 'Long non-JSON response from Controme API. Assuming error state.', $response);
            }
            else {
                $this->SetStatus(IS_ACTIVE);
                return $this->wrapReturn(true, 'Short non-JSON response from Controme API. Assuming success.', $response);
            }
        }

        // Fallback: wenn JSON vorliegt, aber kein explicit success -> als OK werten oder genauer prüfen
        $this->SendDebug(__FUNCTION__, 'Controme-API returned: ' . print_r($json, true), 0);

        // Prüfe auf explizite Fehlermeldung in der JSON Response
        if (isset($json['error']) || isset($json['status']) && $json['status'] === 'error') {
            $errorMsg = $json['error'] ?? $json['message'] ?? 'Unknown API error';
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $errorMsg, print_r($json, true));
        }

        //Alle ist ok.
        $this->SetStatus(IS_ACTIVE);
        return $this->wrapReturn(true, 'Setpoint updated.');
    }

    private function CreateCentralControlInstance(): string
    {
        $parentId = $this->ReadPropertyInteger("TargetCategory"); // Gewählter Parent
        $instanceName = "Controme Central Control";

        if (!$parentId || !$instanceName) {
            return $this->wrapReturn(false, "Parent or name not set!");
        }

        // Neue Central Control Instanz erstellen
        $newId = IPS_CreateInstance(GUIDs::CENTRAL_CONTROL);
        IPS_SetParent($newId, $parentId);
        IPS_SetName($newId, $instanceName);
        IPS_ApplyChanges($newId);

        $msg = "Central Control created with name '$instanceName' (ID $newId)!";
        $this->UpdateFormField("CCInstanceCreationResult", "caption", $msg);
        return $this->wrapReturn(true, $msg);
    }

    private function CreateRoomThermostatInstance($roomRow): string
    {
        try {
            // Raumdaten aus der gespeicherten Liste holen
            $roomData = json_decode($roomRow, true);
            if ($roomData === null) {
                throw new Exception("Please select a room from the list to create an instance.");
            }
            $this->SendDebug(__FUNCTION__, "Create RT instance for: " . print_r($roomData, true), 0);

            $floorId = $roomData['FloorID'];
            $floorName = $roomData['Floor'];
            $roomId = $roomData['RoomID'];
            $roomName = $roomData['Room'];
            $icon = "temperature-list";

            // Zielkategorie aus Konfiguration lesen
            $targetCategoryId = $this->ReadPropertyInteger("TargetCategory");

            // Validierung: Kategorie muss ausgewählt sein
            if ($targetCategoryId < 0) {
                throw new Exception('Please select target category to create instances to.');
            }

            // Instanz erstellen
            $instanceId = $this->CreateAndConfigureRoomInstance($targetCategoryId, $floorId, $floorName, $roomId, $roomName, $icon);

            // Erfolgsmeldung
            $msg = "Room thermostat instance '$floorName-$roomName' created (ID: $instanceId)!";
            $this->UpdateFormField("InstanceCreationResult", "caption", $msg);
            return $this->wrapReturn(true, $msg, $instanceId);
        } catch (Exception $e) {
            $msg = "Error creating instance: " . $e->getMessage();
            $this->UpdateFormField("InstanceCreationResult", "caption", $msg);
            return $this->wrapReturn(false, $msg);
        }
    }

    public function EnableDisableFormButtons()
    {
        // Prüfen, ob Räume vorhanden sind
        $rooms = json_decode($this->ReadPropertyString("Rooms"), true);

        if (is_array($rooms) && count($rooms) > 0) {
            $this->UpdateFormField("ButtonCreateCentralInstance", "enabled", true);
            $this->UpdateFormField("ButtonCreateRoomInstance", "enabled", true);
        } else {
            $this->UpdateFormField("ButtonCreateCentralInstance", "enabled", false);
            $this->UpdateFormField("ButtonCreateRoomInstance", "enabled", false);
        }
    }

    private function CheckHttpReponseHeader($http_response_header): String
    {
        // Erste Zeile enthält den HTTP-Status
        $statusLine = $http_response_header[0];
        $message = $statusLine;

        // Wenn es einen genaueren Status gibt, extrahieren
        if (preg_match('{HTTP/\S+ (\d{3}) (.*)}', $statusLine, $matches)) {
            $statusCode = (int)$matches[1];
            $statusText = $matches[2];
            $message = "HTTP $statusCode $statusText";

            switch ($statusCode) {
                case 401:
                    $message .= ' - Unauthorized (check username/password)';
                    break;
                case 403:
                    $message .= ' - Forbidden (check permissions)';
                    break;
                case 404:
                    $message .= ' - Not Found (wrong URL)';
                    break;
                case 500:
                    $message .= ' - Server error / bad message';
                    break;
                // Weitere Fälle nach Bedarf
                default:
                    $message .= ' - Unhandeled error.';
            }
        }
        return $message;
    }

    private function getJsonGet(): string
    {
        return $this->JSON_GET;
    }

    private function setJsonGet(string $ip, int $houseID = 1, bool $useHTTPS = false): void
    {
        // IP prüfen (IPv4 oder Hostname)
        if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9\-\.]+$/', $ip)) {
            throw new InvalidArgumentException("Invalid IP address or hostname: $ip");
        }
        // houseID prüfen
        if ($houseID <= 0) {
            throw new InvalidArgumentException("House-ID must be greater than 0");
        }
        $this->JSON_GET = "http" . ($useHTTPS ? "s" : "") . "://$ip/get/json/v1/$houseID/";
    }

    private function getJsonSet(): string
    {
        return $this->JSON_SET;
    }

    private function setJsonSet(string $ip, int $houseID = 1, bool $useHTTPS = false): void
    {
        // IP prüfen (IPv4 oder Hostname)
        if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9\-\.]+$/', $ip)) {
            throw new InvalidArgumentException("Invalid IP address or hostname: $ip");
        }
        // houseID prüfen
        if ($houseID <= 0) {
            throw new InvalidArgumentException("House-ID must be greater than 0");
        }
        $this->JSON_SET = "http" . ($useHTTPS ? "s" : "") . "://$ip/set/json/v1/$houseID/";
    }

    protected function PingGateway(string $ip, int $timeout = 1000): string
    {
        // cURL verwenden, weil auf allen Systemen verfügbar
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://$ip");
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_errno($ch);

        curl_close($ch);

        if ($error !== 0) {
            return $this->wrapReturn(false, "Error connecting to $ip: $error");
        }

        $msg = "Response from $ip, HTTP Code: $httpCode";
        return $this->wrapReturn(true, $msg);
    }

    private function CreateAndConfigureRoomInstance(int $parentCategoryId, int $floorId, string $floorName, int $roomId, string $roomName, string $icon = "temperature-list"): int
    {
        // Neue Instanz erstellen
        $instanceId = IPS_CreateInstance(GUIDs::ROOM_THERMOSTAT);
        IPS_SetName($instanceId, "Thermostat " . $floorName . "-" . $roomName);
        IPS_SetIcon($instanceId, $icon);

        // In Kategorie verschieben
        IPS_SetParent($instanceId, $parentCategoryId);

        // Eigenschaften konfigurieren
        IPS_SetProperty($instanceId, 'FloorID', $floorId);
        IPS_SetProperty($instanceId, 'Floor', $floorName);
        IPS_SetProperty($instanceId, 'RoomID', $roomId);
        IPS_SetProperty($instanceId, 'Room', $roomName);

        // Mit diesem Gateway verbinden
        //IPS_ConnectInstance($instanceId, $this->InstanceID); Passiert automatisch ;-)

        // Konfiguration anwenden
        IPS_ApplyChanges($instanceId);

        return $instanceId;
    }

}
