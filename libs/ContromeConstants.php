<?php
declare(strict_types=1);

namespace Controme;

class GUIDs
{
    // Gemeinsame DataFlow-GUID für Gateway <-> Child
    public const DATAFLOW             = '{ED578E4B-01FB-EFD4-6C72-6FF4A4633AD5}';

    // --- Modul GUIDs (Instanzen) ---
    public const LIBRARY              = '{89077CE1-F783-DE03-9291-0EEF49585535}';
    public const GATEWAY              = '{E2DEC3C5-AA7D-0310-69A8-77F429D8C526}';
    public const CENTRAL_CONTROL      = '{A19ABE82-5AB1-7969-3851-E6446DECEBA9}';
    public const ROOM_THERMOSTAT      = '{E73194C2-C9BC-D3A5-4EED-CE5DF055290E}';

    // --- Weitere GUIDs ---
    public const PROFILE_BETRIEBSART  = '{16B16C23-64B7-26D3-6BE9-9B9E43AD491B}';
    public const PROFILE_SETPOINT_PRESENTATION_ID       = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}'; // IPS build-in for room temperature -> https://www.symcon.de/en/service/documentation/developer-area/sdk-tools/sdk-php/constants/
    public const PROFILE_SETPOINT_TEMPLATE_ID           = '{868B087E-A38D-2155-EBE0-157AFBBF9E8C}'; // IPS build-in for room temperatur

}

class ACTIONs
{
    // Implementierte Actions (alle Module)
    public const CHECK_CONNECTION                   = 'CheckConnection';
    public const GET_TEMP_DATA_FOR_ROOM             = 'GetTempDataForRoom';
    public const GET_DATA_FOR_CENTRAL_CONTROL       = 'GetDataForCentralControl';
    public const WRITE_SETPOINT                     = 'Setpoint';
    public const UPDATE_DATA                        = 'UpdateData';
    public const UPDATE_ROOM_DATA                   = 'UpdateRoomData';
    public const FETCH_ROOM_LIST                    = "FetchRoomList";
    public const CREATE_CENTRAL_CONTROL_INSTANCE    = "CreateCentralControlInstance";
    public const CREATE_ROOM_THERMOSTAT_INSTANCE    = "CreateRoomThermostatInstance";
    public const TEST_READ_ROOM_DATA                = "TestReadRoomData";
    public const GET_IP_ADDRESS                     = "GetIPAddress";

    // Implementierte Visu-Actions
    public const VISU_CC_SETPOINT                   = 'visu_CC_Setpoint';
    public const VISU_CC_TEMPERATURE                = 'visu_CC_Temperature';
    public const VISU_CC_MODE                       = 'visu_CC_Mode';
    public const VISU_RT_SETPOINT                   = 'visu_RT_setpoint';
    public const VISU_RT_INC                        = 'visu_RT_inc';
    public const VISU_RT_DEC                        = 'visu_RT_dec';

    // Implementierte Form-Actions
    public const FORM_RT_TOGGLEAUTOUPDATE             = 'form_rt_toggleAutoUpdate';
    public const FORM_RT_TOGGLEFALLBACKTEMPSENSOR     = 'form_rt_toggleFallbackTempSensor';
    public const FORM_RT_TOGGLEFALLBACKHUMIDITYSENSOR = 'form_rt_toggleFallbackHumiditySensor';

    // Abzufragende Daten vom Gateway
    public const DATA_SYSTEM_INFO   = 'info';
    public const DATA_ROOMS         = 'rooms';
    public const DATA_ROOM_OFFSETS  = 'roomoffsets';
    public const DATA_ROOM_SENSORS  = 'sensors';
    public const DATA_TEMPERATURS   = 'temps';
    public const DATA_VTR           = 'vtr';
    public const DATA_TIMER         = 'timer';
    public const DATA_CALENDAR      = 'calendar';
}

// Unterstützte API Befehle
// Dies sind die ergänzenden Zugriffspfade für die in der API implementierten Methoden
// und werden bei Datenabfragen in der url hinten angehängt.
// https://support.controme.com/api/
class CONTROME_API
{
    public const GET_SYSTEM_INFO    = "info/";
    public const GET_ROOMS          = "rooms/"; // wird in temps/ mitgeliefert
    public const GET_ROOM_OFFSETS   = "roomoffsets/"; // wird in temps/ mitgeliefert
    public const GET_TEMPERATURS    = "temps/";
    public const GET_VTR            = "vtr/";
    public const GET_TIMER          = "timer/";
    public const GET_CALENDAR       = "jahreskalender/";

    public const SET_SETPOINT       = "soll/";
    public const SET_SETPOINT_TEMP  = "ziel/";
    public const SET_OPERATION_MODE = "rooms/";

    // Betriebsarten Mapping API <-> IPS - siehe CONTROME_PROFILES::$betriebsartMap
    private static array $betriebsartMapAPI = [
        0 => "cooling",
        1 => "off",
        2 => "heating",
        3 => "on"
    ];

}

class CONTROME_PROFILES
{
    public const BETRIEBSART = 'Controme.Betriebsart';
    public static array $betriebsartMap = [
        0 => "Kühlen (Auto)",
        1 => "Dauer-Aus",
        2 => "Heizen (Auto)",
        3 => "Dauer-Ein"
    ];

    //public const SETPOINT = 'Controme.Setpoint'; // IPS build-in room temperature - see GUIDs

    public static functiOn registerAllContromeProfilesAndTemplates()
    {
        CONTROME_PROFILES::registerProfile(CONTROME_PROFILES::BETRIEBSART);
    }

    public static function registerProfile(string $profile)
    {
        // Variablenprofile werden anhand des Namens identifiziert, Präsentationen anhand der GUID!
        switch ($profile)
        {
            case self::BETRIEBSART:
                if (!IPS_VariableProfileExists($profile)) {
                    IPS_CreateVariableProfile($profile, 1); // 1 = Integer
                    IPS_SetVariableProfileIcon($profile, "Gear");
                    foreach (self::$betriebsartMap as $val => $text) {
                        IPS_SetVariableProfileAssociation($profile, $val, $text, "", -1);
                    }
                }
                break;
        }
    }

    public static function getSetPointPresentation(): array|string
    {
        return "~Temperature.Room";
        return [
            'PresentationID' => GUIDs::PROFILE_SETPOINT_PRESENTATION_ID,
            'TemplateID' => GUIDs::PROFILE_SETPOINT_TEMPLATE_ID
        ];
    }

    public static function getLabelBetriebsart(int $value): string
    {
        if (array_key_exists($value, self::$betriebsartMap)) {
            return self::$betriebsartMap[$value];
        }
        return "Unbekannt ($value)";
    }

    public static function getValueBetriebsart(string $label): ?int
    {
        $index = array_search($label, self::$betriebsartMap, true);
        return ($index !== false) ? $index : null;
    }

}
