<?php
namespace local_gimidashboard\report;


/**
 * Contract implemented by dashboard report subplugins.
 *
 * @package   local_gimidashboard
 */
interface report_interface {
    /**
     * Returns the report title.
     *
     * @return string
     */
    public static function get_title(): string;

    /**
     * Returns true when the report supports a single course selection.
     *
     * @return bool
     */
    public static function supports_course(): bool;

    /**
     * Returns true when the report supports a category selection.
     *
     * @return bool
     */
    public static function supports_category(): bool;

    /**
     * Renders the report HTML.
     *
     * @param array $courses Accessible course records.
     * @return string
     */
    public static function render(array $courses): string;
}
