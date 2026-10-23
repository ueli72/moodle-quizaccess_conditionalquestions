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
 * Tests for the conditional questions quiz access rule hook callbacks.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_conditionalquestions;

defined('MOODLE_INTERNAL') || die();

/**
 * Integration tests for attempt filtering and grade normalisation.
 *
 * @covers \quizaccess_conditionalquestions\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Create a quiz with two one-mark questions, graded out of 10.
     *
     * @return \mod_quiz\quiz_settings the quiz.
     */
    private function create_quiz(): \mod_quiz\quiz_settings {
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        return $quizgenerator->create_test_quiz([
            ['Q1', 1, 'truefalse'],
            ['Q2', 1, 'truefalse'],
        ], ['grade' => 10.0, 'sumgrades' => 2]);
    }

    /**
     * Get the question ids of the quiz slots, keyed by slot number.
     *
     * @param \mod_quiz\quiz_settings $quizobj the quiz.
     * @return array slot number => question id.
     */
    private function get_slot_questionids(\mod_quiz\quiz_settings $quizobj): array {
        $quizobj->preload_questions();
        $ids = [];
        foreach ($quizobj->get_questions(null, false) as $question) {
            $ids[$question->slot] = (int) $question->questionid;
        }
        return $ids;
    }

    /**
     * Create a group membership rule for one question.
     *
     * @param int $quizid the quiz id.
     * @param int $questionid the question id.
     * @param int $groupid the group the user must belong to.
     */
    private function create_rule(int $quizid, int $questionid, int $groupid): void {
        global $DB;
        $DB->insert_record('quizaccess_condq_rule', (object) [
            'quizid' => $quizid,
            'questionid' => $questionid,
            'conditiontype' => 'group',
            'conditionvalue' => $groupid,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * An unmet group condition replaces the question with the placeholder.
     */
    public function test_unmet_group_condition_hides_question(): void {
        global $DB;

        $this->resetAfterTest();

        $quizobj = $this->create_quiz();
        $course = $quizobj->get_course();
        $slotqids = $this->get_slot_questionids($quizobj);
        $q1id = $slotqids[1];

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->create_rule($quizobj->get_quizid(), $q1id, $group->id);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $attempt = $this->getDataGenerator()->get_plugin_generator('mod_quiz')
            ->create_attempt($quizobj->get_quizid(), $user->id);

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $placeholderid = placeholder::get_placeholder_questionid(
            $quizobj->get_quizid(), $quizobj->get_context());

        // The first slot now holds the placeholder with zero marks.
        $this->assertEquals($placeholderid, $quba->get_question_attempt(1)->get_question_id());
        $this->assertEquals(0.0, $quba->get_question_attempt(1)->get_max_mark());
        // The second slot is untouched.
        $this->assertEquals($slotqids[2], $quba->get_question_attempt(2)->get_question_id());

        // The attempt maximum reflects only the visible questions.
        $rec = $DB->get_record('quizaccess_condq_attempt', ['attemptid' => $attempt->id]);
        $this->assertEquals(1.0, $rec->maxmark);
        // Core leaves sumgrades null until the attempt is graded.
        $this->assertNull($DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]));

        // The hidden mapping exists for a later restore.
        $this->assertTrue($DB->record_exists('quizaccess_condq_hidden',
            ['attemptid' => $attempt->id, 'slot' => 1, 'questionid' => $q1id]));
    }

    /**
     * A met group condition keeps the question in the attempt.
     */
    public function test_met_group_condition_keeps_question(): void {
        global $DB;

        $this->resetAfterTest();

        $quizobj = $this->create_quiz();
        $course = $quizobj->get_course();
        $slotqids = $this->get_slot_questionids($quizobj);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->create_rule($quizobj->get_quizid(), $slotqids[1], $group->id);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        groups_add_member($group, $user);
        $this->setUser($user);

        $attempt = $this->getDataGenerator()->get_plugin_generator('mod_quiz')
            ->create_attempt($quizobj->get_quizid(), $user->id);

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $this->assertEquals($slotqids[1], $quba->get_question_attempt(1)->get_question_id());

        $rec = $DB->get_record('quizaccess_condq_attempt', ['attemptid' => $attempt->id]);
        $this->assertEquals(2.0, $rec->maxmark);
        $this->assertFalse($DB->record_exists('quizaccess_condq_hidden', ['attemptid' => $attempt->id]));
    }

    /**
     * Finishing an attempt normalises the sumgrades and the final grade.
     */
    public function test_finishing_normalises_grade(): void {
        global $DB;

        $this->resetAfterTest();

        $quizobj = $this->create_quiz();
        $course = $quizobj->get_course();
        $slotqids = $this->get_slot_questionids($quizobj);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->create_rule($quizobj->get_quizid(), $slotqids[1], $group->id);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attempt = $quizgenerator->create_attempt($quizobj->get_quizid(), $user->id);

        // Answer the second question correctly and finish the attempt.
        $quizgenerator->submit_responses($attempt->id, [2 => get_string('true', 'qtype_truefalse')], false, true);

        // The user saw a maximum of 1 mark and scored 1: the attempt counts as 2 out of 2,
        // which is 10 grade points for a quiz graded out of 10.
        $this->assertEquals(2.0, $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]));
        $this->assertEquals(10.0, $DB->get_field('quiz_grades', 'grade',
            ['quiz' => $quizobj->get_quizid(), 'userid' => $user->id]));
    }

    /**
     * Deleting an attempt removes the plugin records.
     */
    public function test_deleting_attempt_removes_records(): void {
        global $DB;

        $this->resetAfterTest();

        $quizobj = $this->create_quiz();
        $course = $quizobj->get_course();
        $slotqids = $this->get_slot_questionids($quizobj);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->create_rule($quizobj->get_quizid(), $slotqids[1], $group->id);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $attempt = $this->getDataGenerator()->get_plugin_generator('mod_quiz')
            ->create_attempt($quizobj->get_quizid(), $user->id);

        $this->assertTrue($DB->record_exists('quizaccess_condq_attempt', ['attemptid' => $attempt->id]));

        quiz_delete_attempt($attempt->id, $quizobj->get_quiz());

        $this->assertFalse($DB->record_exists('quizaccess_condq_attempt', ['attemptid' => $attempt->id]));
        $this->assertFalse($DB->record_exists('quizaccess_condq_hidden', ['attemptid' => $attempt->id]));
    }

    /**
     * A built-on-last attempt restores a question whose condition is now met.
     */
    public function test_built_on_last_attempt_restores_met_question(): void {
        global $DB;

        $this->resetAfterTest();

        $quizobj = $this->create_quiz();
        $course = $quizobj->get_course();
        $slotqids = $this->get_slot_questionids($quizobj);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->create_rule($quizobj->get_quizid(), $slotqids[1], $group->id);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $first = $quizgenerator->create_attempt($quizobj->get_quizid(), $user->id);
        $quizgenerator->submit_responses($first->id, [], false, true);

        // The user joins the group and starts another attempt on the basis of the last one.
        groups_add_member($group, $user);
        $second = $quizgenerator->create_attempt($quizobj->get_quizid(), $user->id);

        $quba = \question_engine::load_questions_usage_by_activity($second->uniqueid);
        $this->assertEquals($slotqids[1], $quba->get_question_attempt(1)->get_question_id());
        $this->assertFalse($DB->record_exists('quizaccess_condq_hidden', ['attemptid' => $second->id]));
    }
}
