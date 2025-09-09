<?php
require_once __DIR__ . "/../libs/_ipsmodule.php";
// General functions
require_once __DIR__ . '/../libs/_traits.php';

class ContromeHeatingControl extends IPSModuleStrict
{
    use DebugHelper;
    use EventHelper;
    use ProfileHelper;
    use VariableHelper;
    use VersionHelper;
    use FormatHelper;
    use WidgetHelper;

    public function Create()
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

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
    }


    public function ApplyChanges()
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
    public function RequestAction($ident, $value){
        switch($ident) {
            case "UpdateData":
                $this->UpdateData();
                break;
            default:
                throw new Exception("Invalid ident");
        }
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

        // Räume durchgehen
        foreach ($data as $etage) {
            if (!isset($etage['raeume']) || !is_array($etage['raeume'])) continue;

            foreach ($etage['raeume'] as $raum) {
                $raumName = $raum['name'] ?? "Raum";
                $raumId   = $raum['id'] ?? uniqid();

                // Kategorie für Raum
                $catID = $this->GetOrCreateCategory("raum_" . $raumId, $raumName, 0);

                // Variablen anlegen
                $istTempID  = $this->GetOrCreateVariable("ist", "Ist-Temperatur", "~Temperature.Room", $catID, 2);
                $sollTempID = $this->GetOrCreateVariable("soll", "Soll-Temperatur", "~Temperature.Room", $catID, 2);
                $humID      = $this->GetOrCreateVariable("feuchte", "Luftfeuchtigkeit", "~Humidity.F", $catID, 2);
                $modeID     = $this->GetOrCreateVariable("betriebsart", "Betriebsart", "Controme.Betriebsart", $catID, 1);

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
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
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
        $id = @IPS_GetObjectIDByIdent($ident, $parentID);
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
