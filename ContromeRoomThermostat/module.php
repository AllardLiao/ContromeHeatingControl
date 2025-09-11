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

// IPS-Stubs nur in der Entwicklungsumgebung laden
if (substr(__DIR__,0, 10) == "/Users/kai") {
    // Development
    include_once __DIR__ . '/../.ips_stubs/autoload.php';
}

// Bibliotheks-übergreifende Constanten einbinden
require_once __DIR__ . '/../libs/ContromeConstants.php';
use Controme\GUIDs;
use Controme\ACTIONs;

class ContromeRoomThermostat extends IPSModuleStrict
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

        // Konfigurationselemente des Raums
        $this->RegisterPropertyInteger("FloorID", 0);
        $this->RegisterPropertyString("Floor", "");
        $this->RegisterPropertyInteger("RoomID", 0);
        $this->RegisterPropertyString("Room", "");

        //Konfigurationselemente der zyklischen Abfrage
        $this->RegisterPropertyInteger("UpdateInterval", 5); // in Minuten
        $this->RegisterPropertyBoolean("AutoUpdate", true);


        // Konfigurationselemente der Tile-Visualisierung
        $this->RegisterPropertyBoolean("VisuXYZ", true);

        // Timer für zyklische Abfrage (Voreingestellt: alle 5 Minuten)
        $this->RegisterTimer("UpdateContromeDataRoomID" . $this->InstanceID, 5 * 60 * 1000, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateRoomData", true);');

    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges() : void
    {
        //Never delete this line!
        parent::ApplyChanges();

        if ($this->ReadPropertyBoolean("AutoUpdate")) {
            $this->SetTimerInterval("UpdateContromeDataRoomID" . $this->InstanceID, $this->ReadPropertyInteger("UpdateInterval") * 60 * 1000);
        } else {
            $this->SetTimerInterval("UpdateContromeDataRoomID" . $this->InstanceID, 0);
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
            case "UpdateRoomData":
                $this->UpdateRoomData();
                break;
            case "CheckConnection":
                $this->CheckConnection();
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
        $floorID = $this->ReadPropertyInteger("FloorID");
        $floor = $this->ReadPropertyString("Floor");
        $roomID = $this->ReadPropertyInteger("RoomID");
        $room = $this->ReadPropertyString("Room");

        // Der Output kann unter Umständen die Ergebnisse von zwei Prüfschritten enthalten, deswegen eine Merker-Variable
        $outputText = "";

        if (empty($floorID) || empty($roomID)) {
            $this->SendDebug("CheckConnection", "Please configure Floor-ID and Room-ID!", 0);
            $outputText .= "Please set all parameters floor-ID and room-ID).\n";
            $this->UpdateFormField("Result", "caption", $outputText);
            return false;
        }

        if (empty($floor) || empty($room)) {
            $this->SendDebug("CheckConnection", "Missing parameters floor and/or room.", 0);
            $outputText .= "Missing parameters floor and/or room.\n";
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->LogMessage("CheckConnection: floor and room names missing. Fetching from gateway upon next update.", KL_NOTIFY);
        }

        $response = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::CHECK_CONNECTION
        ]));

        $result = json_decode($response, true);

        if ($result['success']) {
            $this->SendDebug("CheckConnection", "Connection to Gateway and Controme Mini-Server is working!", 0);
            $outputText .= "Connection to Gateway and Controme Mini-Server is working!\n";
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->LogMessage("Connection to Gateway and Controme Mini-Server is working!", KL_MESSAGE);
        } else {
            $this->SendDebug("CheckConnection", "Connection failed!", 0);
            $outputText .= "Connection failed!\n";
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->LogMessage("Connection failed!", KL_ERROR);
            return false;
        }

        $floorId = $this->ReadPropertyInteger("FloorID");
        $roomId  = $this->ReadPropertyInteger("RoomID");

        // Anfrage ans Gateway schicken
        $result = $this->SendDataToParent(json_encode([
            "DataID"  => GUIDs::DATAFLOW, // die gemeinsame DataFlow-GUID
            "Action"  => ACTIONs::GET_TEMP_DATA_FOR_ROOM,
            "FloorID" => $floorId,
            "RoomID"  => $roomId
        ]));

        if ($result === false) {
            $this->SendDebug("CheckConnection", "Fetching Data: no response from gateway!", 0);
            $outputText .= "Fetching Data: no response from gateway!\n";
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->LogMessage("Fetching Data: no response from gateway", KL_ERROR);
            return false;
        }

        $data = json_decode($result, true);
        if (isset($data['name'])) {
            $this->SendDebug("CheckConnection", "Fetching Data: Room $roomId found and data seems valid.", 0);
            $outputText .= "Fetching Data: Room $roomId found and data seems valid. (Returned room name \"" . $data['name'] . "\" with temperature " . $data['temperatur'] . " °C.";
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->LogMessage("Fetching Data: Room $roomId found and data seems valid.", KL_MESSAGE);
        } else {
            $this->SendDebug("CheckConnection", "Fetching Data: Room $roomId data not valid!", 0);
            $outputText .= "Fetching Data: Room $roomId data not valid!";
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->LogMessage("Fetching Data: Room data not valid!", KL_ERROR);
            return false;
        }

        // Alles ok - also können wir auch direkt die Daten in Variablen Speichern.
        return $this->saveDataToVariables($data);
    }

    // Funktion die zyklisch aufgerufen wird (wenn aktiv) und die Werte des Raums aktualisiert
    private function UpdateRoomData(): bool
    {
        $roomId   = $this->ReadPropertyInteger("RoomID");
        $floorId  = $this->ReadPropertyInteger("FloorID");

        // Daten vom Gateway holen
        $result = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::GET_TEMP_DATA_FOR_ROOM,
            "RoomID" => $roomId,
            "FloorID"=> $floorId
        ]));

        if ($result === false) {
            $this->SendDebug("UpdateRoomData", "No data received!", 0);
            $this->LogMessage("Fetching Data for Room $roomId returned no data!", KL_ERROR);
            return false;
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            $this->SendDebug("UpdateRoomData", "Invalid data received: " . $result, 0);
            $this->LogMessage("Fetching Data for Room $roomId returned invalid data!", KL_ERROR);
            return false;
        }
$this->SendDebug("UpdateRoomData", "Decoded data: " . print_r($data, true), 0);
        // Variablen anlegen und updaten
        $this->saveDataToVariables($data);

        $this->SendDebug("UpdateRoomData", "Room data updated", 0);

        return true;
    }

    private function saveDataToVariables($data): bool
    {
        // Variablen anlegen und updaten
        $this->MaintainVariable("Temperature", "Actual Temperature", VARIABLETYPE_FLOAT, "~Temperature.Room", 1, true);
        $this->MaintainVariable("Setpoint", "Set Temperature", VARIABLETYPE_FLOAT, "~Temperature.Room", 2, true);
        $this->MaintainVariable("Humidity", "Humidity", VARIABLETYPE_FLOAT, "~Humidity.F", 3, true);
        $this->MaintainVariable("Mode", "Operating Mode", VARIABLETYPE_STRING, "", 4, true);

        if (isset($data['temperatur'])) {
            SetValue($this->GetIDForIdent("Temperature"), floatval($data['temperatur']));
        }
        if (isset($data['solltemperatur'])) {
            SetValue($this->GetIDForIdent("Setpoint"), floatval($data['solltemperatur']));
        }
        if (isset($data['luftfeuchte'])) {
            SetValue($this->GetIDForIdent("Humidity"), floatval($data['luftfeuchte']));
        }
        if (isset($data['betriebsart'])) {
            SetValue($this->GetIDForIdent("Mode"), strval($data['betriebsart']));
        }

        return true;
    }
}
