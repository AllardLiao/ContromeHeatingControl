<?php

/**
 * VersionHelper.php
 *
 * Part of the Trait-Libraray for IP-Symcon Modules.
 *
 * @package       traits
 * @author        Heiko Wilknitz <heiko@wilkware.de>
 * @copyright     2025 Heiko Wilknitz
 * @link          https://wilkware.de
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 */

declare(strict_types=1);

/**
 * Helper class for extract verion infos.
 */
trait VersionHelper
{
    /**
     * Modul GUID "Tile Visualisation"
     *
     * @var string
     */
    private static $GUID_TILE_VISU = '{}';

    /**
     * Modul GUID "WebFront Visualisation"
     *
     * @var string
     */
    private static $GUID_WEBFRONT_VISU = '{}';

    /**
     * IsTileVisuSupported.
     *
     * @return bool TRUE if version > 7.x, otherwise FALSE.
     */
    protected function IsTileVisuSupported(): bool
    {
        // Version check
        $version = (float) IPS_GetKernelVersion();
        if ($version < 7) {
            return false;
        }
        return true;
    }

    /**
     * Check, if given instance id of type tile visu
     *
     * @param int $iid Instance ID.
     * @return bool TRUE if match the desired type, otherwise FALSE.
     */
    protected function IsTileVisuInstance(int $iid): bool
    {
        // Check if the instance exists
        if (IPS_InstanceExists($iid)) {
            // Get the instance information
            $instance = IPS_GetInstance($iid);
            // Check if the ModuleID of the instance matches the desired ModuleID
            if ($instance['ModuleInfo']['ModuleID'] === self::$GUID_TILE_VISU) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check, if given instance id of type webfront visu
     *
     * @param int $iid Instance ID.
     * @return bool TRUE if match the desired type, otherwise FALSE.
     */
    protected function IsWebFrontVisuInstance(int $iid): bool
    {
        // Check if the instance exists
        if (IPS_InstanceExists($iid)) {
            // Get the instance information
            $instance = IPS_GetInstance($iid);
            // Check if the ModuleID of the instance matches the desired ModuleID
            if ($instance['ModuleInfo']['ModuleID'] === self::$GUID_WEBFRONT_VISU) {
                return true;
            }
        }
        return false;
    }
}
