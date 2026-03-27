# gimidashboardreports_leaderboard

`gimidashboardreports_leaderboard` is a report subplugin for `local_gimidashboard`.

## Scope used in this first version

This implementation assumes:

- **pathway = cohort**;
- the pathway must be linked to the selected course(s) through Moodle cohort enrolment instances;
- learners are only ranked inside the selected pathway (`cohortid`).

That means:

- when the current selection is a **category**, the report renders the **Pathway Leaderboard**;
- when the current selection is a **course**, the report renders the **Course Leaderboard**;
- if more than one pathway is linked to the selected scope, the user must choose one before the ranking is shown.

## Implemented categories

### Category selection

- **Best Grade**
  - average course grade percentage across selected courses where the learner has a grade greater than zero;
  - learners without graded assessments stay listed but unranked.

- **Most Progress**
  - average completion percentage across all selected courses;
  - courses not yet started count as `0%`;
  - everyone is ranked, including learners at `0%`.

### Course selection

- **Best Grade**
  - course grade percentage for the selected course;
  - learners without graded assessments stay listed but unranked.

- **Most Progress**
  - completion percentage for the selected course.

- **Fastest to Finish**
  - days between enrolment creation and certificate issue date;
  - currently checks certificate issue data from:
    - `mod_customcert`;
    - `tool_certificate` when the installation exposes `tool_certificate_templates.courseid`.
  - learners without a certificate stay listed but unranked.

## Installation

Place this folder in:

```text
local/gimidashboard/reports/leaderboard
```

Then upgrade Moodle and enable the report in `local_gimidashboard` if needed.
