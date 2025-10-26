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

class ContromeConfigurator extends IPSModuleStrict
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
        // Never delete this line!
        parent::Create();

        // Konfigurationselemente
        $this->RegisterPropertyString("Rooms", "[]"); // gem. Controme-API: get-rooms
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
        switch($ident) {
            case ACTIONs::FETCH_ROOM_LIST:
                $this->SetRoomList(); // Räume abrufen und im Konfig-Form speichern
                break;
            case ACTIONs::CHECK_CONNECTION:
                $this->CheckConnection($value);
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

        $msg = $this->Translate("Central Control created with name ") . "'$instanceName' (ID $newId)!";
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
            $msg = $this->Translate("Room thermostat instance created with name ") . "'$floorName-$roomName' (ID: $instanceId)!";
            $this->UpdateFormField("InstanceCreationResult", "caption", $msg);
            return $this->wrapReturn(true, $msg, $instanceId);
        } catch (Exception $e) {
            $msg = $this->Translate("Error creating instance: ") . $e->getMessage();
            $this->UpdateFormField("InstanceCreationResult", "caption", $msg);
            return $this->wrapReturn(false, $msg);
        }
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
