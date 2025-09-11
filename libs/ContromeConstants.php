<?php
declare(strict_types=1);

namespace Controme;

class GUIDs
{
    // Gemeinsame DataFlow-GUID für Gateway <-> Child
    public const DATAFLOW = '{ED578E4B-01FB-EFD4-6C72-6FF4A4633AD5}';

    // --- Modul GUIDs (Instanzen) ---
    public const LIBRARY              = '{89077CE1-F783-DE03-9291-0EEF49585535}'; // deine library.json
    public const GATEWAY              = '{E2DEC3C5-AA7D-0310-69A8-77F429D8C526}';
    public const CENTRAL_CONTROL      = '{A19ABE82-5AB1-7969-3851-E6446DECEBA9}';
    public const ROOM_THERMOSTAT      = '{E73194C2-C9BC-D3A5-4EED-CE5DF055290E}';

    // --- Weitere GUIDs ---
    public const PROFILE_BETRIEBSART  = '{16B16C23-64B7-26D3-6BE9-9B9E43AD491B}';
}

class ACTIONs
{
    public const CHECK_CONNECTION           = 'CheckConnection';
    public const GET_TEMP_DATA_FOR_ROOM     = 'GetTempDataForRoom';
    public const WRITE_SET_SETPOINT         = 'SetSetpoint';
    public const UPDATE_ROOM_DATA           = 'UpdateRoomData';
}
