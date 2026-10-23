<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Configure conditional quiz questions for one quiz - all questions at once.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/formslib.php');

$cmid = required_param('cmid', PARAM_INT);
$quizobj = \mod_quiz\quiz_settings::create_for_cmid($cmid);
$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
$context = \context_module::instance($cmid);
require_login($quizobj->get_course(), false, $cm);
require_capability('quizaccess/conditionalquestions:configure', $context);

$PAGE->set_url('/mod/quiz/accessrule/conditionalquestions/index.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->activityheader->disable();

$quizobj->preload_questions();
$questions = $quizobj->get_questions(null, false);
if (!$questions) {
    redirect(
        new \moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid]),
        get_string('noquestions', 'quizaccess_conditionalquestions'),
        null, \core\output\notification::NOTIFY_WARNING
    );
}

$PAGE->set_title(get_string('conditionheading', 'quizaccess_conditionalquestions'));
$PAGE->set_heading(get_string('conditionheading', 'quizaccess_conditionalquestions'));

$targets = [
    'group' => ['' => get_string('selecttarget', 'quizaccess_conditionalquestions')],
    'activity' => ['' => get_string('selecttarget', 'quizaccess_conditionalquestions')],
    'competency' => [],
    'badge' => [],
];
foreach (groups_get_all_groups($quizobj->get_courseid()) as $groupid => $group) {
    $targets['group'][$groupid] = format_string($group->name);
}
$modinfo = get_fast_modinfo($quizobj->get_courseid());
foreach ($modinfo->get_cms() as $activity) {
    if ($activity->id != $cmid && $activity->uservisible && $activity->completion != COMPLETION_TRACKING_NONE) {
        $targets['activity'][$activity->id] = format_string($activity->name);
    }
}
$competencies = $DB->get_records_sql(
    'SELECT c.id, c.shortname, cf.shortname AS frameworkname,
            CASE WHEN cc.id IS NULL THEN 0 ELSE 1 END AS iscoursecompetency
       FROM {competency} c
       JOIN {competency_framework} cf ON cf.id = c.competencyframeworkid
       LEFT JOIN {competency_coursecomp} cc
         ON cc.competencyid = c.id AND cc.courseid = :courseid
      ORDER BY cf.shortname, c.shortname',
    ['courseid' => $quizobj->get_courseid()]
);
foreach ($competencies as $competency) {
    $scope = $competency->iscoursecompetency
        ? get_string('coursecompetency', 'quizaccess_conditionalquestions')
        : get_string('globalcompetency', 'quizaccess_conditionalquestions');
    $targets['competency'][$competency->id] = $scope . ': ' . format_string($competency->frameworkname) . ' / ' .
        format_string($competency->shortname);
}
foreach (
    $DB->get_records_select(
        'badge',
        '(courseid IS NULL OR courseid = 0 OR courseid = :courseid)',
        ['courseid' => $quizobj->get_courseid()],
        'name'
    ) as $badge
) {
    $targets['badge'][$badge->id] = format_string($badge->name);
}

$form = new \quizaccess_conditionalquestions\form\conditions(null, [
    'questions' => $questions,
    'targets' => $targets,
    'cmid' => $cmid,
]);

// Pre-fill the form with the existing rules, keyed per question id.
$formdata = ['cmid' => $cmid];
$rules = [];
foreach ($DB->get_records('quizaccess_condq_rule', ['quizid' => $quizobj->get_quizid()]) as $rule) {
    $rules[$rule->questionid] = $rule;
}
foreach ($questions as $question) {
    $id = (int) $question->questionid;
    if ($id <= 0) {
        continue;
    }
    $rule = $rules[$id] ?? null;
    $formdata['condition_' . $id] = $rule->conditiontype ?? '';
    foreach (['group', 'activity', 'competency', 'badge'] as $type) {
        $value = ($rule && $rule->conditiontype === $type) ? $rule->conditionvalue : 0;
        $formdata['target_' . $type . '_' . $id] = $value;
    }
}
$form->set_data((object) $formdata);

$PAGE->requires->js_call_amd('quizaccess_conditionalquestions/conditions', 'init');

if ($form->is_cancelled()) {
    redirect(new \moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid]));
}
if ($data = $form->get_data()) {
    $now = time();
    $changed = false;
    foreach ($questions as $question) {
        $id = (int) $question->questionid;
        if ($id <= 0) {
            continue;
        }
        $DB->delete_records('quizaccess_condq_rule', [
            'quizid' => $quizobj->get_quizid(), 'questionid' => $id,
        ]);
        $type = $data->{'condition_' . $id} ?? '';
        $value = $type !== '' ? ((int) ($data->{'target_' . $type . '_' . $id} ?? 0)) : 0;
        if ($type === '' || $value <= 0) {
            continue;
        }
        $DB->insert_record('quizaccess_condq_rule', (object) [
            'quizid' => $quizobj->get_quizid(), 'questionid' => $id,
            'conditiontype' => $type, 'conditionvalue' => $value,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $changed = true;
    }
    redirect(
        new \moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid]),
        get_string('changessaved')
    );
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
