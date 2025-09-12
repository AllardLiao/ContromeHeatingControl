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
        $this->RegisterPropertyInteger("FloorID", 1);
        $this->RegisterPropertyString("Floor", "");
        $this->RegisterPropertyInteger("RoomID", 0);
        $this->RegisterPropertyString("Room", "");

        //Konfigurationselemente der zyklischen Abfrage
        $this->RegisterPropertyInteger("UpdateInterval", 5); // in Minuten
        $this->RegisterPropertyBoolean("AutoUpdate", true);

        // Schrittweite für Setpoint-Änderung (Default: 0.5)
        $this->RegisterPropertyFloat('StepSize', 0.5);

    // Variablen definieren - read-only, kommt von Controme
        $this->RegisterVariableFloat("Temperature", "Raumtemperatur", "~Temperature.Room", 1);
        $this->RegisterVariableFloat("Setpoint", "Solltemperatur", "~Temperature.Room", 2);
        $this->RegisterVariableFloat("Humidity", "Luftfeuchtigkeit", "~Humidity.F", 3);
        $this->RegisterVariableString("Mode", "Betriebsart", "", 4);

        // Variablen definieren - Anpassbar machen mit Rückschreibung an Controme
        $this->EnableAction("Setpoint");
        $this->EnableAction('inc');
        $this->EnableAction('dec');

        //Visu Type setzen:
        $this->SetVisualizationType(1);     // 1 = Tile Visu; 0 = Standard.

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
            case ACTIONs::UPDATE_ROOM_DATA:
                $this->UpdateRoomData();
                break;
            case ACTIONs::CHECK_CONNECTION:
                $this->CheckConnection();
                break;
            case ACTIONs::WRITE_SETPOINT:
                $result = $this->WriteSetpoint(floatval($value));
                break;
            case 'inc':
                $new = $this->GetValue('Setpoint') + $this->ReadPropertyFloat('StepSize');
                $this->WriteSetpoint($new);
                break;
            case 'dec':
                $new = $this->GetValue('Setpoint') - $this->ReadPropertyFloat('StepSize');
                $this->WriteSetpoint($new);
                break;
            case 'setpoint':
                $this->WriteSetpoint((float)$value);
                break;
        default:
                throw new Exception("Invalid function call to CONRTROME Room Thermostat. RequestAction: " . $ident);
        }
        // Immer aktuellen Status zurücksenden
        $this->UpdateVisualizationValue(json_encode([
            'Setpoint'    => floatval($this->GetValue('Setpoint')),
            'Temperature' => floatval($this->GetValue('Temperature')),
            'Humidity'    => floatval($this->GetValue('Humidity')),
            'Mode'        => $this->GetValue('Mode')
        ]));
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
            $this->LogMessage("CheckConnection: floor-ID and/or room-ID missing!", KL_NOTIFY);
            $this->SetStatus(IS_INACTIVE);
            return false;
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
            $this->SetStatus(IS_NO_CONNECTION);
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
            $this->SetStatus(IS_NO_CONNECTION);
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
            $this->SetStatus(IS_BAD_JSON);
            return false;
        }

        // Alles ok - also können wir auch direkt die Daten in Variablen Speichern.
        $this->SetStatus(IS_ACTIVE);
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

        // Variablen anlegen und updaten
        $this->saveDataToVariables($data);

        $this->SendDebug("UpdateRoomData", "Room data updated", 0);
        $this->SetStatus(IS_ACTIVE);

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
            $this->SetValue("Temperature", floatval($data['temperatur']));
        }
        if (isset($data['solltemperatur'])) {
            $this->SetValue("Setpoint", floatval($data['solltemperatur']));
        }
        if (isset($data['luftfeuchte'])) {
            $this->SetValue("Humidity", floatval($data['luftfeuchte']));
        }
        if (isset($data['betriebsart'])) {
            $this->SetValue("Mode", strval($data['betriebsart']));
        }

        return true;
    }

    private function WriteSetpoint(float $value): bool
    {
        $roomId  = $this->ReadPropertyInteger("RoomID");
        $floorId = $this->ReadPropertyInteger("FloorID");

        $data = [
            "DataID"   => GUIDs::DATAFLOW,
            "Action"   => ACTIONs::WRITE_SETPOINT,
            "FloorID"  => $floorId,
            "RoomID"   => $roomId,
            "Setpoint" => $value
        ];

        $result = $this->SendDataToParent(json_encode($data));
        $this->SendDebug('SendSetpointToParent', 'Response: ' . $result, 0);

        if ($result !== false) {
            $decoded = json_decode($result, true);
            if (is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }
            $this->SendDebug("WriteSetpoint", "Decoded result: " . print_r($decoded, true), 0);
            if (isset($decoded['success']) && $decoded['success'] === true) {
                $this->SetValue("Setpoint", $value); // nur bei Erfolg lokal setzen
                $this->SendDebug("WriteSetpoint", "Solltemperatur erfolgreich gesetzt: " . $value, 0);
            } else {
                $msg = $decoded['message'] ?? 'Unbekannter Fehler';
                $this->SendDebug("WriteSetpoint", "Fehler beim Setzen der Solltemperatur: " . $msg, 0);
                $this->LogMessage("Setpoint-Fehler: " . $msg, KL_ERROR);
                // Optional: User Feedback ins Frontend
                $this->UpdateFormField("Result", "caption", "Fehler: " . $msg);
                $this->SetStatus(IS_NO_CONNECTION);
                throw new UserFriendlyException($decoded['message']);
            }
        } else {
            $this->SendDebug("WriteSetpoint", "Fehler: Gateway hat einen Fehler zurückgegeben (siehe Debug)!", 0);
            $this->LogMessage("Setpoint-Fehler: Gateway hat einen Fehler zurückgegeben!", KL_ERROR);
            $this->SetStatus(IS_NO_CONNECTION);
            return false;
        }

        $this->SetStatus(IS_ACTIVE);
        return true;
    }

    public function GetVisualizationTile(): string
    {
        // sichere Werte holen
        $step        = $this->ReadPropertyFloat('StepSize');
        $title       = htmlspecialchars($this->ReadPropertyString('Room') ?: $this->ReadPropertyString('Floor') ?: 'Thermostat', ENT_QUOTES);
        $instanceId  = $this->InstanceID;
        $setpoint    = floatval($this->GetValue('Setpoint'));     // helper siehe unten
        $temperature = floatval($this->GetValue('Temperature'));
        $humidity    = floatval($this->GetValue('Humidity'));
        $mode        = htmlspecialchars((string)$this->GetValue('Mode'), ENT_QUOTES);

        // Template-Datei laden (module.html)
        $moduleFile = __DIR__ . '/module.html';
        if (!file_exists($moduleFile)) {
            // Fallback: kleines inline-HTML, falls module.html fehlt
            $html = "<div style='font-family:sans-serif;padding:8px;'>Controme Thermostat</div>";
        } else {
            $html = file_get_contents($moduleFile);
            if ($html === false) $html = '';
        }

        // Platzhalter ersetzen (Initwerte)
        $repl = [
            '{{InstanceID}}' => $instanceId,
            '{{Setpoint}}'   => number_format($setpoint, 1, '.', ''),
            '{{Temperature}}'=> number_format($temperature, 1, '.', ''),
            '{{Humidity}}'   => number_format($humidity, 1, '.', ''),
            '{{Mode}}'       => $mode,
            '{{Step}}'       => rtrim(rtrim((string)$step, '0'), '.'),
            '{{Title}}'      => $title
        ];
        $html = str_replace(array_keys($repl), array_values($repl), $html);

        // WICHTIG: als STRING zurückgeben (HTML)
        return $html;
    }

    public function getVisu(): String
    {
        return $this->GetVisualizationTile();
    }
}
