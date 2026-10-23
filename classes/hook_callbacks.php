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
 * Hook callback implementation for the conditional questions quiz access rule.
 *
 * The plugin reacts exclusively to the existing core hook
 * \mod_quiz\hook\attempt_state_changed, so no core patch is required.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_conditionalquestions;

defined('MOODLE_INTERNAL') || die();

/**
 * Reacts to quiz attempt state transitions.
 */
final class hook_callbacks {
    /** @var float tolerance used when comparing float grades. */
    const EPSILON = 0.0000001;

    /**
     * Entry point for the attempt_state_changed core hook.
     *
     * @param \mod_quiz\hook\attempt_state_changed $hook the hook instance.
     */
    public static function attempt_state_changed(\mod_quiz\hook\attempt_state_changed $hook): void {
        global $DB;

        $original = $hook->get_original_attempt();
        $updated = $hook->get_updated_attempt();

        if ($updated === null) {
            // The attempt has been deleted: clean up our own records.
            self::attempt_deleted((int) $original->id);
            return;
        }

        if (!empty($updated->preview)) {
            // Teacher previews are never filtered or normalised.
            return;
        }

        if (
            $updated->state === \mod_quiz\quiz_attempt::IN_PROGRESS &&
            ($original === null || $original->state === \mod_quiz\quiz_attempt::NOT_STARTED)
        ) {
            // A fresh attempt (or a pre-created NOT_STARTED attempt) has just been started.
            self::filter_attempt($updated);
            return;
        }

        if (
            $updated->state === \mod_quiz\quiz_attempt::FINISHED && $original !== null &&
            in_array($original->state, [\mod_quiz\quiz_attempt::IN_PROGRESS, \mod_quiz\quiz_attempt::SUBMITTED])
        ) {
            // The attempt has just been graded: normalise its sumgrades and final grade.
            self::finalise_attempt_grades($updated);
        }
    }

    /**
     * Hide questions whose condition is not met when an attempt is started.
     *
     * Questions are not removed from the question usage (that is not possible
     * once the usage has been saved). Instead they are replaced in place by a
     * zero-mark description placeholder, exactly like core does when it updates
     * a question to a new version mid-attempt. The original slot layout stays
     * intact and the attempt maximum is the sum of the remaining questions.
     *
     * @param \stdClass $attempt the freshly started attempt record.
     */
    private static function filter_attempt(\stdClass $attempt): void {
        global $DB;

        $quizid = (int) $attempt->quiz;
        $rules = [];
        foreach ($DB->get_records('quizaccess_condq_rule', ['quizid' => $quizid]) as $rule) {
            $rules[(int) $rule->questionid] = $rule;
        }
        if (!$rules) {
            return;
        }
        if ($DB->record_exists('quizaccess_condq_attempt', ['attemptid' => $attempt->id])) {
            // Already processed.
            return;
        }

        $quizobj = \mod_quiz\quiz_settings::create($quizid);
        $context = $quizobj->get_context();
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);

        $rec = (object) [
            'attemptid' => (int) $attempt->id,
            'quizid' => $quizid,
            'userid' => (int) $attempt->userid,
            'maxmark' => 0.0,
            'rawsumgrades' => null,
            'scaledsumgrades' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $rec->id = $DB->insert_record('quizaccess_condq_attempt', $rec);

        $placeholderid = placeholder::get_placeholder_questionid($quizid, $context);
        $changed = false;

        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            $questionid = $qa->get_question_id();

            if (!isset($rules[$questionid])) {
                continue;
            }

            $met = self::condition_is_met($rules[$questionid], $quizobj, (int) $attempt->userid);
            $isplaceholder = placeholder::is_placeholder((int) $questionid, $quizid, $context);

            if (!$met && !$isplaceholder) {
                // The condition is not met: replace the question by the placeholder.
                $placeholder = placeholder::get_placeholder_question($quizid, $context);
                if ($placeholder === null) {
                    continue;
                }
                $DB->insert_record('quizaccess_condq_hidden', (object) [
                    'attemptid' => (int) $attempt->id,
                    'slot' => $slot,
                    'questionid' => (int) $questionid,
                    'maxmark' => $qa->get_max_mark(),
                ]);
                $quba->add_question_in_place_of_other($slot, $placeholder, 0, false);
                $quba->start_question($slot);
                $changed = true;
            } else if ($met && $isplaceholder) {
                // A built-on-last attempt copied a placeholder from a previous
                // attempt, but the condition is met now: restore the original.
                $hidden = $DB->get_record('quizaccess_condq_hidden', [
                    'attemptid' => (int) $attempt->id,
                    'slot' => $slot,
                ], '*', IGNORE_MISSING);
                if (!$hidden) {
                    continue;
                }
                $question = \question_bank::load_question((int) $hidden->questionid, $quizobj->get_quiz()->shuffleanswers);
                $quba->add_question_in_place_of_other($slot, $question, (float) $hidden->maxmark, false);
                $quba->start_question($slot);
                $DB->delete_records('quizaccess_condq_hidden', ['id' => $hidden->id]);
                $changed = true;
            }
        }

        // The effective attempt maximum is the sum of the questions the user faces.
        $total = 0.0;
        foreach ($quba->get_slots() as $slot) {
            $total += $quba->get_question_max_mark($slot);
        }
        $rec->maxmark = $total;
        $rec->timemodified = time();
        $DB->update_record('quizaccess_condq_attempt', $rec);

        if ($changed) {
            \question_engine::save_questions_usage_by_activity($quba);
        }
    }

    /**
     * Normalise the attempt sumgrades to the full quiz sumgrades and recompute
     * the final grade, so each attempt is graded out of what the user saw.
     *
     * @param \stdClass $attempt the finished attempt record.
     */
    private static function finalise_attempt_grades(\stdClass $attempt): void {
        global $DB;

        $rec = $DB->get_record('quizaccess_condq_attempt', ['attemptid' => (int) $attempt->id]);
        if (!$rec || $rec->maxmark <= 0) {
            return;
        }
        if ($rec->scaledsumgrades !== null) {
            // Already finalised.
            return;
        }

        $quizobj = \mod_quiz\quiz_settings::create((int) $attempt->quiz);
        $quiz = $quizobj->get_quiz();
        if ($quiz->sumgrades <= 0) {
            return;
        }

        $dbattempt = $DB->get_record('quiz_attempts', ['id' => (int) $attempt->id], 'id, sumgrades');
        if (!$dbattempt || $dbattempt->sumgrades === null) {
            return;
        }

        $raw = (float) $dbattempt->sumgrades;
        $scaled = $raw;
        if (abs((float) $rec->maxmark - (float) $quiz->sumgrades) > self::EPSILON) {
            $scaled = $raw * (float) $quiz->sumgrades / (float) $rec->maxmark;
            $DB->set_field('quiz_attempts', 'sumgrades', $scaled, ['id' => $dbattempt->id]);
        }

        $rec->rawsumgrades = $raw;
        $rec->scaledsumgrades = $scaled;
        $rec->timemodified = time();
        $DB->update_record('quizaccess_condq_attempt', $rec);

        if (abs($scaled - $raw) > self::EPSILON) {
            // Core has already computed the final grade from the raw sumgrades.
            $quizobj->get_grade_calculator()->recompute_final_grade((int) $attempt->userid);
        }
    }

    /**
     * Delete the plugin records for a deleted attempt.
     *
     * @param int $attemptid the deleted attempt id.
     */
    private static function attempt_deleted(int $attemptid): void {
        global $DB;

        $DB->delete_records('quizaccess_condq_hidden', ['attemptid' => $attemptid]);
        $DB->delete_records('quizaccess_condq_attempt', ['attemptid' => $attemptid]);
    }

    /**
     * Check whether a rule's condition is met for the user.
     *
     * @param \stdClass $rule the rule record from quizaccess_condq_rule.
     * @param \mod_quiz\quiz_settings $quiz the quiz the attempt belongs to.
     * @param int $userid the user starting the attempt.
     * @return bool whether the condition is met.
     */
    public static function condition_is_met(\stdClass $rule, \mod_quiz\quiz_settings $quiz, int $userid): bool {
        global $DB;

        switch ($rule->conditiontype) {
            case 'group':
                return array_key_exists(
                    (int) $rule->conditionvalue,
                    groups_get_all_groups($quiz->get_courseid(), $userid)
                );
            case 'activity':
                return (bool) $DB->get_record_select(
                    'course_modules_completion',
                    'coursemoduleid = :cmid AND userid = :userid AND completionstate IN (:complete, :pass)',
                    [
                        'cmid' => $rule->conditionvalue,
                        'userid' => $userid,
                        'complete' => COMPLETION_COMPLETE,
                        'pass' => COMPLETION_COMPLETE_PASS,
                    ],
                    'id'
                );
            case 'competency':
                return $DB->record_exists('competency_usercomp', [
                    'userid' => $userid,
                    'competencyid' => $rule->conditionvalue,
                    'proficiency' => 1,
                ]);
            case 'badge':
                return (bool) $DB->get_record_sql(
                    'SELECT id FROM {badge_issued}
                      WHERE userid = :userid AND badgeid = :badgeid
                        AND (dateexpire IS NULL OR dateexpire = 0 OR dateexpire > :now)',
                    ['userid' => $userid, 'badgeid' => $rule->conditionvalue, 'now' => time()]
                );
            default:
                return false;
        }
    }
}
