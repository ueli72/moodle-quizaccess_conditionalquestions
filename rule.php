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
 * A no-op quiz access rule that provides the configuration UI entry point.
 *
 * The rule itself does not restrict access in any way. It exists so that the
 * plugin is a first-class quiz access rule: its settings link appears in the
 * quiz settings form and the conditional question behaviour is applied through
 * the existing \mod_quiz\hook\attempt_state_changed hook.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The conditional questions access rule.
 */
class quizaccess_conditionalquestions extends \mod_quiz\local\access_rule_base {
    /**
     * Make an instance of this rule, but only for quizzes that actually use it.
     *
     * @param \mod_quiz\quiz_settings $quizobj information about the quiz in question.
     * @param int $timenow the time that should be considered as 'now'.
     * @param bool $canignoretimelimits whether the current user is exempt from time limits.
     * @return self|null the rule, or null when the quiz has no conditional questions.
     */
    public static function make(\mod_quiz\quiz_settings $quizobj, $timenow, $canignoretimelimits) {
        global $DB;
        if (!$DB->record_exists('quizaccess_condq_rule', ['quizid' => $quizobj->get_quizid()])) {
            return null;
        }
        return new self($quizobj, $timenow);
    }

    /**
     * Add the configuration link to the quiz settings form.
     *
     * @param \mod_quiz_mod_form $quizform the quiz settings form that is being built.
     * @param MoodleQuickForm $mform the wrapped MoodleQuickForm.
     */
    public static function add_settings_form_fields(\mod_quiz_mod_form $quizform, MoodleQuickForm $mform): void {
        $cmid = $quizform->get_coursemodule()->id;
        $url = new \moodle_url('/mod/quiz/accessrule/conditionalquestions/index.php', ['cmid' => $cmid]);
        $link = \html_writer::link(
            $url,
            get_string('configureconditions', 'quizaccess_conditionalquestions')
        );
        $mform->addElement('static', 'conditionalquestionsintro', '',
            get_string('formintro', 'quizaccess_conditionalquestions'));
        $mform->addElement('static', 'conditionalquestionslink', '', $link);
    }
}
