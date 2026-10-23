/**
 * Conditional questions quiz access rule.
 *
 * Shows/hides the target selector matching the selected condition type,
 * for every question on the configuration page.
 *
 * @module     quizaccess_conditionalquestions/conditions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    "use strict";

    /**
     * Wire up the show/hide behaviour for one question.
     *
     * @param {HTMLSelectElement} condition the condition select.
     */
    var wireUp = function(condition) {
        var questionid = condition.dataset.questionId;
        var types = ['group', 'activity', 'competency', 'badge'];
        var update = function() {
            types.forEach(function(type) {
                var element = document.querySelector('[name="target_' + type + '_' + questionid + '"]');
                if (!element) {
                    return;
                }
                var wrapper = element.closest('.fitem, .form-group');
                if (wrapper) {
                    wrapper.hidden = type !== condition.value;
                }
            });
        };
        condition.addEventListener('change', update);
        update();
    };

    return {
        /**
         * Initialise the condition configuration page.
         */
        init: function() {
            document.querySelectorAll('select[name^="condition_"]').forEach(wireUp);
        }
    };
});
