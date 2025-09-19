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

class ContromeRoomThermostat extends IPSModuleStrict
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

        // Konfigurationselemente des Raums
        $this->RegisterPropertyInteger("FloorID", 1);
        $this->RegisterPropertyString("Floor", "");
        $this->RegisterPropertyInteger("RoomID", 0);
        $this->RegisterPropertyString("Room", "");

        // Fallbaack für Temperatur
        $this->RegisterPropertyBoolean("FallbackTempSensorUse", false);
        $this->RegisterPropertyInteger("FallbackTempSensor", 0);
        $this->RegisterPropertyFloat("FallbackTempValue", 15);

        //Konfigurationselemente der zyklischen Abfrage
        $this->RegisterPropertyInteger("UpdateInterval", 5); // in Minuten
        $this->RegisterPropertyBoolean("AutoUpdate", true);
        $this->toggleAutoUpdate($this->ReadPropertyBoolean("AutoUpdate"));

        // Schrittweite für Setpoint-Änderung (Default: 0.5)
        $this->RegisterPropertyFloat('StepSize', 0.5);

        //Visu Type setzen:
        $this->SetVisualizationType(1);     // 1 = Tile Visu; 0 = Standard.

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

        // Variablenprofile/Presentationtemplates sicherstellen
        CONTROME_PROFILES::registerAllContromeProfilesAndTemplates();

        // Variablen definieren - read-only, kommt von Controme
        $this->MaintainVariable("Temperature", "Raumtemperatur", VARIABLETYPE_FLOAT, "~Temperature.Room", 1, true);
        $this->MaintainVariable("Humidity", "Luftfeuchtigkeit", VARIABLETYPE_FLOAT, "~Humidity.F", 3, true);
        $this->MaintainVariable("Mode", "Betriebsart", VARIABLETYPE_INTEGER, CONTROME_PROFILES::BETRIEBSART, 4, true);

        // Variablen definieren - Anpassbar machen mit Rückschreibung an Controme
        $this->MaintainVariable("Setpoint", "Solltemperatur", VARIABLETYPE_FLOAT, CONTROME_PROFILES::getSetPointPresentation(), 2, true);
        $this->EnableAction("Setpoint");

        // Timer anpassen
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
            case ACTIONs::TEST_READ_ROOM_DATA:
                $this->UpdateRoomData(true); // Mit Flag für Test-Modus
                break;
            case 'visu_inc':
                $new = $this->GetValue('Setpoint') + $this->ReadPropertyFloat('StepSize');
                $this->WriteSetpoint($new);
                $this->updateVisualization();
                break;
            case 'visu_dec':
                $new = $this->GetValue('Setpoint') - $this->ReadPropertyFloat('StepSize');
                $this->WriteSetpoint($new);
                $this->updateVisualization();
                break;
            case 'visu_setpoint': // Änderung über Visu
                $this->WriteSetpoint((float)$value);
                $this->updateVisualization();
                break;
            case 'form_toggleAutoUpdate': // Auskösung über onChange der Konfig-Forms
                $this->toggleAutoUpdate($value==1);
                break;
            case 'form_toggleFallbackTempSensor': // Auskösung über onChange der Konfig-Forms
                $this->toggleFallbackTempSensor($value==1);
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

    private function toggleAutoUpdate(bool $toggleAutoUpdate)
    {
        $this->UpdateFormField('UpdateInterval', 'enabled', $toggleAutoUpdate ? 'true' : 'false');
    }

    private function toggleFallbackTempSensor(bool $toggleAutoUpdate)
    {
        $this->UpdateFormField('FallbackTempSensor', 'enabled', $toggleAutoUpdate ? 'true' : 'false');
    }

    /**
     * Is called by pressing the button "Check Connection" from the instance configuration
     *
     * @return boolean
     */
    public function CheckConnection(): string
    {
        $result = $this->checkConnectionPrerequisites();
        if (!$this->isSuccess($result))
        {
            $errMsg = "Missing information to connect.";
            $this->SetStatus(IS_INACTIVE);
            $this->UpdateFormField("Result", "caption", $errMsg);
            return $this->wrapReturn(false, $errMsg);
        }

        // Wenn das lesen und schreiben klappt, liefern wir dem User noch Daten für den hier angelegten Raum
        $floorId = $this->ReadPropertyInteger("FloorID");
        $roomId  = $this->ReadPropertyInteger("RoomID");

        // Abfrage über Gateway - das Gateway versucht Daten zu bekommen und zu schreiben.
        $result = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::CHECK_CONNECTION,
            "FloorID" => $floorId,
            "RoomID"  => $roomId
        ]));

        // Da mehrere Abfragen durchgeführt werden, merken wir und den Stand zu jeder und geben beide Ergebnisse an den User aus.
        $outputText = "";
        $errMsg = "Please check Gateway. Error checking connection via IPS Controme Gateway for provided room id. " . "(Id: " . $roomId . ")";
        if ($this->isError($result))
        {
            $outputText .= $errMsg;
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $errMsg);
        }
        else {
            $outputText .= "Connection to Gateway and Controme Mini-Server is working!\n";
            $this->SendDebug(__FUNCTION__, $outputText, 0);
            $this->LogMessage($outputText, KL_MESSAGE);
        }

        // Anfrage ans Gateway schicken um die Temperaturdaten zu lesen.
        $result = $this->SendDataToParent(json_encode([
            "DataID"  => GUIDs::DATAFLOW, // die gemeinsame DataFlow-GUID
            "Action"  => ACTIONs::GET_TEMP_DATA_FOR_ROOM,
            "FloorID" => $floorId,
            "RoomID"  => $roomId
        ]));

        $errMsg = "Please check Gateway. Fetching data results in no response from gateway for provided room! " . "(Id: " . $roomId . ")";
        if ($this->isError($result))
        {
            $outputText .= $errMsg;
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $errMsg);
        }
        else {
            $outputText .= "Reading data for provided room returned results.\n";
            $this->SendDebug(__FUNCTION__, $outputText, 0);
            $this->LogMessage($outputText, KL_MESSAGE);
        }

        $data = json_decode($result, true);
        $msgSuffix = "";
        if (isset($data['name'])) {
            // Controme liefert Temperatur = null, wenn keine Ist-Temperatur vorhanden ist.
            // In dem Fall das Backup als Temperatur heranziehen oder "unbekannt" setzen.
            if (!isset($data['temperatur']) || is_null($data['temperature'])) {
                if ($this->GetValue('FallbackTempSensorUse'))
                {
                    if ($this->ReadPropertyInteger("FallbackTempSensor") > 0 && is_numeric(GetValue($this->ReadPropertyInteger("FallbackTempSensor"))))
                    {
                        $data['temperatur'] = floatval(GetValue($this->ReadPropertyInteger("FallbackTempSensor")));
                        $msgSuffix = ", taken from fallback variable";
                    }
                    else {
                        $data['temperatur'] = $this->ReadPropertyFloat("FallbackTempValue");
                        $msgSuffix = ", taken from fallback value";
                    }
                }
                else {
                    $data['temperatur'] = "n/a";
                }
            }
            // Bis hier ist alles gut :-)
            $msg = "Data for provided room found and data seems valid. (" . $data['name'] . " - " . $data['temperatur'] . " °C" . $msgSuffix . ".)";
            $this->SendDebug(__FUNCTION__, $msg, 0);
            $outputText .= $msg;
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->LogMessage($msg, KL_MESSAGE);
            $this->SetStatus(IS_ACTIVE);
            $this->saveDataToVariables($data);
            return $this->wrapReturn(true, $msg);
        }
        else {
            $errMsg = "Fetching data successfull, however no valid room or temperature data found for provided room! " . "(Id: " . $roomId . ")";
            $outputText .= $errMsg;
            $this->UpdateFormField("Result", "caption", $outputText);
            $this->SetStatus(IS_BAD_JSON);
            return $this->wrapReturn(false, $errMsg);
        }

    }

    // Funktion die zyklisch durch den Timer aufgerufen wird (wenn aktiv) und die Werte des Raums aktualisiert - hier keine User-Rückmeldungen!
    private function UpdateRoomData($testMode = false): string
    {
        $result = $this->checkConnectionPrerequisites();
        if (!$this->isSuccess($result))
        {
            $this->SetStatus(IS_INACTIVE);
            return $this->wrapReturn(false, "Missing information to connect.");
        }

        $roomId   = $this->ReadPropertyInteger("RoomID");
        $floorId  = $this->ReadPropertyInteger("FloorID");

        // Daten vom Gateway holen
        $result = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::GET_TEMP_DATA_FOR_ROOM,
            "RoomID" => $roomId,
            "FloorID"=> $floorId
        ]));

        if ($this->isError($result))
        {
            $errMsg = "Please check Gateway. Fetching data results in no response from gateway for provided room! " . "(Id: " . $roomId . ")";
            $this->UpdateFormField("Result", "caption", $errMsg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $errMsg);
        }

        // Dann werden wohl Daten angekommen sein:
        $data = json_decode($result, true);
        if (isset($data['name'])) {
            // Controme liefert Temperatur = null, wenn keine Ist-Temperatur vorhanden ist.
            // In dem Fall das Backup als Temperatur heranziehen oder "unbekannt" setzen.
            $msgSuffix = "";
            if (!isset($data['temperatur'])) {
                if ($this->GetValue('FallbackTempSensorUse'))
                {
                    if ($this->ReadPropertyInteger("FallbackTempSensor") > 0 && is_numeric(GetValue($this->ReadPropertyInteger("FallbackTempSensor"))))
                    {
                        $data['temperatur'] = floatval(GetValue($this->ReadPropertyInteger("FallbackTempSensor")));
                        $msgSuffix = ", taken from fallback variable";
                    }
                    else {
                        $data['temperatur'] = $this->ReadPropertyFloat("FallbackTempValue");
                        $msgSuffix = ", taken from fallback value";
                    }
                }
                else {
                    $data['temperatur'] = "n/a";
                }
            }

            $msg = "Fetching Data: Room $roomId found and data seems valid. (Returned room name \"" . $data['name'] . "\" with temperature " . $data['temperatur'] . " °C" . $msgSuffix . ")";
            $this->SendDebug(__FUNCTION__, $msg, 0);
            $this->LogMessage($msg, KL_MESSAGE);
            if ($testMode) {
                $this->UpdateFormField("ResultTestRead", "caption", $msg);
            }
        } else {
            $errMsg = "Fetching data successfull, however no valid room/temperature data found for provided room! " . "(Id: " . $roomId . ")";
            if ($testMode) {
                $this->UpdateFormField("ResultTestRead", "caption", $errMsg);
            }
            $this->SetStatus(IS_BAD_JSON);
            return $this->wrapReturn(false, $errMsg, $data);
        }

        // Alles ok - also können wir auch direkt die Daten in Variablen Speichern.
        $this->SetStatus(IS_ACTIVE);
        $this->saveDataToVariables($data);
        return $this->wrapReturn(true, "Room data successfully " . ($testMode ? "tested." : "updated."));
    }

    private function saveDataToVariables($data)
    {
        // Variablen anlegen und updaten
        $this->MaintainVariable("Temperature", "Actual Temperature", VARIABLETYPE_FLOAT, "~Temperature.Room", 1, true);
        $this->MaintainVariable("Setpoint", "Set Temperature", VARIABLETYPE_FLOAT, CONTROME_PROFILES::getSetPointPresentation(), 2, true);
        $this->MaintainVariable("Humidity", "Humidity", VARIABLETYPE_FLOAT, "~Humidity.F", 3, true);
        $this->MaintainVariable("Mode", "Operating Mode", VARIABLETYPE_INTEGER, CONTROME_PROFILES::BETRIEBSART, 4, true);
        //$this->EnableAction("Setpoint");

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
            $this->SetValue("Mode", CONTROME_PROFILES::getValueBetriebsart($data['betriebsart']));
        }
    }

    private function WriteSetpoint(float $value): string
    {
        $result = $this->checkConnectionPrerequisites();
        if (!$this->isSuccess($result))
        {
            $this->SetStatus(IS_INACTIVE);
            return $this->wrapReturn(false, "Missing information to connect.");
        }

        $roomId  = $this->ReadPropertyInteger("RoomID");
        $floorId = $this->ReadPropertyInteger("FloorID");

        $result = $this->SendDataToParent(json_encode([
            "DataID"   => GUIDs::DATAFLOW,
            "Action"   => ACTIONs::WRITE_SETPOINT,
            "FloorID"  => $floorId,
            "RoomID"   => $roomId,
            "Setpoint" => number_format($value, 2, '.', '') // immer mit 2 Nachkommastellen senden
        ]));

        $this->SendDebug(__FUNCTION__, 'Response: ' . print_r($result, true), 0);

        $errMsg = "Could not write setpoint for provided room! " . "(Id: " . $roomId . ")";
        if ($this->isError($result))
        {
            $this->SetStatus(IS_NO_CONNECTION);
            $errMsg .= $this->getResponseMessage($result);
            $this->SendDebug(__FUNCTION__, "Fehler beim Setzen der Solltemperatur: " . $errMsg, 0);
            $this->LogMessage("Setpoint-Fehler: " . $errMsg, KL_ERROR);
            throw new UserFriendlyException($errMsg);
        }

        $msg = "Solltemperatur erfolgreich gesetzt: " . $value . " °C";
        $this->SetValue("Setpoint", $value); // nur bei Erfolg lokal setzen
        $this->SetStatus(IS_ACTIVE);
        return $this->wrapReturn(true, "Setpoint set successfully.");
    }

    public function GetVisualizationTileCustom(): string
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

    private function checkConnectionPrerequisites()
    {
        $floorId = $this->ReadPropertyInteger("FloorID");
        $roomId  = $this->ReadPropertyInteger("RoomID");

        // Properties müssen gefüllt sein
        if (empty($floorId) || empty($roomId)) {
            $msg = "Floor id and/or room id missing!";
            $this->UpdateFormField("Result", "caption", $msg);
            $this->SetStatus(IS_NO_CONNECTION);
            return $this->wrapReturn(false, $msg);
        }

        // Validierung: Floor und Room müssen > 0 sein
        if ($floorId <= 0 || $roomId <= 0) {
            $msg = "Invalid floor id ($floorId) or room id ($roomId) (floor/room <= 0)!";
            $this->UpdateFormField("Result", "caption", $msg);
            $this->SetStatus(IS_INACTIVE);
            return $this->wrapReturn(false, $msg);
        }
        return $this->wrapReturn(true, "All params existing and valid.");
    }

}
