<?php
namespace local_gimidashboard\navigation;


use coding_exception;
use context_course;
use core\exception\moodle_exception;
use local_gimidashboard\access\access_manager;
use moodle_url;
use navigation_node;
use stdClass;

/**
 * Adds the dashboard link to course navigation.
 *
 * @package   local_gimidashboard
 */
class course_navigation {
    /**
     * Adds the course navigation node when the user has access.
     *
     * @param navigation_node $navigation Course navigation node.
     * @param stdClass $course Course record.
     * @param context_course $context Course context.
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public static function extend(navigation_node $navigation, \stdClass $course, context_course $context): void {
        if (!access_manager::user_has_course_access($course->id)) {
            return;
        }

        $navigation->add(
            get_string("pluginname", "local_gimidashboard"),
            new moodle_url("/local/gimidashboard/view.php", ["target" => "course:" . $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            "local_gimidashboard"
        );
    }
}
