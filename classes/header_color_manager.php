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
 * Header color manager.
 *
 * @package   local_gimidashboard
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard;

/**
 * Manages per-report header colors.
 *
 * @package   local_gimidashboard
 */
class header_color_manager {
    /** @var string Default header base color. */
    protected const DEFAULT_BASE_COLOR = '#0f4c81';

    /**
     * Returns the configured base color for a report component.
     *
     * @param string $component Report component name.
     * @return string
     * @throws \dml_exception
     */
    public static function get_base_color(string $component): string {
        $configname = self::get_config_name($component);
        $configured = get_config('local_gimidashboard', $configname);
        return self::normalize_hex_color((string) $configured) ?: self::DEFAULT_BASE_COLOR;
    }

    /**
     * Stores the configured base color for a report component.
     *
     * @param string $component Report component name.
     * @param string $color Base color.
     * @return void
     */
    public static function set_base_color(string $component, string $color): void {
        $normalized = self::normalize_hex_color($color) ?: self::DEFAULT_BASE_COLOR;
        set_config(self::get_config_name($component), $normalized, 'local_gimidashboard');
    }

    /**
     * Returns the inline CSS used by the header container.
     *
     * @param string $component Report component name.
     * @return string
     * @throws \dml_exception
     */
    public static function get_header_style(string $component): string {
        if ($component === '') {
            return '';
        }

        $basecolor = self::get_base_color($component);
        $accentcolor = self::calculate_accent_color($basecolor);

        return 'background: linear-gradient(135deg, ' . $basecolor . ' 0%, ' . $accentcolor . ' 100%);';
    }

    /**
     * Calculates the accent color used in the gradient.
     *
     * @param string $basecolor Base color.
     * @return string
     */
    public static function calculate_accent_color(string $basecolor): string {
        $normalized = self::normalize_hex_color($basecolor) ?: self::DEFAULT_BASE_COLOR;
        [$red, $green, $blue] = self::hex_to_rgb($normalized);
        [$hue, $saturation, $lightness] = self::rgb_to_hsl($red, $green, $blue);

        $hue = fmod(($hue - 4.0 + 360.0), 360.0);
        $saturation = min(1.0, $saturation + 0.085);
        $lightness = min(1.0, $lightness + 0.069);

        [$red, $green, $blue] = self::hsl_to_rgb($hue, $saturation, $lightness);

        return self::rgb_to_hex($red, $green, $blue);
    }

    /**
     * Validates and normalizes a hexadecimal color.
     *
     * @param string $color Color string.
     * @return string|null
     */
    public static function normalize_hex_color(string $color): ?string {
        $color = trim($color);
        if ($color === '') {
            return null;
        }

        if (!preg_match('/^#?[0-9a-fA-F]{6}$/', $color)) {
            return null;
        }

        return '#' . strtoupper(ltrim($color, '#'));
    }

    /**
     * Returns the config key for a report component.
     *
     * @param string $component Report component name.
     * @return string
     */
    protected static function get_config_name(string $component): string {
        return 'reportheadercolor_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($component));
    }

    /**
     * Converts a hexadecimal color to RGB values.
     *
     * @param string $hex Hexadecimal color.
     * @return array
     */
    protected static function hex_to_rgb(string $hex): array {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Converts RGB values to an hexadecimal color.
     *
     * @param int $red Red channel.
     * @param int $green Green channel.
     * @param int $blue Blue channel.
     * @return string
     */
    protected static function rgb_to_hex(int $red, int $green, int $blue): string {
        return sprintf('#%02X%02X%02X', $red, $green, $blue);
    }

    /**
     * Converts RGB values to HSL values.
     *
     * @param int $red Red channel.
     * @param int $green Green channel.
     * @param int $blue Blue channel.
     * @return array
     */
    protected static function rgb_to_hsl(int $red, int $green, int $blue): array {
        $red = $red / 255;
        $green = $green / 255;
        $blue = $blue / 255;

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;
        $hue = 0.0;
        $saturation = 0.0;

        if ($max !== $min) {
            $delta = $max - $min;
            $saturation = $lightness > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);

            if ($max === $red) {
                $hue = (($green - $blue) / $delta) + ($green < $blue ? 6 : 0);
            } else if ($max === $green) {
                $hue = (($blue - $red) / $delta) + 2;
            } else {
                $hue = (($red - $green) / $delta) + 4;
            }

            $hue *= 60;
        }

        return [$hue, $saturation, $lightness];
    }

    /**
     * Converts HSL values to RGB values.
     *
     * @param float $hue Hue in degrees.
     * @param float $saturation Saturation from 0 to 1.
     * @param float $lightness Lightness from 0 to 1.
     * @return array
     */
    protected static function hsl_to_rgb(float $hue, float $saturation, float $lightness): array {
        $hue = $hue / 360;

        if ($saturation == 0.0) {
            $value = (int) round($lightness * 255);
            return [$value, $value, $value];
        }

        $q = $lightness < 0.5
            ? $lightness * (1 + $saturation)
            : $lightness + $saturation - ($lightness * $saturation);
        $p = (2 * $lightness) - $q;

        $red = self::hue_to_rgb($p, $q, $hue + (1 / 3));
        $green = self::hue_to_rgb($p, $q, $hue);
        $blue = self::hue_to_rgb($p, $q, $hue - (1 / 3));

        return [
            (int) round($red * 255),
            (int) round($green * 255),
            (int) round($blue * 255),
        ];
    }

    /**
     * Converts a hue value to RGB.
     *
     * @param float $p Intermediate value.
     * @param float $q Intermediate value.
     * @param float $t Hue segment.
     * @return float
     */
    protected static function hue_to_rgb(float $p, float $q, float $t): float {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < (1 / 6)) {
            return $p + (($q - $p) * 6 * $t);
        }
        if ($t < (1 / 2)) {
            return $q;
        }
        if ($t < (2 / 3)) {
            return $p + (($q - $p) * ((2 / 3) - $t) * 6);
        }

        return $p;
    }
}
