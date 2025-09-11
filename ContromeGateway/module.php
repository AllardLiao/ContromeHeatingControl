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

        // Variablenprofil für Betriebsart sicherstellen
        $profile = "Controme.Betriebsart";
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1); // 1 = Integer
            IPS_SetVariableProfileIcon($profile, "Gear");
            IPS_SetVariableProfileAssociation($profile, 0, "Kühlen (Auto)", "", -1);
            IPS_SetVariableProfileAssociation($profile, 1, "Dauer-Aus", "", -1);
            IPS_SetVariableProfileAssociation($profile, 2, "Heizen (Auto)", "", -1);
            IPS_SetVariableProfileAssociation($profile, 3, "Dauer-Ein", "", -1);
        }

        // JSON url anpassen
        $this->setJsonGet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));
        $this->setJsonSet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));
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
                $this->FetchRoomList();
                break;
            case ACTIONs::CHECK_CONNECTION:
                $this->CheckConnection();
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

    /**
     * Is called by pressing the button "Check Connection" from the instance configuration
     *
     * @return boolean
     */
    public function CheckConnection(): bool
    {
        $ip   = $this->ReadPropertyString("IPAddress");
        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        // 1. Test: Daten da?
        if (empty($ip) || empty($user) || empty($pass)) {
            $this->SendDebug("CheckConnection", "IP, User oder Passwort nicht gesetzt!", 0);
            $this->UpdateFormField("Result", "caption", "Please set all 3 parameters (username, password and device IP).");
            return false;
        } else {
            $this->SendDebug("CheckConnection", "Check 1 - sufficient data available to try to connect.", 0);
        }

        // 2. Test: Verbindung zum Gateway erreichbar?
        // Dafür nutzen wir schon die Datenabruf-Funktion, denn wir brauchen den Soll-Wert von Raum 1 um das Schreiben mit Passwort zu testen.
        $currentData = $this->GetTempDataForRoom(1); //Raum 1 sollte es immer geben.

        // Sollte eigentlich klappen - Controme prüft beim get nicht das Passwort. Wenn es nicht klappt kann es fast nur die IP sein.
        if ($currentData === false){
            $this->SendDebug("CheckConnection", "No connection to Controme MiniServer at $ip - please check IP!", 0);
            $this->LogMessage("No connection to Controme MiniServer at $ip - please check IP!", KL_ERROR);
            $this->UpdateFormField("Result", "caption", "No connection to Controme MiniServer at $ip - please check IP!");
            return false;
        } else {
            $this->SendDebug("CheckConnection", "Check 2 - connection to Controme MiniServer at $ip established. (" . $currentData["name"] . "=" . $currentData["temperatur"] . ")", 0);
        }

        // 3. Test: Wird das Passwort akzeptiert? Dazu schreiben wir die eben ausgelesene Solltemperatur zurück.
        $sendData = Array('RoomID' => 1, 'Setpoint' => $currentData['solltemperatur']);
        $result = $this->WriteSetpoint($sendData);

        $decoded = json_decode($result, true);
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        $this->SendDebug("CheckConnection", "Decoded result: " . print_r($decoded, true), 0);
        if (isset($decoded['success']) && $decoded['success'] === true) {
            $this->SendDebug("CheckConnection", "Success - connection established for user " . $user . "!");
            $this->LogMessage("Success - connection established for user " . $user . "!", KL_NOTIFY);
            $this->UpdateFormField("Result", "caption", "Success - connection established for user " . $user . "!");
            return true;
        } else {
            $this->SendDebug('WriteSetpoint', $decoded['message'] . "!", 0);
            $this->LogMessage($decoded['message'] . "!", KL_NOTIFY);
            $this->UpdateFormField("Result", "caption", $decoded['message'] . "!");
            return false;
        }
    }

    //
    public function ForwardData($JSONString): string
    {
        // Auswertung für Aufrufe von Child-Instanzen
        $data = json_decode($JSONString, true);

        if (!isset($data['Action'])) {
            $this->SendDebug("ForwardData", "No action provided!", 0);
            return json_encode(false);
        }

        // JSON url anpassen
        $this->setJsonGet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));
        $this->setJsonSet($this->ReadPropertyString("IPAddress"), $this->ReadPropertyInteger("HouseID"), $this->ReadPropertyBoolean("UseHTTPS"));

        switch ($data['Action']) {
            case ACTIONs::CHECK_CONNECTION:
                $ok = $this->CheckConnection(); // <- deine bestehende Funktion
                return json_encode([
                    "success" => $ok,
                    "message" => $ok ? "Connected" : "Connection failed"
                ]);

            case ACTIONs::GET_TEMP_DATA_FOR_ROOM:
                if (!isset($data['FloorID']) || !isset($data['RoomID'])) {
                    $this->SendDebug("GET_ROOM_DATA", "Missing FloorID or RoomID", 0);
                    return json_encode(false);
                }

                $roomId  = (int)$data['RoomID'];
                $roomData = $this->GetTempDataForRoom($roomId);
                return json_encode($roomData);

            case ACTIONs::WRITE_SETPOINT:
                return json_encode($this->WriteSetpoint($data));

            default:
                $this->SendDebug("ForwardData", "Unknown action: " . $data['Action'], 0);
                return json_encode(false);

        }

        return json_encode([
            "success" => false,
            "message" => "Unknown action"
        ]);
    }

    /**
     * Update Data from Controme MiniServer
     *
     * @return boolean
     */
    public function FetchRoomList(): bool
    {
        $ip   = $this->ReadPropertyString("IPAddress");
        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        if (empty($ip) || empty($user) || empty($pass)) {
            $this->SendDebug("FetchRoomList", "IP, User oder Passwort nicht gesetzt!", 0);
            $this->UpdateFormField("StatusInstances", "caption", "Failed to login - missing argument.");
            return false;
        }

        $url = $this->getJsonGet() . CONTROME_API::GET_ROOMS;
        $opts = [
            "http" => [
                "header" => "Authorization: Basic " . base64_encode("$user:$pass")
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);

        if ($json === FALSE) {
            if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
                $message = $this->CheckHttpReponseHeader($http_response_header);
            }
            else {
                $message = "HTTP request failed and no response header available.";
            }
            $this->SendDebug("FetchRoomList", "Fehler beim Abrufen von $url\n" . $message, 0);
            $this->UpdateFormField("StatusInstances", "caption", "Failed to read data.");
            return false;
        }

        $data = json_decode($json, true);
        if ($data === null) {
            $this->SendDebug("FetchRoomList", "Fehler beim JSON-Decode", 0);
            $this->UpdateFormField("StatusInstances", "caption", "Failed to decode data.");
            return false;
        }

        // Räume durchgehen und Json für Formular-Liste erstellen.
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
        $this->UpdateFormField("Rooms", "values", json_encode($formListJson));

        // Räume Speichern
        //$this->WriteAttributeString("Rooms", $json);

        $this->SendDebug("FetchRoomList", "Updated Controme Heating Data.", 0);
        $this->UpdateFormField("StatusInstances", "caption", "Room list updated.");
        $this->UpdateFormField("ExpansionPanelRooms", "expanded", "true");
        $this->UpdateFormField("ButtonCreateCentralInstance", "enabled", "true");
        $this->UpdateFormField("ButtonCreateRoomInstance", "enabled", "true");
        return true;
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
        // Beispiel: Abfrage an Controme API bauen
        $ip = $this->ReadPropertyString("IPAddress");

        //$url = "http://$ip/get/json/v1/1/temps/$roomId/";
        $url = $this->getJsonGet() . CONTROME_API::GET_TEMPERATURS . "$roomId/";

        $response = @file_get_contents($url);

        if ($response === false) {
            if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
                $message = $this->CheckHttpReponseHeader($http_response_header);
            }
            else {
                $message = "HTTP request failed and no response header available.";
            }
            $this->SendDebug("GetRoomData", "Failed to fetch room data from $url\n" . $message, 0);
            $this->LogMessage("Failed to fetch room data from $url\n" . $message, KL_ERROR);
            return false;
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            $this->SendDebug("GetRoomData", "Invalid JSON response from $url", 0);
            $this->LogMessage("Invalid JSON response from $url", KL_ERROR);
            return false;
        }

        // Wenn ein Array mit Objekten kommt, das erste nehmen - das macht Controme i.d.R.
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }
        return $data;
    }

    private function WriteSetpoint(mixed $data): string
    {
        $roomId   = isset($data['RoomID'])   ? intval($data['RoomID'])   : null;
        $setpoint = isset($data['Setpoint']) ? floatval($data['Setpoint']) : null;

        if ($roomId === null || $setpoint === null) {
            $this->SendDebug('WriteSetpoint', 'SETPOINT missing params', 0);
            return json_encode(['success' => false, 'message' => 'Missing p arameter RoomID or Setpoint']);
        }

        $ip   = $this->ReadPropertyString('IPAddress');
        $user = $this->ReadPropertyString('User');
        $pass = $this->ReadPropertyString('Password');

        if (empty($ip) || empty($user) || empty($pass)) {
            $this->SendDebug('WriteSetpoint', 'Missing gateway credentials', 0);
            return json_encode(['success' => false, 'message' => 'Missing gateway credentials']);
        }

        // URL laut Controme-Doku
        //$url = "http://$ip/set/json/v1/1/soll/$roomId/";
        $url = $this->getJsonSet() . CONTROME_API::SET_SETPOINT . "$roomId/";

        // POST-Daten (ggf. action/value anpassen nach der Controme-API für setzen der Solltemperatur)
        $postData = json_encode([
            'user'     => $user,
            'password' => $pass,
            'soll'     => (float)$setpoint
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

        $this->SendDebug('WriteSetpoint', "POST $url\nData: " . $postData . "\nContext: " . $context, 0);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
                $message = $this->CheckHttpReponseHeader($http_response_header);
            }
            else {
                $message = "HTTP request failed and no response header available.";
            }
            $this->SendDebug('WriteSetpoint', 'Request failed: ' . $message, 0);
            return json_encode(['success' => false, 'message' => 'Request failed: ' . $message]);;
        }

        // Versuche JSON zu decodieren — falls die API was Kulantes zurückliefert
        $json = json_decode($response, true);
        if ($json === null) {
            // Wenn kein JSON, aber die API trotzdem success impliziert, akzeptieren wir das
            $this->SendDebug('WriteSetpoint', 'Non-JSON response: ' . $response, 0);
            // Optional: treat any non-empty response as success (oder decide otherwise)
            return strlen(trim($response)) > 0 ? true : 'Empty response';
        }

        // Fallback: wenn JSON vorliegt, aber kein explicit success -> als OK werten oder genauer prüfen
        $this->SendDebug('WriteSetpoint', 'API returned: ' . print_r($json, true), 0);

        // Falls die API ein structured response liefert, prüfe ein success-flag
        if (isset($json['success'])) {
            return ($json['success'] ? true : (isset($json['message']) ? $json['message'] : 'API returned failure'));
        }

        return json_encode(['success' => true, 'message' => 'Setpoint updated']);

    }

    public function CreateCentralControlInstance(): bool
    {
        $parentId = $this->ReadPropertyInteger("TargetParent"); // Gewählter Parent
        $instanceName = $this->ReadPropertyString("InstanceName"); // Gewählter Name

        if (!$parentId || !$instanceName) {
            $this->SendDebug("CreateCentralControl", "Parent or name not set!", 0);
            return false;
        }

        // Neue Central Control Instanz erstellen
        $newId = IPS_CreateInstance('{A19ABE82-5AB1-7969-3851-E6446DECEBA9}');
        IPS_SetParent($newId, $parentId);
        IPS_SetName($newId, $instanceName);
        IPS_ApplyChanges($newId);

        $this->SendDebug("CreateCentralControl", "Central Control created under parent $parentId with name '$instanceName', ID $newId", 0);

        return true;
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

}
