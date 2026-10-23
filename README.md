# Conditional questions (quiz access rule)

This Moodle 5.2 quiz access rule adds one optional visibility condition to an
individual question already present in a quiz. A condition is evaluated when a
new attempt is started. If it is not met, the question is replaced in place by
a zero-mark information item, so the student neither sees it nor is graded
against it: each attempt is graded out of the questions the student actually
saw. Existing attempts are not changed. Teacher previews always show all
questions.

The plugin uses **only existing Moodle core APIs** – no core patch is required:

- `\mod_quiz\hook\attempt_state_changed` (existing core hook) is used to
  - hide questions with unmet conditions when the attempt starts (the question
    is replaced in the question usage by a shared, hidden description
    placeholder, the same mechanism core uses when it updates a question to a
    new version mid-attempt), and
  - normalise the attempt sumgrades and final grade when the attempt finishes,
    so it is graded out of what the student saw;
- `mod_quiz\grade_calculator::recompute_final_grade()` (existing public core
  API) recomputes the final grade after normalisation;
- a scheduled task reconciles attempts that are regraded or manually regraded
  later on;
- per-attempt data is stored in the plugin's own tables, not in core tables.

The current condition types are course-group membership, completed activity,
completed competency, and awarded badge. Global and course competencies, as
well as global and course badges, are available. Competencies and badges use
searchable autocomplete controls.

All conditions are configured on a single overview page listing every question
of the quiz. The page is linked from the quiz settings form ("Extra
restrictions" section, link "Configure conditional questions").

Random questions cannot have a condition, because the concrete question is
only chosen when the attempt starts.

## Backup and restore

Rules are attached to the quiz module backup and restored together with the
quiz. Question, group, course module and badge ids are remapped on restore.
Rules whose target cannot be resolved (e.g. a competency on a different site)
are dropped, because a dangling condition would hide the question from
everybody. Rules and attempt data are deleted automatically when the quiz
module is deleted.

## License

The plugin is distributed under the GNU General Public License as published
by the Free Software Foundation, version 3 or later (see the LICENSE file).


## Data migration

The predecessor plugin `local_conditional_questions` relied on a core patch
that is no longer needed and should be reverted. On installation this plugin
migrates automatically:

- rules from `local_conditional_questions_rule` are copied;
- per-attempt maxima stored in the `quiz_attempts.maxmark` column added by the
  old core patch are moved into the plugin's own table, the stored attempt
  sumgrades are normalised, and the core column is dropped again.

After installing this plugin, uninstall `local_conditional_questions` and
revert its core patch:

```text
git -C /path/to/moodle apply -R public/local/conditional_questions/cli/core_patch.patch
php admin/cli/uninstall_plugins.php --plugin=local_conditional_questions
```

## Tests

```text
vendor/bin/phpunit public/mod/quiz/accessrule/conditionalquestions/tests
```
