<?php
namespace local_gimidashboard\access;


/**
 * Formats category names including their full path.
 *
 * @package   local_gimidashboard
 */
class category_path_formatter {
    /**
     * Returns full labels indexed by category id.
     *
     * @param array $categoryids Category ids.
     * @return array
     */
    public static function get_labels(array $categoryids): array {
        global $DB;

        $categoryids = array_values(array_unique(array_map("intval", $categoryids)));
        if (empty($categoryids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $selected = $DB->get_records_select("course_categories", "id {$insql}", $params, "", "id, name, path");

        $allids = [];
        foreach ($selected as $category) {
            foreach (explode("/", trim( $category->path, "/")) as $pathid) {
                if ($pathid !== "") {
                    $allids[ $pathid] =  $pathid;
                }
            }
        }

        if (empty($allids)) {
            return [];
        }

        [$allinsql, $allparams] = $DB->get_in_or_equal(array_values($allids), SQL_PARAMS_NAMED);
        $allcategories = $DB->get_records_select("course_categories", "id {$allinsql}", $allparams, "", "id, name");

        $labels = [];
        foreach ($selected as $category) {
            $parts = [];
            foreach (explode("/", trim( $category->path, "/")) as $pathid) {
                $pathid =  $pathid;
                if (!empty($allcategories[$pathid])) {
                    $parts[] = format_string($allcategories[$pathid]->name, true, ["context" => \context_system::instance()]);
                }
            }
            $labels[ $category->id] = implode(" / ", $parts);
        }

        return $labels;
    }
}
