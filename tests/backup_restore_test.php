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
 * Tests for backup and restore of the conditional questions quiz access rule.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_conditionalquestions;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for backup and restore.
 *
 * @covers \backup_quizaccess_conditionalquestions_subplugin
 * @covers \restore_quizaccess_conditionalquestions_subplugin
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Rules survive a course backup and restore with remapped ids.
     */
    public function test_rules_survive_backup_and_restore(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $questiongenerator = $generator->get_plugin_generator('core_question');

        // Questions live in a course context category, like they would for a real course.
        $course = $generator->create_course();
        $quizrecord = $quizgenerator->create_instance([
            'course' => $course->id,
            'grade' => 10.0,
            'sumgrades' => 2,
        ]);
        $category = $questiongenerator->create_question_category(
            ['contextid' => \context_course::instance($course->id)->id]);
        $quizgenerator->create_quiz_structure($quizrecord, [
            ['Q1', 1, 'truefalse'],
            ['Q2', 1, 'truefalse'],
        ], $category->id);
        $cm = get_coursemodule_from_instance('quiz', $quizrecord->id, $course->id);
        $quizobj = new \mod_quiz\quiz_settings($quizrecord, $cm, $course);

        $slotqids = [];
        $quizobj->preload_questions();
        foreach ($quizobj->get_questions(null, false) as $question) {
            $slotqids[$question->slot] = (int) $question->questionid;
        }

        $group = $generator->create_group(['courseid' => $course->id]);
        $DB->insert_record('quizaccess_condq_rule', (object) [
            'quizid' => $quizobj->get_quizid(),
            'questionid' => $slotqids[1],
            'conditiontype' => 'group',
            'conditionvalue' => $group->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        // Back up the whole course.
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $admin = get_admin();
        $bc = new \backup_controller(\backup::TYPE_1COURSE, $course->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $admin->id);
        $bc->execute_plan();

        // Extract the backup file so the restore controller can find it.
        $backupid = $bc->get_backupid();
        $results = $bc->get_results();
        $fp = get_file_packer('application/vnd.moodle.backup');
        $filepath = $CFG->dataroot . '/temp/backup/' . $backupid;
        $results['backup_destination']->extract_to_pathname($fp, $filepath);
        $bc->destroy();

        // Restore it into a new course.
        $newcourse = $generator->create_course();
        $rc = new \restore_controller($backupid, $newcourse->id, \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL, $admin->id, \backup::TARGET_NEW_COURSE);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        // The new quiz has exactly one rule, pointing at the new question and the new group.
        $newquiz = $DB->get_record('quiz', ['course' => $newcourse->id], '*', MUST_EXIST);
        $rules = $DB->get_records('quizaccess_condq_rule', ['quizid' => $newquiz->id]);
        $this->assertCount(1, $rules);
        $rule = reset($rules);
        $this->assertEquals('group', $rule->conditiontype);

        $newquizobj = \mod_quiz\quiz_settings::create($newquiz->id);
        $newquizobj->preload_questions();
        $newslotqids = [];
        foreach ($newquizobj->get_questions(null, false) as $question) {
            $newslotqids[$question->slot] = (int) $question->questionid;
        }
        $this->assertEquals($newslotqids[1], $rule->questionid);
        $this->assertNotEquals($slotqids[1], $rule->questionid);

        $newgroups = groups_get_all_groups($newcourse->id);
        $this->assertCount(1, $newgroups);
        $this->assertEquals(reset($newgroups)->id, $rule->conditionvalue);
        $this->assertNotEquals($group->id, $rule->conditionvalue);
    }

    /**
     * Deleting a quiz module removes its rules and attempt data.
     */
    public function test_quiz_deletion_removes_rules(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $quizobj = $quizgenerator->create_test_quiz([
            ['Q1', 1, 'truefalse'],
        ], ['grade' => 10.0, 'sumgrades' => 1]);
        $course = $quizobj->get_course();
        $quizobj->preload_questions();
        $question = reset($quizobj->get_questions(null, false));

        $group = $generator->create_group(['courseid' => $course->id]);
        $DB->insert_record('quizaccess_condq_rule', (object) [
            'quizid' => $quizobj->get_quizid(),
            'questionid' => (int) $question->questionid,
            'conditiontype' => 'group',
            'conditionvalue' => $group->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $this->assertTrue($DB->record_exists('quizaccess_condq_rule',
            ['quizid' => $quizobj->get_quizid()]));

        course_delete_module($quizobj->get_cmid());

        $this->assertFalse($DB->record_exists('quizaccess_condq_rule',
            ['quizid' => $quizobj->get_quizid()]));
    }
}
