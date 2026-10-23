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
 * Privacy provider for the conditional questions quiz access rule.
 *
 * @package    quizaccess_conditionalquestions
 * @copyright  2026 Ueli Leutwyler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_conditionalquestions\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the data stored by the plugin.
     *
     * @param collection $collection the collection.
     * @return collection the collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('quizaccess_condq_attempt', [
            'attemptid' => 'privacy:metadata:quizaccess_condq_attempt:attemptid',
            'quizid' => 'privacy:metadata:quizaccess_condq_attempt:quizid',
            'userid' => 'privacy:metadata:quizaccess_condq_attempt:userid',
            'maxmark' => 'privacy:metadata:quizaccess_condq_attempt:maxmark',
            'rawsumgrades' => 'privacy:metadata:quizaccess_condq_attempt:rawsumgrades',
            'scaledsumgrades' => 'privacy:metadata:quizaccess_condq_attempt:scaledsumgrades',
            'timecreated' => 'privacy:metadata:quizaccess_condq_attempt:timecreated',
            'timemodified' => 'privacy:metadata:quizaccess_condq_attempt:timemodified',
        ], 'privacy:metadata:quizaccess_condq_attempt');
        $collection->add_database_table('quizaccess_condq_hidden', [
            'attemptid' => 'privacy:metadata:quizaccess_condq_hidden:attemptid',
            'slot' => 'privacy:metadata:quizaccess_condq_hidden:slot',
            'questionid' => 'privacy:metadata:quizaccess_condq_hidden:questionid',
            'maxmark' => 'privacy:metadata:quizaccess_condq_hidden:maxmark',
        ], 'privacy:metadata:quizaccess_condq_hidden');
        return $collection;
    }

    /**
     * Get the list of contexts containing user data for the user.
     *
     * @param int $userid the user id.
     * @return contextlist the context list.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {quizaccess_condq_attempt} t ON t.quizid = q.id
                 WHERE t.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Export the user data stored by the plugin in the given contexts.
     *
     * @param approved_contextlist $contextlist the approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $quizid = $DB->get_field('course_modules', 'instance',
                ['id' => $context->instanceid], IGNORE_MISSING);
            if (!$quizid) {
                continue;
            }
            $attempts = $DB->get_records('quizaccess_condq_attempt',
                ['quizid' => $quizid, 'userid' => $userid]);
            if (!$attempts) {
                continue;
            }
            $data = [];
            foreach ($attempts as $attempt) {
                $data[] = (object) [
                    'attemptid' => $attempt->attemptid,
                    'maxmark' => $attempt->maxmark,
                    'rawsumgrades' => $attempt->rawsumgrades,
                    'scaledsumgrades' => $attempt->scaledsumgrades,
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'quizaccess_conditionalquestions')],
                (object) $data
            );
        }
    }

    /**
     * Delete the user data stored by the plugin for the user.
     *
     * @param approved_contextlist $contextlist the approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $quizid = $DB->get_field('course_modules', 'instance',
                ['id' => $context->instanceid], IGNORE_MISSING);
            if (!$quizid) {
                continue;
            }
            self::delete_attempt_records("quizid = :quizid AND userid = :userid",
                ['quizid' => $quizid, 'userid' => $userid]);
        }
    }

    /**
     * Get the list of users who have data in the given context.
     *
     * @param userlist $userlist the user list.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT t.userid
                  FROM {quizaccess_condq_attempt} t
                  JOIN {course_modules} cm ON cm.instance = t.quizid
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * Delete the data stored by the plugin for the users in the user list.
     *
     * @param approved_userlist $userlist the approved user list.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['cmid' => $context->instanceid] + $inparams;
        self::delete_attempt_records(
            "quizid = (SELECT instance FROM {course_modules} WHERE id = :cmid) AND userid $insql",
            $params
        );
    }

    /**
     * Delete attempt records (and their hidden-slot records) matching the SQL.
     *
     * @param string $where the where clause.
     * @param array $params the SQL parameters.
     */
    private static function delete_attempt_records(string $where, array $params): void {
        global $DB;

        $DB->delete_records_select('quizaccess_condq_hidden',
            "attemptid IN (SELECT t.attemptid FROM {quizaccess_condq_attempt} t WHERE $where)", $params);
        $DB->delete_records_select('quizaccess_condq_attempt', $where, $params);
    }
}
