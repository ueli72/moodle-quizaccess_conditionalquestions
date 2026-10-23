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
 * Scheduled task that reconciles attempt grades for quizzes with conditional questions.
 *
 * Regrades and manual re-grading recompute attempt sumgrades from the question
 * usage, which loses the normalisation applied when the attempt was finished.
 * This task detects such attempts and re-applies the normalisation.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_conditionalquestions\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Reconcile attempt grades.
 */
class reconcile_grades extends \core\task\scheduled_task {
    /**
     * Get the name of the task.
     *
     * @return string the task name.
     */
    public function get_name(): string {
        return get_string('reconciletask', 'quizaccess_conditionalquestions');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        // Remove records of attempts that no longer exist.
        $DB->delete_records_select('quizaccess_condq_hidden',
            "attemptid NOT IN (SELECT id FROM {quiz_attempts})");
        $DB->delete_records_select('quizaccess_condq_attempt',
            "attemptid NOT IN (SELECT id FROM {quiz_attempts})");

        // Find finished attempts whose sumgrades is not the normalised value we stored.
        $sql = "SELECT a.id, a.quiz, a.userid, a.sumgrades, a.preview,
                       t.id AS recid, t.maxmark, t.scaledsumgrades, q.sumgrades AS quizsumgrades
                  FROM {quiz_attempts} a
                  JOIN {quizaccess_condq_attempt} t ON t.attemptid = a.id
                  JOIN {quiz} q ON q.id = a.quiz
                 WHERE a.state IN ('finished', 'abandoned')
                   AND a.preview = 0
                   AND t.maxmark > 0
                   AND q.sumgrades > 0
                   AND (t.scaledsumgrades IS NULL
                        OR ABS(a.sumgrades - t.scaledsumgrades) > :epsilon)";

        $records = $DB->get_records_sql($sql,
                ['epsilon' => \quizaccess_conditionalquestions\hook_callbacks::EPSILON]);
        if (!$records) {
            return;
        }

        $quizobjs = [];
        foreach ($records as $record) {
            $quizid = (int) $record->quiz;
            if (!isset($quizobjs[$quizid])) {
                $quizobjs[$quizid] = \mod_quiz\quiz_settings::create($quizid);
            }
            $quizobj = $quizobjs[$quizid];
            $quiz = $quizobj->get_quiz();

            $raw = (float) $record->sumgrades;
            $scaled = $raw;
            if (abs((float) $record->maxmark - (float) $record->quizsumgrades) >
                    \quizaccess_conditionalquestions\hook_callbacks::EPSILON) {
                $scaled = $raw * (float) $record->quizsumgrades / (float) $record->maxmark;
                $DB->set_field('quiz_attempts', 'sumgrades', $scaled, ['id' => $record->id]);
            }

            $DB->update_record('quizaccess_condq_attempt', (object) [
                'id' => $record->recid,
                'rawsumgrades' => $raw,
                'scaledsumgrades' => $scaled,
                'timemodified' => time(),
            ]);

            if (abs($scaled - $raw) > \quizaccess_conditionalquestions\hook_callbacks::EPSILON) {
                $quizobj->get_grade_calculator()->recompute_final_grade((int) $record->userid);
            }
        }
    }
}
