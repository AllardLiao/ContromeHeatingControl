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

        //Konfigurationselemente der zyklischen Abfrage
        $this->RegisterPropertyInteger("UpdateInterval", 5); // in Minuten
        $this->RegisterPropertyBoolean("AutoUpdate", true);

        //Visu Type setzen:
        $this->SetVisualizationType(1);     // 1 = Tile Visu; 0 = Standard.

        // Timer für zyklische Abfrage (Voreingestellt: alle 5 Minuten)
        $this->RegisterTimer("UpdateContromeDataCentralControl" . $this->InstanceID, 5 * 60 * 1000, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateRoomData", true);');
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
            case ACTIONs::UPDATE_ROOM_DATA:
                $this->UpdateRoomData();
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
            $this->SendDebug("TestReadRoomData", "Fetching Data: no response from gateway!", 0);
            $this->LogMessage("TestReadRoomData: Fetching Data: no response from gateway", KL_ERROR);
            $this->UpdateFormField("ResultTestRead", "caption", "Fetching Data: no response from gateway");
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }

        $data = json_decode($result, true);
        if (isset($data['name'])) {
            $this->SendDebug("TestReadRoomData", "Fetching Data: Room $roomId found and data seems valid.", 0);
            $this->LogMessage("TestReadRoomData: Fetching Data: Room $roomId found and data seems valid. (Returned room name \"" . $data['name'] . "\" with temperature " . $data['temperatur'] . " °C.", KL_MESSAGE);
            $this->UpdateFormField("ResultTestRead", "caption", "Fetching Data: Room $roomId found and data seems valid. (Returned room name \"" . $data['name'] . "\" with temperature " . $data['temperatur'] . " °C.");
        } else {
            $this->SendDebug("TestReadRoomData", "Fetching Data ok, but room $roomId data not valid!", 0);
            $this->LogMessage("TestReadRoomData: Fetching Data ok, but room data not valid!", KL_ERROR);
            $this->UpdateFormField("ResultTestRead", "caption", "Fetching Data ok, but room data not valid");
            $this->SetStatus(IS_BAD_JSON);
            return false;
        }

        // Alles ok - also können wir auch direkt die Daten in Variablen Speichern.
        $this->SetStatus(IS_ACTIVE);
        return true;
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
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            $this->SendDebug("UpdateRoomData", "Invalid data received: " . $result, 0);
            $this->LogMessage("Fetching Data for Room $roomId returned invalid data!", KL_ERROR);
            $this->SetStatus(IS_BAD_JSON);
            return false;
        }

        $this->SendDebug("UpdateRoomData", "Room data updated", 0);
        $this->SetStatus(IS_ACTIVE);

        return $this->saveDataToVariables($data);
    }

}
