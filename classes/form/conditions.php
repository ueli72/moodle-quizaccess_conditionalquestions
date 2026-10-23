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
 * Configuration form for the conditional visibility of all quiz questions.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_conditionalquestions\form;

defined('MOODLE_INTERNAL') || die();

/**
 * Overview form: one condition and target selector per quiz question.
 */
final class conditions extends \moodleform {
    /** @var array the quiz questions. */
    private array $questions;
    /** @var array available condition targets. */
    private array $targets;

    /**
     * Build the condition and target elements for every question in the quiz.
     */
    public function definition(): void {
        global $PAGE;

        $mform = $this->_form;
        $this->questions = $this->_customdata['questions'];
        $this->targets = $this->_customdata['targets'];

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);

        $conditiontypes = [
            '' => get_string('none'),
            'group' => get_string('group'),
            'activity' => get_string('activity'),
            'competency' => get_string('competency', 'quizaccess_conditionalquestions'),
            'badge' => get_string('badge', 'quizaccess_conditionalquestions'),
        ];

        foreach ($this->questions as $question) {
            $id = (int) $question->questionid;

            $mform->addElement('html', \html_writer::tag(
                'h3',
                get_string('questionheading', 'quizaccess_conditionalquestions',
                    format_string($question->name)),
                ['class' => 'text-start mt-4 mb-2']
            ));

            if ($id <= 0) {
                // Random question slots cannot have a stable condition.
                $mform->addElement('html', \html_writer::tag(
                    'p',
                    get_string('randomquestionnote', 'quizaccess_conditionalquestions'),
                    ['class' => 'text-muted']
                ));
                continue;
            }

            $mform->addElement(
                'select',
                'condition_' . $id,
                get_string('condition', 'quizaccess_conditionalquestions'),
                $conditiontypes,
                ['class' => 'conditional-condition', 'data-question-id' => $id]
            );
            $mform->setType('condition_' . $id, PARAM_ALPHANUMEXT);

            foreach (['group', 'activity', 'competency', 'badge'] as $type) {
                $elementtype = in_array($type, ['competency', 'badge']) ? 'autocomplete' : 'select';
                $options = $this->targets[$type];
                $attributes = ['class' => 'conditional-target', 'data-condition-type' => $type,
                    'data-question-id' => $id];
                if ($elementtype === 'autocomplete') {
                    $elementoptions = [
                        'multiple' => false,
                        'noselectionstring' => get_string('selecttarget', 'quizaccess_conditionalquestions'),
                    ];
                } else {
                    $elementoptions = $attributes;
                }
                $mform->addElement(
                    $elementtype,
                    'target_' . $type . '_' . $id,
                    get_string('target', 'quizaccess_conditionalquestions'),
                    $options,
                    $elementtype === 'autocomplete' ? $elementoptions : $attributes
                );
                $mform->setType('target_' . $type . '_' . $id, PARAM_INT);
            }
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
