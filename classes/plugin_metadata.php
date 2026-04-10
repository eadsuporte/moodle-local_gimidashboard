<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Plugin metadata helper.
 *
 * @package   local_gimidashboard
 */

namespace local_gimidashboard;

/**
 * Reads plugin release information from version.php files.
 *
 * @package   local_gimidashboard
 */
class plugin_metadata {
    /**
     * Returns the Academy Dashboard release label.
     *
     * @return string
     */
    public static function get_main_release(): string {
        return self::read_release(__DIR__ . '/../version.php', 'v2');
    }

    /**
     * Returns a report subplugin release label.
     *
     * @param string $name Subplugin name.
     * @return string
     */
    public static function get_report_release(string $name): string {
        return self::read_release(__DIR__ . '/../reports/' . $name . '/version.php', 'report');
    }

    /**
     * Reads the release string from a version.php file.
     *
     * @param string $path Version file path.
     * @param string $fallback Fallback label.
     * @return string
     */
    protected static function read_release(string $path, string $fallback = ''): string {
        if (!is_readable($path)) {
            return $fallback;
        }

        $plugin = new \stdClass();
        include($path);

        if (!empty($plugin->release)) {
            return (string) $plugin->release;
        }

        return $fallback;
    }
}
