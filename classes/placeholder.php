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
 * Management of the per-quiz placeholder question.
 *
 * Questions that are hidden because their condition is not met are replaced
 * in place by a zero-mark description question. Because the question usage is
 * persisted, the replacement must be a real question record, otherwise the
 * question engine could not load the usage later on. The plugin therefore
 * maintains one hidden description question per quiz, in the quiz's own
 * module question category, and reuses it for every replacement in that quiz.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_conditionalquestions;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates and caches the per-quiz placeholder question.
 */
final class placeholder {
    /** @var array question ids cached per quiz id. */
    private static array $questions = [];

    /**
     * Get the id of the placeholder question of a quiz, creating it if required.
     *
     * @param int $quizid the quiz id.
     * @param \context_module $context the quiz module context.
     * @return ?int the question id, or null if it could not be created.
     */
    public static function get_placeholder_questionid(int $quizid, \context_module $context): ?int {
        global $DB;

        if (isset(self::$questions[$quizid]) &&
                $DB->record_exists('question', ['id' => self::$questions[$quizid]])) {
            return self::$questions[$quizid];
        }

        $id = (int) get_config('quizaccess_conditionalquestions', 'placeholder_' . $quizid);
        if ($id && $DB->record_exists('question', ['id' => $id])) {
            self::$questions[$quizid] = $id;
            return $id;
        }
        $id = self::create_placeholder_question($quizid, $context);
        if ($id) {
            self::$questions[$quizid] = $id;
        }
        return $id;
    }

    /**
     * Whether the given question id belongs to the quiz's placeholder.
     *
     * @param int $questionid the question id to check.
     * @param int $quizid the quiz id.
     * @param \context_module $context the quiz module context.
     * @return bool whether this is the placeholder question of this quiz.
     */
    public static function is_placeholder(int $questionid, int $quizid, \context_module $context): bool {
        if ($questionid <= 0) {
            return false;
        }
        return $questionid === self::get_placeholder_questionid($quizid, $context);
    }

    /**
     * Get the placeholder question instance of a quiz.
     *
     * @param int $quizid the quiz id.
     * @param \context_module $context the quiz module context.
     * @return ?\question_definition the placeholder question, or null on failure.
     */
    public static function get_placeholder_question(int $quizid, \context_module $context): ?\question_definition {
        $id = self::get_placeholder_questionid($quizid, $context);
        if (!$id) {
            return null;
        }
        return \question_bank::load_question($id);
    }

    /**
     * Create the placeholder question in a dedicated subcategory of the quiz's
     * module question category and remember its id.
     *
     * @param int $quizid the quiz id.
     * @param \context_module $context the quiz module context.
     * @return ?int the new question id, or null on failure.
     */
    private static function create_placeholder_question(int $quizid, \context_module $context): ?int {
        global $DB;

        $defaultcategory = question_get_default_category($context->id, true);
        if (!$defaultcategory) {
            return null;
        }

        $category = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'parent' => $defaultcategory->id,
            'name' => get_string('placeholdercategory', 'quizaccess_conditionalquestions'),
        ], '*', IGNORE_MISSING);
        if (!$category) {
            $category = (object) [
                'name' => get_string('placeholdercategory', 'quizaccess_conditionalquestions'),
                'info' => get_string('placeholdercategoryinfo', 'quizaccess_conditionalquestions'),
                'contextid' => $context->id,
                'parent' => $defaultcategory->id,
                'sortorder' => 999,
                'stamp' => make_unique_id_code(),
            ];
            $category->id = $DB->insert_record('question_categories', $category);
        }

        // Create a new question, never a new version of an existing one.
        $question = (object) ['qtype' => 'description'];
        $form = (object) [
            'category' => $category->id . ',' . $context->id,
            'name' => get_string('placeholdername', 'quizaccess_conditionalquestions'),
            'questiontext' => [
                'text' => get_string('placeholdertext', 'quizaccess_conditionalquestions'),
                'format' => FORMAT_HTML,
            ],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
            'defaultmark' => 0,
        ];

        $saved = \question_bank::get_qtype('description')->save_question($question, $form);
        if (empty($saved->id)) {
            return null;
        }
        // Keep it out of the usual question bank listings.
        $DB->set_field('question_versions', 'status',
            \core_question\local\bank\question_version_status::QUESTION_STATUS_HIDDEN,
            ['questionid' => $saved->id]);
        set_config('placeholder_' . $quizid, (int) $saved->id, 'quizaccess_conditionalquestions');

        return (int) $saved->id;
    }
}
