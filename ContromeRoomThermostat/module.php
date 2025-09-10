<?php
// General functions
require_once __DIR__ . '/../libs/_traits.php';

// IPS-Stubs nur in der Entwicklungsumgebung laden
if (substr(__DIR__,0, 10) == "/Users/kai") {
    // Development
    include_once __DIR__ . '/../.ips_stubs/autoload.php';
}

declare(strict_types=1);
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

        // Konfigurationselemente der Tile-Visualisierung

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
            $this->UpdateFormField("Result", "caption", "Please set all 4 parameters (username, password and device IP).");
            return false;
        }

        if (empty($floor) || empty($room)) {
            $this->SendDebug("CheckConnection", "Missing Floor and Room.", 0);
            $this->UpdateFormField("Result", "caption", "Please set all 4 parameters (username, password and device IP).");
            $this->LogMessage("UpdateContromeDataRoomID" . $this->InstanceID . " CheckConnection: floor and room names missing. Fetching from I/O Socket upon next update.", KL_NOTIFY);
            return false;
        }

        return true;
    }
}
