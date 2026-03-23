<?php

/**
 * Extends the course navigation with a dashboard entry.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course record.
 * @param context_course $context The course context.
 * @return void
 */
function local_gimidashboard_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    \local_gimidashboard\navigation\course_navigation::extend($navigation, $course, $context);
}
