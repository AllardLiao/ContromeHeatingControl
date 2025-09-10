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
        $this->RegisterPropertyString("FloorID", "");
        $this->RegisterPropertyString("Floor", "");
        $this->RegisterPropertyString("RoomID", "");
        $this->RegisterPropertyString("Room", "");

        //Konfigurationselemente der zyklischen Abfrage
        $this->RegisterPropertyInteger("UpdateInterval", 5); // in Minuten
        $this->RegisterPropertyBoolean("AutoUpdate", true);


        // Konfigurationselemente der Tile-Visualisierung
        $this->RegisterPropertyBoolean("VisuXYZ", true);

        // Timer für zyklische Abfrage (Voreingestellt: alle 5 Minuten)
        $this->RegisterTimer("UpdateContromeDataRoomID" . $this->InstanceID, 5 * 60 * 1000, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", true);');

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
            case "UpdateData":
//                $this->UpdateData();
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
        $floorID   = $this->ReadPropertyString("FloorID");
        $floor = $this->ReadPropertyString("Floor");
        $roomID = $this->ReadPropertyString("RoomID");
        $room = $this->ReadPropertyString("Room");

        if (empty($floorID) || empty($roomID)) {
            $this->SendDebug("CheckConnection", "Please configure FloorID, Floor, RoomID and Room!", 0);
            $this->UpdateFormField("Result", "caption", "Please set all 4 parameters (floor-ID and -name, room-ID and -name).");
            return false;
        }

        if (empty($floor) || empty($room)) {
            $this->SendDebug("CheckConnection", "Missing parameters floor and/or room.", 0);
            $this->UpdateFormField("Result", "caption", "Missing parameters floor and/or room.");
            $this->LogMessage("CheckConnection: floor and room names missing. Fetching from I/O Socket upon next update.", KL_NOTIFY);
            return false;
        }

        $result = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::CHECK_CONNECTION
        ]));

        echo $result;
        return false;

        if ($result) {
            $this->SendDebug("CheckConnection", "Connection to Gateway and Controme Mini-Server is working!", 0);
            $this->UpdateFormField("Result", "caption", "Connection to Gateway and Controme Mini-Server is working!");
            $this->LogMessage("Connection to Gateway and Controme Mini-Server is working!", KL_MESSAGE);
        } else {
            $this->SendDebug("CheckConnection", "Connection failed!", 0);
            $this->UpdateFormField("Result", "caption", "Connection failed!");
            $this->LogMessage("Connection failed!", KL_ERROR);
            return false;
        }

        $floorId = $this->ReadPropertyInteger("FloorID");
        $roomId  = $this->ReadPropertyInteger("RoomID");

        // Anfrage ans Gateway schicken
        $result = $this->SendDataToParent(json_encode([
            "DataID"  => GUIDs::DATAFLOW, // die gemeinsame DataFlow-GUID
            "Action"  => ACTIONs::GET_ROOM_DATA,
            "FloorID" => $floorId,
            "RoomID"  => $roomId
        ]));

        if ($result === false) {
            $this->SendDebug("CheckConnection", "Fetching Data: no response from gateway!", 0);
            $this->UpdateFormField("Result", "caption", "Fetching Data: no response from gateway!");
            $this->LogMessage("Fetching Data: no response from gateway", KL_ERROR);
            return false;
        }

        $data = json_decode($result, true);
        if (isset($data['name'])) {
            $this->SendDebug("CheckConnection", "Fetching Data: Room {$data['name']} found", 0);
            $this->UpdateFormField("Result", "caption", "Fetching Data: Room {$data['name']} found");
            $this->LogMessage("Fetching Data: Room {$data['name']} found", KL_MESSAGE);
        } else {
            $this->SendDebug("CheckConnection", "Fetching Data: Room data not valid!", 0);
            $this->UpdateFormField("Result", "caption", "Fetching Data: Room data not valid!");
            $this->LogMessage("Fetching Data: Room data not valid!", KL_ERROR);
            return false;
        }

        return true;
    }

}
