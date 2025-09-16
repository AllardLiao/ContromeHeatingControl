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

    // Abzufragende Daten vom Gateway
    public const DATA_SYSTEM_INFO   = 'info';
    public const DATA_ROOMS         = 'rooms';
    public const DATA_ROOM_OFFSETS  = 'roomoffsets';
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
    public const GET_ROOMS          = "rooms/";
    public const GET_ROOM_OFFSETS   = "roomoffsets/";
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

    public const SETPOINT = 'Controme.Setpoint';
    private static String $setPointPresentation = "[
        'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
        'SUFFIX' => '°C',
        'MIN' => 15.0,
        'MAX' => 28.0,
        'STEP_SIZE' => 0.5,
        'GRADIENT_TYPE' => 1, //Temperatur
        'USAGE_TYPE' => 0, // Standard
        'DIGITS' => 1,
        'ICON' => 'Temperature'
    ]";

    public static function registerProfile(string $profile)
    {
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
        }
    }

    public static function getSetPointPresentation(): string
    {
        //return self::$setPointPresentation;
        return "~Temperature";
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
