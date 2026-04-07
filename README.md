# local_gimidashboard

## Overview

`local_gimidashboard` is a Moodle local plugin that centralizes one or more dashboard reports under a single entry point.

The parent plugin is responsible for:

- discovering installed report subplugins;
- filtering the list of courses/categories the current user can access;
- resolving the selected target (`course-{id}` or `category-{id}`);
- deciding which report subplugins can run for the current selection;
- rendering enabled reports in the configured order.

The reports themselves live as subplugins under:

```text
local/gimidashboard/reports/
````

Each report subplugin is an isolated plugin with its own:

* `version.php`
* `lang/en/...php`
* `classes/report.php`
* `templates/...`
* any extra classes needed for SQL, tables, services, formatters, or builders

## Access model

The access control is implemented mainly in:

* `classes/access/access_manager.php`
* `classes/access/config.php`
* `classes/settings/capability_options.php`

### Important detail

Although the setting is called `reportcapabilities`, the current code stores and uses **role IDs**, not capability names.

So the effective rule is:

* the user must have `local/gimidashboard:view` in the course context;
* the user must also have one of the configured roles considered valid for reports;
* site admins bypass the role restriction.

### Supported role scopes

The plugin grants visibility when the configured role is assigned in one of these contexts:

* system context;
* course category context;
* course context.

### Course list resolution

`access_manager::get_accessible_courses()` builds the full list of visible courses for the current user.
That list is later reused by the selector and by the reports.

### Category filtering

`access_manager::get_accessible_courses_for_category($categoryid)` does **not** query all courses from the category directly.
Instead it:

1. starts from the already accessible courses;
2. reads the category path of those courses;
3. keeps only courses inside the selected category tree.

This ensures category reports never receive courses outside the user’s allowed scope.

### Discovery

The manager uses Moodle plugin discovery:

```php
core_plugin_manager::instance()->get_plugins_of_type("gimidashboardreports")
```

For each discovered subplugin, it builds metadata like:

* `component`
* `name`
* `displayname`
* `classname`

Example for folder `fullacademydashboard`:

```php
[
    "component" => "gimidashboardreports_fullacademydashboard",
    "name" => "fullacademydashboard",
    "displayname" => get_string("pluginname", "gimidashboardreports_fullacademydashboard"),
    "classname" => "\\gimidashboardreports_fullacademydashboard\\report",
]
```

### Compatibility with selection type

Before rendering, the parent plugin checks whether the report class:

* exists;
* implements `local_gimidashboard\report\report_interface`;
* supports the current selection type (`course` or `category`).

Only then `render($courses)` is called.

### Rendering wrapper

The report HTML is wrapped by:

```text
templates/report_card.mustache
```

So the parent plugin is responsible for the outer card, and the subplugin is responsible for the internal content.

## Report contract

Every report subplugin must expose a class named:

```php
\gimidashboardreports_<pluginname>\report
```

That class must implement:

```php
local_gimidashboard\report\report_interface
```

Current interface:

```php
interface report_interface {
    public static function get_header(array $courses, $extra=""): string;
    public static function supports_course(): bool;
    public static function supports_category(): bool;
    public static function render(array $courses): string;
}
```

### Method semantics

#### `get_header(array $courses, $extra="")`

Returns the report title shown by the parent plugin.

#### `supports_course()`

Return `true` when the report can work with a single selected course.

#### `supports_category()`

Return `true` when the report can work with multiple courses coming from a category selection.

#### `render(array $courses)`

Receives the filtered courses already authorized by the parent plugin.
This method must return an HTML string, normally produced with:

```php
$OUTPUT->render_from_template(...)
```

## Data contract for `$courses`

The parent plugin sends `$courses` as an array of Moodle course records indexed by course id.

For a course selection, it usually looks like:

```php
[
    17 => $course17,
]
```

For a category selection, it looks like:

```php
[
    17 => $course17,
    32 => $course32,
    44 => $course44,
]
```

A report that supports categories should always work with `IN (...)` SQL over the received course IDs.
It must not assume a single course unless `supports_category()` is `false`.

## Existing example: `fullacademydashboard`

The uploaded package contains one concrete report subplugin:

```text
reports/fullacademydashboard/
```

Main characteristics found in the code:

* namespace: `gimidashboardreports_fullacademydashboard`
* contract class: `classes/report.php`
* supports both course and category selections
* uses Mustache templates for rendering
* builds summary KPIs, learner table, detail table, and export pipeline

This subplugin is the best reference when creating new reports.

## Administration page for subplugins

The plugin includes:

```text
/local/gimidashboard/admin_plugins.php
```

This page allows:

* enabling/disabling reports;
* moving reports up/down;
* persisting order and active state.

Internally it uses:

```text
classes/admin/plugin_admin_page.php
```

### Important implementation detail

The page exists and works, but the external admin menu registration inside `settings.php` is currently commented out.
So, in the current uploaded version, the page may need to be accessed directly by URL unless that menu block is re-enabled.

## How to create a new report subplugin

Suppose the new report will be called `learnerprogress`.

### 1) Create the folder

```text
local/gimidashboard/reports/learnerprogress/
```

### 2) Create the minimum file structure

```text
local/gimidashboard/reports/learnerprogress/
├── classes/
│   └── report.php // implements report_interface
├── lang/
│   └── en/
│       └── gimidashboardreports_learnerprogress.php
├── templates/
│   └── content.mustache
└── version.php
```

### 3) Create `version.php`

```php
<?php

defined("MOODLE_INTERNAL") || die();

$plugin->component = "gimidashboardreports_learnerprogress";
$plugin->version = 2026032400;
$plugin->requires = 2024042200;
$plugin->maturity = MATURITY_STABLE;
$plugin->release = "0.1.0";
```

### 4) Create the language file

File:

```text
lang/en/gimidashboardreports_learnerprogress.php
```

Minimal content:

```php
<?php

$string["pluginname"] = "Learner Progress";
$string["empty"] = "No data found for the current selection.";
```

### 5) Create the report class

File:

```text
classes/report.php
```

Example skeleton:

```php
<?php

namespace gimidashboardreports_learnerprogress;

use local_gimidashboard\report\report_interface;

class report implements report_interface {
    public static function get_header(array $courses, $extra=""): string {
        return get_string("pluginname", "gimidashboardreports_learnerprogress");
    }

    public static function supports_course(): bool {
        return true;
    }

    public static function supports_category(): bool {
        return true;
    }

    public static function render(array $courses): string {
        global $OUTPUT;

        $courseids = array_keys($courses);
        if (empty($courseids)) {
            return "";
        }

        $rows = [];

        return $OUTPUT->render_from_template("gimidashboardreports_learnerprogress/content", [
            "rows" => $rows,
            "hasrows" => !empty($rows),
        ]);
    }
}
```

### 6) Create the template

File:

```text
templates/content.mustache
```

Example:

```mustache
<div class="gimi-learner-progress">
    {{#hasrows}}
        <div>Render your report here.</div>
    {{/hasrows}}

    {{^hasrows}}
        <div class="alert alert-info">{{#str}}empty, gimidashboardreports_learnerprogress{{/str}}</div>
    {{/hasrows}}
</div>
```

### 7) Install or upgrade

After adding the subplugin files:

* visit Moodle notifications;
* complete the upgrade;
* configure the parent plugin if needed;
* open `/local/gimidashboard/admin_plugins.php` to order or disable the new report.
