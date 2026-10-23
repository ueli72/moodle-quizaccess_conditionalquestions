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
 * Tests for the shared placeholder question.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_conditionalquestions;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the shared placeholder question.
 *
 * @covers \quizaccess_conditionalquestions\placeholder
 */
final class placeholder_test extends \advanced_testcase {
    /**
     * The placeholder is created once and then reused.
     */
    public function test_placeholder_is_created_and_reused(): void {
        global $DB;

        $this->resetAfterTest();

        $quizobj = $this->create_quiz();
        $quizid = $quizobj->get_quizid();
        $context = $quizobj->get_context();

        $id = placeholder::get_placeholder_questionid($quizid, $context);
        $this->assertNotNull($id);
        $this->assertGreaterThan(0, $id);

        $question = $DB->get_record('question', ['id' => $id]);
        $this->assertEquals('description', $question->qtype);
        $status = $DB->get_field('question_versions', 'status', ['questionid' => $id]);
        $this->assertEquals(\core_question\local\bank\question_version_status::QUESTION_STATUS_HIDDEN, $status);

        // A second call returns the same question.
        $this->assertSame($id, placeholder::get_placeholder_questionid($quizid, $context));

        $this->assertTrue(placeholder::is_placeholder($id, $quizid, $context));
        $this->assertFalse(placeholder::is_placeholder($id + 1, $quizid, $context));
        $this->assertFalse(placeholder::is_placeholder(0, $quizid, $context));

        // The question can be loaded like any persisted question.
        $loaded = placeholder::get_placeholder_question($quizid, $context);
        $this->assertInstanceOf(\qtype_description_question::class, $loaded);
        $this->assertEquals($id, $loaded->id);
    }

    /**
     * Create a small test quiz.
     *
     * @return \mod_quiz\quiz_settings the quiz.
     */
    private function create_quiz(): \mod_quiz\quiz_settings {
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        return $quizgenerator->create_test_quiz([
            ['Q1', 1, 'truefalse'],
        ], ['grade' => 10.0, 'sumgrades' => 1]);
    }
}
