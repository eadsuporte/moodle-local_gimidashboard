<?php
namespace local_gimidashboard\plugininfo;


use core\plugininfo\base;

/**
 * Plugin info class for dashboard report subplugins.
 *
 * @package   local_gimidashboard
 */
class gimidashboardreports extends base {
    /**
     * Allows uninstalling report subplugins.
     *
     * @return bool
     */
    public function is_uninstall_allowed(): bool {
        return true;
    }
}
