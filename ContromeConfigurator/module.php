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
        $fetchRoomsSuccess = true;

        // Response ist ein JSON-String, prüfen ob Fehler (wrapped mit success=false)
        if ($this->isError($responseJson)) {
            $msg = $this->getResponseMessage($responseJson);
            $this->SendDebug(__FUNCTION__, "Could not fetch rooms from gateway: " . $msg . " Only displying Central Controls.", 0);
            $fetchRoomsSuccess = false;
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

        // 2. Central Control Instanzen - alle vom Gateway abfragen
        $ccInstances = $this->GetCentralControlInstances();
        $ccCount = 1;
        // Wenn bereits Central Controls existieren, diese anzeigen
        if (!empty($ccInstances)) {
            foreach ($ccInstances as $ccInstanceJson) {
                $ccInstance = json_decode($ccInstanceJson, true);
                $instanceID = $ccInstance['InstanceID'];
                // Aktuelle Konfiguration der Instanz holen
                $ccConfig = $this->GetConfigurationForCentralControl($instanceID);
                $values[] = [
                    'parent' => 1,
                    'name' => $ccInstance['name'],
                    'instanceID' => $instanceID,
                    'create' => [
                        'moduleID' => GUIDs::CENTRAL_CONTROL,
                        'configuration' => $ccConfig
                    ]
                ];
                $ccCount++;
            }
        }
        // Immer die Möglichkeit anbieten, eine neue zu erstellen (mit Defaults)
        $values[] = [
            'parent' => 1,
            'name' => 'Create new Controme Central Control',
            'InstanceID' => 0, // 0 = nicht vorhanden, immer erstellbar
            'create' => [
                'moduleID' => GUIDs::CENTRAL_CONTROL,
                'name' => 'Controme Central Control #' . $ccCount,
                'configuration' => $this->GetConfigurationForCentralControl(0)
            ]
        ];

        // 3. Room Thermostat Kategorie
        $values[] = [
            'id' => 2,
            'name' => 'Room Thermostats'
        ];

        // 4. Räume durchgehen und Instanzen anlegen
        if ($fetchRoomsSuccess){
            foreach ($roomsData as $etage) {
                if (!isset($etage['raeume']) || !is_array($etage['raeume'])) {
                    continue;
                }
                $floorId = $etage['id'] ?? 0;
                $floorName = $etage['etagenname'] ?? 'Unknown Floor';
                foreach ($etage['raeume'] as $raum) {
                    $roomId = $raum['id'] ?? 0;
                    // Prüfen, ob bereits eine Instanz für diesen Raum existiert
                    $instanceID = $this->GetRoomThermostatInstanceID($roomId);
                    // Aktuelle Konfiguration der Instanz holen (oder Defaults für neue Instanzen)
                    if ($instanceID === 0) {
                        $roomName = $floorName . ' / ' . $raum['name'] ?? 'Unknown Room';
                    } else {
                        $roomName = IPS_GetName($instanceID);
                    }
                    $rtConfig = $this->GetConfigurationForRoomThermostat($instanceID, $floorId, $floorName, $roomId, $roomName);
                    $values[] = [
                        'parent' => 2,
                        'name' => $roomName,
                        'FloorID' => $floorId,
                        'RoomID' => $roomId,
                        'instanceID' => $instanceID, // 0 = nicht vorhanden, >0 = bereits erstellt
                        'create' => [
                            'moduleID' => GUIDs::ROOM_THERMOSTAT,
                            'configuration' => $rtConfig
                        ]
                    ];
                }
            }
        } else {
            $values[] = [
                'parent' => 2,
                'name' => 'Error fetching rooms from Controme Mini-Server. Please check gateway.',
                'FloorID' => 0,
                'RoomID' => 0,
                'InstanceID' => 0 // 0 = nicht vorhanden, >0 = bereits erstellt
            ];
        }

        // Values in Configurator eintragen
        foreach ($form['elements'] as &$element) {
            if ($element['type'] === 'Configurator' && $element['name'] === 'ContromeConfiguratorRTs') {
                $element['values'] = $values;
                break;
            }
        }
        $this->SendDebug(__FUNCTION__, "Form: " . print_r($form, true));
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
        parent::RequestAction($ident, $value);
    }

    /**
     * Holt die aktuelle Konfiguration einer Central Control Instanz
     *
     * @param int $instanceID Die Instanz-ID (0 für neue Instanz = Defaults)
     * @return array Assoziatives Array mit allen Konfigurationswerten
     */
    private function GetConfigurationForCentralControl(int $instanceID): array
    {
        if ($instanceID === 0 || !IPS_InstanceExists($instanceID)) {
            // Defaults zurückgeben für neue Instanzen
            return [
                'ShowMainElements' => true,
                'AllowChangeOfMode' => true,
                'AllowChangeOfPermanentTemperature' => true,
                'AllowChangeOfTemporaryTemperature' => true,
                'VisuColorMainTiles' => 0x454545,
                'ShowSystemInfo' => true,
                'VisuColorSystemInfoTile' => 0x696e96,
                'ShowRooms' => true,
                'VisuColorRoomTiles' => 0x5c5c5c,
                'VisuColorFloorTiles' => 0x454545,
                'ShowRoomData' => true,
                'ShowRoomOffsets' => false,
                'ShowRoomOffsetsOnlyActive' => false,
                'ShowRoomSensors' => false,
                'ShowVTR' => false,
                'ShowTimer' => false,
                'ShowCalendar' => false,
                'DurationOfMessagePopup' => 8,
                'VisuColorText' => 0xFFFFFF,
                'VisuColorModeButton' => 0x00a9f4,
                'VisuColorTempButtons' => 0xfb4f2a,
                'UpdateInterval' => 5,
                'AutoUpdate' => true,
                'RoomID' => 1
            ];
        }

        // Aktuelle Konfiguration der existierenden Instanz auslesen
        $configJson = IPS_GetConfiguration($instanceID);
        return json_decode($configJson, true);
    }

    /**
     * Holt die aktuelle Konfiguration einer Room Thermostat Instanz
     *
     * @param int $instanceID Die Instanz-ID (0 für neue Instanz = Defaults)
     * @param int $floorID Die Etagen-ID (nur für neue Instanzen)
     * @param string $floorName Der Etagen-Name (nur für neue Instanzen)
     * @param int $roomID Die Raum-ID (nur für neue Instanzen)
     * @param string $roomName Der Raum-Name (nur für neue Instanzen)
     * @return array Assoziatives Array mit allen Konfigurationswerten
     */
    private function GetConfigurationForRoomThermostat(int $instanceID, int $floorID = 0, string $floorName = '', int $roomID = 0, string $roomName = ''): array
    {
        if ($instanceID === 0 || !IPS_InstanceExists($instanceID)) {
            // Defaults zurückgeben für neue Instanzen
            return [
                'FloorID' => $floorID,
                'Floor' => $floorName,
                'RoomID' => $roomID,
                'Room' => $roomName,
                'FallbackTempSensorUse' => false,
                'FallbackTempSensor' => 0,
                'FallbackTempValue' => 15.0,
                'FallbackHumiditySensorUse' => false,
                'FallbackHumiditySensor' => 0,
                'FallbackHumidityValue' => 40.0,
                'UpdateInterval' => 5,
                'AutoUpdate' => true,
                'StepSize' => 0.5
            ];
        }

        // Aktuelle Konfiguration der existierenden Instanz auslesen
        $configJson = IPS_GetConfiguration($instanceID);
        return json_decode($configJson, true);
    }

    /**
     * Holt alle Central Control Instanzen vom Gateway
     * Fragt das Gateway nach allen seinen Central Control Child-Instanzen
     *
     * @return array Array mit Instanzen [{"InstanceID": X, "Name": "..."}, ...] oder leeres Array
     */
    private function GetCentralControlInstances(): array
    {
        // Anfrage an das Gateway senden
        $response = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::GET_CENTRAL_CONTROL_INSTANCES
        ]));
        // Fehlerprüfung
        if ($response === false) {
            $this->SendDebug(__FUNCTION__, "No parent gateway configured", 0);
            return [];
        }
        // JSON dekodieren
        $instances = json_decode($response, true);
        if (!is_array($instances)) {
            $this->SendDebug(__FUNCTION__, "Invalid response from gateway", 0);
            return [];
        }
        return $instances;
    }

    /**
     * Sucht nach einer existierenden Room Thermostat Instanz für die gegebene RoomID
     * Fragt das Gateway nach allen seinen Child-Instanzen
     *
     * @param int $roomId Die Controme RoomID
     * @return int Die Instanz-ID (0 wenn nicht gefunden)
     */
    private function GetRoomThermostatInstanceID(int $roomId): int
    {
        // Anfrage an das Gateway senden
        $response = $this->SendDataToParent(json_encode([
            "DataID" => GUIDs::DATAFLOW,
            "Action" => ACTIONs::GET_ROOM_THERMOSTAT_INSTANCES
        ]));
        // Fehlerprüfung
        if ($response === false) {
            $this->SendDebug(__FUNCTION__, "No parent gateway configured", 0);
            return 0;
        }
        // JSON dekodieren
        $instances = json_decode($response, true);
        if (!is_array($instances)) {
            $this->SendDebug(__FUNCTION__, "Invalid response from gateway", 0);
            return 0;
        }
        // Nach RoomID suchen
        foreach ($instances as $instanceJson) {
            $instance = json_decode($instanceJson, true);
            if (isset($instance['RoomID']) && (int)$instance['RoomID'] === $roomId) {
                return (int)$instance['InstanceID'];
            }
        }
        // Keine passende Instanz gefunden
        return 0;
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
