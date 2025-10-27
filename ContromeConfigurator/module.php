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

        // Verbindung zum Gateway herstellen
        $this->RequireParent(GUIDs::GATEWAY);

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
     * Erstellt das Konfigurationsformular dynamisch
     *
     * @return string JSON-String des Formulars
     */
    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Raumliste vom Gateway abrufen (gibt JSON-String zurück)
        $responseJson = $this->FetchRoomsFromGateway();

        // Response ist ein JSON-String, prüfen ob Fehler (wrapped mit success=false)
        if ($this->isError($responseJson)) {
            $msg = $this->getResponseMessage($responseJson);
            $this->SendDebug(__FUNCTION__, "Could not fetch rooms from gateway: " . $msg, 0);
            return json_encode($form);
        }

        // JSON dekodieren - wenn isError false ist, sind die Rohdaten direkt im JSON
        $roomsData = json_decode($responseJson, true);
        if (!is_array($roomsData)) {
            $this->SendDebug(__FUNCTION__, "Invalid room data format", 0);
            return json_encode($form);
        }

        // Configurator values aufbauen
        $values = [];

        // 1. Central Control Kategorie
        $values[] = [
            'id' => 1,
            'name' => 'Central Control'
        ];

        // 2. Central Control Instance
        $values[] = [
            'parent' => 1,
            'name' => 'Create new Controme Central Control',
            'create' => [
                'moduleID' => GUIDs::CENTRAL_CONTROL,
                'configuration' => []
            ]
        ];

        // 3. Room Thermostat Kategorie
        $values[] = [
            'id' => 2,
            'name' => 'Room Thermostats'
        ];

        // 4. Räume durchgehen und Instanzen anlegen
        foreach ($roomsData as $etage) {
            if (!isset($etage['raeume']) || !is_array($etage['raeume'])) {
                continue;
            }

            $floorId = $etage['id'] ?? 0;
            $floorName = $etage['etagenname'] ?? 'Unknown Floor';

            foreach ($etage['raeume'] as $raum) {
                $roomId = $raum['id'] ?? 0;
                $roomName = $raum['name'] ?? 'Unknown Room';

                $values[] = [
                    'parent' => 2,
                    'name' => $floorName . ' (Floor-ID: ' . $floorId . ') / ' . $roomName . ' (Room-ID: ' . $roomId . ')',
                    'create' => [
                        'moduleID' => GUIDs::ROOM_THERMOSTAT,
                        'configuration' => [
                            'RoomID' => $roomId,
                            'FloorID' => $floorId,
                            'Floor' => $floorName,
                            'Room' => $roomName
                        ]
                    ]
                ];
            }
        }

        // Values in Configurator eintragen
        foreach ($form['elements'] as &$element) {
            if ($element['type'] === 'Configurator' && $element['name'] === 'ContromeConfiguratorRTs') {
                $element['values'] = $values;
                break;
            }
        }

        return json_encode($form);
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

    /**
     * Holt die Raumliste vom Gateway über SendDataToParent
     *
     * @return string JSON-String mit Raumdaten oder Fehler-Wrapper
     */
    private function FetchRoomsFromGateway(): string
    {
        // Anfrage an das Gateway senden - nutzt die dedizierte Konfigurator-Action
        $response = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::GET_ROOMS_FOR_CONFIGURATOR
        ]));

        // SendDataToParent gibt false zurück, wenn kein Parent vorhanden ist
        if ($response === false) {
            $this->SendDebug(__FUNCTION__, "No parent gateway configured", 0);
            return $this->wrapReturn(false, "No parent gateway configured. Please connect this configurator to a Controme Gateway instance.");
        }

        if ($this->isError($response)) {
            $this->SendDebug(__FUNCTION__, "Error fetching rooms from gateway: " . $this->getResponseMessage($response), 0);
            return $this->wrapReturn(false, "Error fetching rooms from gateway.", $response);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->SendDebug(__FUNCTION__, "Invalid room data in gateway response", 0);
            return $this->wrapReturn(false, "Invalid room data in gateway response", $response);
        }

        return $response;
    }

}
