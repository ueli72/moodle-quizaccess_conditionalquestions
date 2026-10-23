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
 * Restore instructions for the conditional questions quiz access subplugin.
 *
 * @package    quizaccess_conditionalquestions
 * @category   backup
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/backup/moodle2/restore_mod_quiz_access_subplugin.class.php');

/**
 * Restore instructions for the conditional questions quiz access subplugin.
 *
 * Restores the visibility rules of the quiz, remapping the question, group,
 * course module and badge ids. Rules whose target cannot be resolved on this
 * site (e.g. competencies on a different site) are dropped, because a rule
 * with a dangling target would hide the question from everybody.
 *
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_quizaccess_conditionalquestions_subplugin extends restore_mod_quiz_access_subplugin {

    /**
     * Provides path structure required to restore data for this subplugin.
     *
     * @return array of restore_path_element.
     */
    protected function define_quiz_subplugin_structure() {
        $paths = [];

        $paths[] = new restore_path_element('quizaccess_condq_rule',
            $this->get_pathfor('/quizaccess_condq_rule'));

        return $paths;
    }

    /**
     * Process a restored rule.
     *
     * @param array $data the rule data from the backup xml.
     */
    public function process_quizaccess_condq_rule($data) {
        global $DB;

        $data = (object) $data;
        $data->quizid = $this->get_new_parentid('quiz');

        // Remap the question the rule belongs to.
        $data->questionid = $this->get_mappingid('question', $data->questionid, false);

        // Remap the condition target, depending on its type.
        switch ($data->conditiontype) {
            case 'group':
                $newgroupid = $this->get_mappingid('group', $data->conditionvalue, false);
                if ($newgroupid) {
                    $data->conditionvalue = $newgroupid;
                } else if (!$this->task->is_samesite() ||
                        !$DB->record_exists('groups', ['id' => $data->conditionvalue])) {
                    $this->drop_rule($data);
                    return;
                }
                break;
            case 'activity':
                $newcmid = $this->get_mappingid('course_module', $data->conditionvalue, false);
                if ($newcmid) {
                    $data->conditionvalue = $newcmid;
                } else if (!$this->task->is_samesite() ||
                        !$DB->record_exists('course_modules', ['id' => $data->conditionvalue])) {
                    $this->drop_rule($data);
                    return;
                }
                break;
            case 'badge':
                $newbadgeid = $this->get_mappingid('badge', $data->conditionvalue, false);
                if ($newbadgeid) {
                    $data->conditionvalue = $newbadgeid;
                } else if (!$this->task->is_samesite() ||
                        !$DB->record_exists('badge', ['id' => $data->conditionvalue])) {
                    // The badge is neither in the backup nor on this site: drop the rule.
                    $this->drop_rule($data);
                    return;
                }
                break;
            case 'competency':
                // Competencies are not part of course backups: keep them only on the same site.
                if (!$this->task->is_samesite() ||
                        !$DB->record_exists('competency', ['id' => $data->conditionvalue])) {
                    $this->drop_rule($data);
                    return;
                }
                break;
            default:
                // Unknown condition type: drop the rule.
                $this->drop_rule($data);
                return;
        }

        if (empty($data->quizid) || empty($data->questionid) || empty($data->conditionvalue)) {
            $this->drop_rule($data);
            return;
        }

        unset($data->id);
        $data->timecreated = time();
        $data->timemodified = time();

        // A rule for this question may already exist (e.g. when the quiz is
        // restored over an existing one): update it instead of duplicating.
        $existing = $DB->get_record('quizaccess_condq_rule',
            ['quizid' => $data->quizid, 'questionid' => $data->questionid]);
        if ($existing) {
            $data->id = $existing->id;
            $DB->update_record('quizaccess_condq_rule', $data);
        } else {
            $DB->insert_record('quizaccess_condq_rule', $data);
        }
    }

    /**
     * Log the dropping of a rule that could not be restored.
     *
     * @param \stdClass $data the rule data.
     */
    private function drop_rule(\stdClass $data): void {
        $this->task->log('Dropping quizaccess_conditionalquestions rule for question ' .
            $data->questionid . ' with unresolvable condition ' .
            $data->conditiontype . ':' . $data->conditionvalue, \backup::LOG_INFO);
    }
}
