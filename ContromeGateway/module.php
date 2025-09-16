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

        $this->SetStatus(IS_INACTIVE);

        // Variablenprofil für Betriebsart sicherstellen
        $profile = CONTROME_PROFILES::BETRIEBSART;
        CONTROME_PROFILES::registerProfile($profile);

        $ip = $this->ReadPropertyString("IPAddress");
        if ($ip == "") {
            $this->LogMessage("Keine IP-Adresse hinterlegt", KL_ERROR);
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        if (!$this->PingGateway($ip)) {
            $this->LogMessage("Gateway $ip nicht erreichbar", KL_ERROR);
            $this->SetStatus(IS_NO_CONNECTION);
            return;
        }

        // JSON url anpassen
        $this->setJsonGet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));
        $this->setJsonSet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));
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
            default:
                throw new Exception("Invalid ident");
        }
    }

    //
    public function ForwardData($JSONString): string
    {
        // Auswertung für Aufrufe von Child-Instanzen
        $data = json_decode($JSONString, true);

        if (!is_array($data) || ($data['DataID'] ?? '') !== GUIDs::DATAFLOW) {
            $this->SendDebug(__FUNCTION__, 'Ungültiger Payload', 0);
            return json_encode(false); // oder false je nach Pattern
        }

        // JSON url anpassen
        $this->setJsonGet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));
        $this->setJsonSet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));

        switch ($data['Action']) {
            case ACTIONs::GET_IP_ADDRESS:
                return $this->ReadPropertyString("IPAddress");

            case ACTIONs::CHECK_CONNECTION:
                $ok = $this->CheckConnection($data); // <- deine bestehende Funktion
                return json_encode([
                    "success" => $ok,
                    "message" => $ok ? "Connected" : "Connection failed"
                ]);

            case ACTIONs::GET_TEMP_DATA_FOR_ROOM:
                if (!isset($data['RoomID'])) {
                    $this->SendDebug(__FUNCTION__, "Missing room id", 0);
                    return json_encode(false);
                }

                $roomId  = (int)$data['RoomID'];
                $roomData = $this->GetTempDataForRoom($roomId);
                return json_encode($roomData);

            case ACTIONs::GET_DATA_FOR_CENTRAL_CONTROL:
                // Wird von Child-Instanzen genutzt, aufruf ohne Parameter.
                $result = [];
                if (!empty($data[ACTIONs::DATA_SYSTEM_INFO])) {
                    $sysInfo = $this->fetchSystemInfo();
                    $this->SendDebug(__FUNCTION__, "fetchSystemInfo returned: " . print_r($sysInfo, true), 0);
                    $result[ACTIONs::DATA_SYSTEM_INFO] = $sysInfo;
                }
                if (!empty($data[ACTIONs::DATA_ROOMS]) || !empty($data[ACTIONs::DATA_TEMPERATURS])) {
                    $rooms = $this->fetchRooms();
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
                return json_encode($this->WriteSetpoint($data));

            default:
                $this->SendDebug(__FUNCTION__, "Unknown action: " . $data['Action'], 0);
                return json_encode(false);

        }

        return json_encode([
            "success" => false,
            "message" => "Unknown action"
        ]);
    }

    /**
     * Is called by pressing the button "Check Connection" from the instance configuration
     *
     * @return boolean
     */
    public function CheckConnection(mixed $value): bool
    {
        $ip   = $this->ReadPropertyString("IPAddress");
        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        // 1. Test: Daten da?
        if (empty($ip) || empty($user) || empty($pass)) {
            $this->SendDebug(__FUNCTION__, "IP, User oder Passwort nicht gesetzt!", 0);
            $this->UpdateFormField("Result", "caption", "Please set all 3 parameters (username, password and device IP).");
            return false;
        } else {
            // Etwas stimmt nicht
            $this->SetStatus(IS_INACTIVE);
            $this->SendDebug(__FUNCTION__, "Check 1 - sufficient data available to try to connect.", 0);
        }

        // 2. Test: Verbindung zum Gateway erreichbar?
        // Dafür nutzen wir schon die Datenabruf-Funktion, denn wir brauchen den Soll-Wert von Raum 1 um das Schreiben mit Passwort zu testen.
        $roomId = $value['RoomID'] ?? 1;
        if ($roomId <= 0) {
            $roomId = 1;
        }
        $currentData = $this->GetTempDataForRoom($roomId); //Raum 1 sollte es immer geben.

        // Sollte eigentlich klappen - Controme prüft beim get nicht das Passwort. Wenn es nicht klappt kann es fast nur die IP sein.
        if ($currentData === false){
            $this->SendDebug(__FUNCTION__, "No connection to Controme MiniServer at $ip - please check IP!", 0);
            $this->LogMessage("No connection to Controme MiniServer at $ip - please check IP!", KL_ERROR);
            $this->UpdateFormField("Result", "caption", "No connection to Controme MiniServer at $ip - please check IP!");
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        } else {
            $this->SendDebug(__FUNCTION__, "Check 2 - connection to Controme MiniServer at $ip established. (" . $currentData["name"] . "=" . $currentData["temperatur"] . ")", 0);
        }

        // 3. Test: Wird das Passwort akzeptiert? Dazu schreiben wir die eben ausgelesene Solltemperatur zurück.
        $sendData = Array('RoomID' => $roomId, 'Setpoint' => $currentData['solltemperatur']);
        $result = $this->WriteSetpoint($sendData);

        $decoded = json_decode($result, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        $this->SendDebug(__FUNCTION__, "Decoded result: " . print_r($decoded, true), 0);
        if (isset($decoded['success']) && $decoded['success'] === true) {
            $this->SendDebug(__FUNCTION__, "Success - connection established for user " . $user . "!");
            $this->LogMessage("Success - connection established for user " . $user . "!", KL_NOTIFY);
            $this->UpdateFormField("Result", "caption", "Success - connection established for user " . $user . "!");
            $this->SetStatus(IS_ACTIVE);
            return true;
        } else {
            $this->SendDebug(__FUNCTION__, $decoded['message'] . "!", 0);
            $this->LogMessage($decoded['message'] . "!", KL_NOTIFY);
            $this->UpdateFormField("Result", "caption", $decoded['message'] . "!");
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }
    }

    /**
     * Holt die Rohdaten der Räume vom Controme-API.
     * Rückgabe:
     *  - array (decoded JSON) bei Erfolg
     *  - false bei Fehler
     */
    public function FetchRooms(): array|false
    {
        $ip   = $this->ReadPropertyString("IPAddress");
        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        if (empty($ip) || empty($user) || empty($pass)) {
            $this->SendDebug(__FUNCTION__, "IP, user or password missing!", 0);
            $this->UpdateFormField("StatusInstances", "caption", "Failed to login - missing argument.");
            $this->SetStatus(IS_INACTIVE);
            return false;
        }

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
            $message = "HTTP request failed and no response header available.";
            if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
                $message = $this->CheckHttpReponseHeader($http_response_header);
            }
            $this->SendDebug(__FUNCTION__, "Error calling {$url}: {$message}", 0);
            $this->UpdateFormField("StatusInstances", "caption", "Failed to read data.");
            $this->LogMessage("fetchRooms: Request failed for {$url} ({$message})", KL_ERROR);
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, "Error: JSON-Decode", 0);
            $this->UpdateFormField("StatusInstances", "caption", "Failed to decode data.");
            $this->LogMessage("fetchRooms: invalid JSON from {$url}", KL_ERROR);
            $this->SetStatus(IS_BAD_JSON);
            return false;
        }

        // Alles gut — zurückgeben (Rohdaten, Struktur wie API liefert)
        return $data;
    }

    /**
     * Build the form list and update the module/form UI.
     * Liefert true bei Erfolg, false bei Fehler.
     */
    public function SetRoomList(): bool
    {
        $data = $this->FetchRooms();

        if ($data === false) {
            $this->SendDebug(__FUNCTION__, "No data received from Gateway!", 0);
            // FetchRooms() hat bereits Status und FormCaption gesetzt, hier nur zusätzliche Info
            $this->UpdateFormField("StatusInstances", "caption", "Failed to read data.");
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }

        $formListJson = [];
        foreach ($data as $etage) {
            if (!isset($etage['raeume']) || !is_array($etage['raeume'])) continue;

            foreach ($etage['raeume'] as $raum) {
                $formListJson[] = [
                    "FloorID" => $etage['id'] ?? 0,
                    "Floor"   => $etage['etagenname'] ?? "Haus",
                    "RoomID"  => $raum['id'] ?? 0,
                    "Room"    => $raum['name'] ?? "Raum",
                ];
            }
        }

        $this->SendDebug(__FUNCTION__, "Updated Controme Heating Data.", 0);
        $this->UpdateFormField("Rooms", "values", json_encode($formListJson));
        $this->UpdateFormField("StatusInstances", "caption", "Room list updated.");
        $this->UpdateFormField("ExpansionPanelRooms", "expanded", "true");
        $this->UpdateFormField("ButtonCreateCentralInstance", "enabled", "true");
        $this->UpdateFormField("ButtonCreateRoomInstance", "enabled", "true");
        $this->SetStatus(IS_ACTIVE);

        return true;
    }

    public function fetchSystemInfo(): string
    {
        $ip      = $this->ReadPropertyString("IPAddress");
        $user    = $this->ReadPropertyString("User");
        $pass    = $this->ReadPropertyString("Password");
        $houseId = $this->ReadPropertyInteger("HouseID");

        if (empty($ip) || empty($user) || empty($pass) || empty($houseId)) {
            $this->SendDebug(__FUNCTION__, "IP, User, Passwort oder House-ID nicht gesetzt!", 0);
            $this->UpdateFormField("StatusInstances", "caption", "Login fehlgeschlagen – fehlende Argumente.");
            $this->SetStatus(IS_NO_CONNECTION);
            return json_encode(false);
        }

        // URL zusammenbauen über Helper
        $url = $this->getJsonGet() . CONTROME_API::GET_SYSTEM_INFO;
        $this->SendDebug(__FUNCTION__, "Requesting URL: " . $url, 0);

        $opts = [
            'http' => [
                'method'  => "GET",
                'timeout' => 5
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);

        if ($json === false) {
            $error = error_get_last();
            $msg   = $error['message'] ?? "Unknown error";
            $this->SendDebug(__FUNCTION__, "Request failed: " . $msg, 0);
            $this->LogMessage("fetchSystemInfo: Request failed for $ip ($msg)", KL_ERROR);
            $this->UpdateFormField("StatusInstances", "caption", "Fehler beim Abrufen der Systeminfo.");
            $this->SetStatus(IS_NO_CONNECTION);
            return json_encode(false);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, "Invalid JSON: " . $json, 0);
            $this->LogMessage("fetchSystemInfo: Ungültige JSON-Antwort von $ip", KL_ERROR);
            $this->UpdateFormField("StatusInstances", "caption", "Ungültige Antwort vom Gateway.");
            $this->SetStatus(IS_BAD_JSON);
            return json_encode(false);
        }
        else {
            $this->SendDebug(__FUNCTION__, "Systeminfo received: " . print_r($data, true), 0);
            $this->SetStatus(IS_ACTIVE);
        }

        return json_encode($data);
    }

    // --- Hilfsfunktionen ---
    private function GetOrCreateCategory($ident, $name, $parentID)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName($id, $name);
        return $id;
    }

    private function GetOrCreateVariable($ident, $name, $profile, $parentID, $type)
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === false) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parentID);
            IPS_SetIdent($id, $ident);
        }
        IPS_SetName($id, $name);
        if ($profile != "" && IPS_VariableProfileExists($profile)) {
            IPS_SetVariableCustomProfile($id, $profile);
        }
        return $id;
    }

    public function GetTempDataForRoom(int $roomId)
    {
        $url = $this->getJsonGet() . CONTROME_API::GET_TEMPERATURS . "$roomId/";

        $response = @file_get_contents($url);

        if ($response === false) {
            if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
                $message = $this->CheckHttpReponseHeader($http_response_header);
            }
            else {
                $message = "HTTP request failed and no response header available.";
            }
            $this->SendDebug(__FUNCTION__, "Failed to fetch room data from $url\n" . $message, 0);
            $this->LogMessage("Failed to fetch room data from $url\n" . $message, KL_ERROR);
            // Etwas stimmt nicht
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            // Etwas stimmt nicht
            $this->SetStatus(IS_NO_CONNECTION);
            $this->SendDebug(__FUNCTION__, "Invalid JSON response from $url", 0);
            $this->LogMessage("Invalid JSON response from $url", KL_ERROR);
            return false;
        }

        // Wenn ein Array mit Objekten kommt, das erste nehmen - das macht Controme i.d.R.
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        //Alle ist ok.
        $this->SetStatus(IS_ACTIVE);
        return ($data);
    }

    private function WriteSetpoint(mixed $data): string
    {
        $roomId   = isset($data['RoomID'])   ? intval($data['RoomID'])   : null;
        $setpoint = isset($data['Setpoint']) ? floatval($data['Setpoint']) : null;

        if ($roomId === null || $setpoint === null) {
            $this->SendDebug(__FUNCTION__, 'SETPOINT missing params', 0);
            return json_encode(['success' => false, 'message' => 'Missing p arameter RoomID or Setpoint']);
        }

        $ip   = $this->ReadPropertyString('IPAddress');
        $user = $this->ReadPropertyString('User');
        $pass = $this->ReadPropertyString('Password');

        if (empty($ip) || empty($user) || empty($pass)) {
            // Etwas stimmt nicht
            $this->SetStatus(IS_NO_CONNECTION);
            $this->SendDebug(__FUNCTION__, 'Missing gateway credentials', 0);
            return json_encode(['success' => false, 'message' => 'Missing gateway credentials']);
        }

        // URL laut Controme-Doku
        //$url = "http://$ip/set/json/v1/1/soll/$roomId/";
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
            $this->SendDebug(__FUNCTION__, 'Request failed: ' . $message, 0);
            // Etwas stimmt nicht
            $this->SetStatus(IS_NO_CONNECTION);
            return json_encode(['success' => false, 'message' => 'Request failed: ' . $message]);
        }

        // Versuche JSON zu decodieren — falls die API was Kulantes zurückliefert
        $json = json_decode($response, true);
        if ($json === null) {
            // Wenn kein JSON, aber die API trotzdem success impliziert, akzeptieren wir das
            $this->SendDebug(__FUNCTION__, 'Non-JSON response: ' . $response, 0);
            $this->SetStatus(IS_ACTIVE);
            // Optional: treat any non-empty response as success (oder decide otherwise)
            return json_encode(['success' => true, 'message' => strlen(trim($response)) > 0 ? true : 'Empty response - guessing all good.']);
        }

        // Fallback: wenn JSON vorliegt, aber kein explicit success -> als OK werten oder genauer prüfen
        $this->SendDebug(__FUNCTION__, 'API returned: ' . print_r($json, true), 0);

        // Prüfe auf explizite Fehlermeldung in der JSON Response
        if (isset($json['error']) || isset($json['status']) && $json['status'] === 'error') {
            $errorMsg = $json['error'] ?? $json['message'] ?? 'Unknown API error';
            $this->SendDebug(__FUNCTION__, 'API returned error: ' . $errorMsg, 0);
            return json_encode(['success' => false, 'message' => 'API Error: ' . $errorMsg]);
        }

        //Alle ist ok.
        $this->SetStatus(IS_ACTIVE);
        return json_encode(['success' => true, 'message' => 'Setpoint updated']);

    }

    public function CreateCentralControlInstance(): bool
    {
        $parentId = $this->ReadPropertyInteger("TargetParent"); // Gewählter Parent
        $instanceName = $this->ReadPropertyString("InstanceName"); // Gewählter Name

        if (!$parentId || !$instanceName) {
            $this->SendDebug(__FUNCTION__, "Parent or name not set!", 0);
            return false;
        }

        // Neue Central Control Instanz erstellen
        $newId = IPS_CreateInstance('{A19ABE82-5AB1-7969-3851-E6446DECEBA9}');
        IPS_SetParent($newId, $parentId);
        IPS_SetName($newId, $instanceName);
        IPS_ApplyChanges($newId);

        $this->SendDebug(__FUNCTION__, "Central Control created under parent $parentId with name '$instanceName', ID $newId", 0);

        return true;
    }

    public function EnableDisableFormButtons()
    {
        $rooms = json_decode($this->ReadPropertyString("Rooms"));

        // Prüfen, ob Räume vorhanden sind
        if (is_array($rooms) && count($rooms) > 0) {
            // Räume existieren – Buttons einblenden
            $this->UpdateFormField("ButtonCreateCentralInstance", "enabled", "true");
            $this->UpdateFormField("ButtonCreateRoomInstance", "enabled", "true");
        } else {
            // Keine Räume vorhanden
            $this->UpdateFormField("ButtonCreateCentralInstance", "enabled", "false");
            $this->UpdateFormField("ButtonCreateRoomInstance", "enabled", "false");
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

    protected function PingGateway(string $ip, int $timeout = 1000): bool
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
            $this->SendDebug(__FUNCTION__, "Fehler bei Verbindung zu $ip: $error", 0);
            return false;
        }

        $this->SendDebug(__FUNCTION__, "Antwort von $ip, HTTP Code: $httpCode", 0);
        return $httpCode > 0;
    }
}
