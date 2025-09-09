<?php
// General functions
require_once __DIR__ . '/../libs/_traits.php';

// IPS-Stubs nur in der Entwicklungsumgebung laden
if (substr(__DIR__,0, 10) == "/Users/kai") {
    // Development
    include_once __DIR__ . '/../.ips_stubs/autoload.php';
}
class ContromeHeatingControl extends IPSModuleStrict
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

        // Timer für zyklische Abfrage (alle 5 Minuten)
        $this->RegisterTimer("UpdateContromeData", 5 * 60 * 1000, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", true);');
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
            case "UpdateData":
                $this->UpdateData();
                break;
            case "CheckConnection":
                $this->CheckConnection();
                break;
            case "ReadHeatingInstances":
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

        $url = "http://$ip/get/json/v1/1/temps/";
        $opts = [
            "http" => [
                "header" => "Authorization: Basic " . base64_encode("$user:$pass")
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);

        if ($json === FALSE) {
            $this->SendDebug("CheckConnection", "Fehler beim Abrufen von $url", 0);
            $this->UpdateFormField("Result", "caption", "No connection.");
            return false;
        }

        $data = json_decode($json, true);
        if ($data === null) {
            $this->SendDebug("CheckConnection", "Fehler beim JSON-Decode", 0);
            $this->UpdateFormField("Result", "caption", "Error: JSON-Decode - please contact developer.");
            return false;
        }
        $this->SendDebug("CheckConnection", "Success - connection established for user " . $user . "!");
        $this->UpdateFormField("Result", "caption", "Success - connection established for user " . $user . "!");
        return true;
    }

    /**
     * Connects to the Controme MiniServer and reads the current values
     * Upon success returns true
     *
     * @return boolean
     *
    */
    public function ReadHeatingInstances(): bool
    {
        $ip   = $this->ReadPropertyString("IPAddress");
        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        if (empty($ip) || empty($user) || empty($pass)) {
            $this->SendDebug("ReadHeatingInstances", "IP, User oder Passwort nicht gesetzt!", 0);
            return false;
        }

        $url = "http://$ip/get/json/v1/1/temps/";
        $opts = [
            "http" => [
                "header" => "Authorization: Basic " . base64_encode("$user:$pass")
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);

        if ($json === FALSE) {
            $this->SendDebug("ReadHeatingInstances", "Fehler beim Abrufen von $url", 0);
            return false;
        }

        $data = json_decode($json, true);
        if ($data === null) {
            $this->SendDebug("ReadHeatingInstances", "Fehler beim JSON-Decode", 0);
            return false;
        }

        $heatingInstances = [];
        foreach ($data as $etage) {
            if (!isset($etage['raeume']) || !is_array($etage['raeume'])) continue;
            foreach ($etage['raeume'] as $raum) {
                $raumName = $raum['name'] ?? "Raum";
                $raumId   = $raum['id'] ?? uniqid();
                $heatingInstances[] = [
                    'id' => $raumId,
                    'name' => $raumName
                ];
            }
        }

        $this->SendDebug("ReadHeatingInstances", "Gefundene Instanzen: " . print_r($heatingInstances, true), 0);
        return true;
    }

    // Button-Action oder Timer-Action
    public function UpdateData()
    {
        $ip   = $this->ReadPropertyString("IPAddress");
        $user = $this->ReadPropertyString("User");
        $pass = $this->ReadPropertyString("Password");

        if (empty($ip) || empty($user) || empty($pass)) {
            $this->SendDebug("UpdateData", "IP, User oder Passwort nicht gesetzt!", 0);
            return;
        }

        $url = "http://$ip/get/json/v1/1/temps/";
        $opts = [
            "http" => [
                "header" => "Authorization: Basic " . base64_encode("$user:$pass")
            ]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);

        if ($json === FALSE) {
            $this->SendDebug("UpdateData", "Fehler beim Abrufen von $url", 0);
            return;
        }

        $data = json_decode($json, true);
        if ($data === null) {
            $this->SendDebug("UpdateData", "Fehler beim JSON-Decode", 0);
            return;
        }
        $text = print_r($data, true);
        $this->SendDebug("UpdateData", "Input: " . $text, 0);
        // Räume durchgehen
        foreach ($data as $etage) {
            if (!isset($etage['raeume']) || !is_array($etage['raeume'])) continue;

            foreach ($etage['raeume'] as $raum) {
                $raumName = $raum['name'] ?? "Raum";
                $raumId   = $raum['id'] ?? uniqid();

                // Kategorie für Raum
                $catID = $this->GetOrCreateCategory("raum_" . $raumId, $raumName, $this->InstanceID);

                // Variablen anlegen
                $istTempID  = $this->GetOrCreateVariable("currentTemp", "Ist-Temperatur", "~Temperature.Room", $catID, 2);
                $sollTempID = $this->GetOrCreateVariable("targertTemp", "Soll-Temperatur", "~Temperature.Room", $catID, 2);
                $humID      = $this->GetOrCreateVariable("humidity", "Luftfeuchtigkeit", "~Humidity.F", $catID, 2);
                $modeID     = $this->GetOrCreateVariable("operationMode", "Betriebsart", "Controme.Betriebsart", $catID, 1);

                // Werte setzen
                if (isset($raum['temperatur']))     SetValue($istTempID, floatval($raum['temperatur']));
                if (isset($raum['solltemperatur'])) SetValue($sollTempID, floatval($raum['solltemperatur']));
                if (isset($raum['luftfeuchte']))    SetValue($humID, floatval($raum['luftfeuchte']));

                if (isset($raum['betriebsart'])) {
                    $modeMap = [
                        "Cooling" => 0,
                        "Off"     => 1,
                        "Heating" => 2,
                        "On"      => 3
                    ];
                    if (isset($modeMap[$raum['betriebsart']])) {
                        SetValue($modeID, $modeMap[$raum['betriebsart']]);
                    }
                }

                // Sensoren
                if (isset($raum['sensoren']) && is_array($raum['sensoren'])) {
                    $sensCatID = $this->GetOrCreateCategory("sensoren", "Sensoren", $catID);
                    foreach ($raum['sensoren'] as $sensor) {
                        $sName = $sensor['beschreibung'] != "" ? $sensor['beschreibung'] : $sensor['name'];
                        $sIdent = "sensor_" . preg_replace('/[^a-zA-Z0-9_]/', '_', $sensor['name']);
                        $sVarID = $this->GetOrCreateVariable($sIdent, $sName, "~Temperature.Room", $sensCatID, 2);
                        if (isset($sensor['wert'])) {
                            SetValue($sVarID, floatval($sensor['wert']));
                        }
                    }
                }
            }
        }
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
