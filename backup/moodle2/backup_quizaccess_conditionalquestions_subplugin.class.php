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
 * Backup instructions for the conditional questions quiz access subplugin.
 *
 * @package    quizaccess_conditionalquestions
 * @category   backup
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/backup/moodle2/backup_mod_quiz_access_subplugin.class.php');

/**
 * Backup instructions for the conditional questions quiz access subplugin.
 *
 * Stores the visibility rules of the quiz being backed up. The per-attempt
 * tables (quizaccess_condq_attempt / quizaccess_condq_hidden) hold user data
 * and are intentionally not included in a backup.
 *
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_quizaccess_conditionalquestions_subplugin extends backup_mod_quiz_access_subplugin {

    /**
     * Stores the visibility rules of the quiz.
     *
     * @return backup_subplugin_element
     */
    protected function define_quiz_subplugin_structure() {
        parent::define_quiz_subplugin_structure();

        $quizid = backup::VAR_ACTIVITYID;

        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());
        $rule = new backup_nested_element('quizaccess_condq_rule', null, [
            'questionid',
            'conditiontype',
            'conditionvalue',
        ]);

        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($rule);

        $rule->set_source_table('quizaccess_condq_rule', ['quizid' => $quizid]);

        return $subplugin;
    }
}
