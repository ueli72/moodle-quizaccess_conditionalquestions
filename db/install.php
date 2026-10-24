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
 * Install code for the conditional questions quiz access rule.
 *
 * Migrates data from the previous local_conditional_questions plugin:
 * the visibility rules and the per-attempt maximum marks that were stored
 * in the quiz_attempts.maxmark column added by its core patch.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Migrate data from the previous local_conditional_questions plugin.
 */
function xmldb_quizaccess_conditionalquestions_install(): void {
    global $DB;

    $dbman = $DB->get_manager();
    $now = time();

    // 1. Migrate the visibility rules (the very first release used a shorter table name).
    $oldtable = null;
    if ($dbman->table_exists('local_conditional_questions_rule')) {
        $oldtable = 'local_conditional_questions_rule';
    } else if ($dbman->table_exists('local_cq_rule')) {
        $oldtable = 'local_cq_rule';
    }
    if ($oldtable) {
        $oldrules = $DB->get_records($oldtable);
        foreach ($oldrules as $old) {
            if ($DB->record_exists('quizaccess_condq_rule', [
                'quizid' => $old->quizid,
                'questionid' => $old->questionid,
            ])) {
                continue;
            }
            $DB->insert_record('quizaccess_condq_rule', (object) [
                'quizid' => $old->quizid,
                'questionid' => $old->questionid,
                'conditiontype' => $old->conditiontype,
                'conditionvalue' => $old->conditionvalue,
                'timecreated' => $old->timecreated ?: $now,
                'timemodified' => $old->timemodified ?: $now,
            ]);
        }
    }

    // 2. Migrate the per-attempt maxmark values from the patched core column.
    $table = new xmldb_table('quiz_attempts');
    $field = new xmldb_field('maxmark');
    if ($dbman->table_exists($table) && $dbman->field_exists($table, $field)) {
        // Remember the raw sumgrades and the effective maximum for every non-preview attempt.
        $DB->execute(
            "INSERT INTO {quizaccess_condq_attempt}
                  (attemptid, quizid, userid, maxmark, rawsumgrades, scaledsumgrades, timecreated, timemodified)
             SELECT a.id, a.quiz, a.userid, a.maxmark, a.sumgrades, NULL, ?, ?
               FROM {quiz_attempts} a
              WHERE a.maxmark IS NOT NULL AND a.maxmark > 0 AND a.preview = 0",
            [$now, $now]
        );

        // Rescale the stored sumgrades so it is normalised to the full quiz sumgrades.
        $DB->execute(
            "UPDATE {quizaccess_condq_attempt} t
                JOIN {quiz} q ON q.id = t.quizid
                SET t.scaledsumgrades = ROUND(t.rawsumgrades * q.sumgrades / t.maxmark, 7)
              WHERE t.rawsumgrades IS NOT NULL AND q.sumgrades > 0"
        );
        $DB->execute(
            "UPDATE {quiz_attempts} a
                JOIN {quizaccess_condq_attempt} t ON t.attemptid = a.id
                JOIN {quiz} q ON q.id = a.quiz
                SET a.sumgrades = t.scaledsumgrades
              WHERE t.scaledsumgrades IS NOT NULL"
        );

        // The column was only added by the previous core patch; core does not know about it.
        $dbman->drop_field($table, $field);
    }
}
