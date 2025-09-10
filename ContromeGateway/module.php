<?php
// General functions
require_once __DIR__ . '/../libs/_traits.php';

// IPS-Stubs nur in der Entwicklungsumgebung laden
if (substr(__DIR__,0, 10) == "/Users/kai") {
    // Development
    include_once __DIR__ . '/../.ips_stubs/autoload.php';
}
class ContromeGateway extends IPSModuleStrict
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
        // Never delete this line!
        parent::Create();

        // Konfigurationselemente
        $this->RegisterPropertyString("IPAddress", "");
        $this->RegisterPropertyString("User", "");
        $this->RegisterPropertyString("Password", "");
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
            case "FetchRoomList":
                $this->FetchRoomList();
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
        $this->ApplyChanges();

        $ip   = $this->ReadPropertyString("IPAddress");
        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        if (empty($ip) || empty($user) || empty($pass)) {
            $this->SendDebug("CheckConnection", "IP, User oder Passwort nicht gesetzt!", 0);
            $this->UpdateFormField("Result", "caption", "Please set all 3 parameters (username, password and device IP).");
            return false;
        }

        $url = "http://$ip/get/json/v1/1/rooms/";
        $opts = [
            "http" => [
                "header" => "Authorization: Basic " . base64_encode("$user:$pass")
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);

        if ($json === FALSE) {
            $this->SendDebug("CheckConnection", "Fehler beim Zugriff", 0);
            $this->LogMessage("No connection to Controme MiniServer at $ip - please check IP, username and password!", KL_ERROR);
            $this->UpdateFormField("Result", "caption", "No connection.");
            return false;
        }

        $data = json_decode($json, true);
        if ($data === null) {
            $this->SendDebug("CheckConnection", "Fehler beim JSON-Decode", 0);
            $this->LogMessage("CONTROMEHC - JSON Error", KL_ERROR);
            $this->UpdateFormField("Result", "caption", "Error: JSON-Decode - please contact developer.");
            return false;
        }
        $this->SendDebug("CheckConnection", "Success - connection established for user " . $user . "!");
        $this->LogMessage("CONTROMEHC - Successfully established connection.for user " . $user . "!", KL_NOTIFY);
        $this->UpdateFormField("Result", "caption", "Success - connection established for user " . $user . "!");
        return true;
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

        $url = "http://$ip/get/json/v1/1/rooms/";
        $opts = [
            "http" => [
                "header" => "Authorization: Basic " . base64_encode("$user:$pass")
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);

        if ($json === FALSE) {
            $this->SendDebug("FetchRoomList", "Fehler beim Abrufen von $url", 0);
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
}
