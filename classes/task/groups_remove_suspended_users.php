<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled task to remove suspended users from groups
 * 
 * @author      Blackboard - Belinda O'Mahoney <belinda.omahoney@gmail.com>
 * @package     local_removesuspended
 */

namespace local_removesuspended\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once $CFG->dirroot.'/group/lib.php';

class groups_remove_suspended_users extends \core\task\scheduled_task {

    /**
     * Descriptive name for Admins
     * 
     * @return string
     */
    public function get_name() {
        return get_string('groupsremovesuspendedusers', 'local_blackboard');
    }

    /**
     * Remove suspended users from groups
     * 
     * Checks log file for user_enrollment_updated events since yesterday (ie 
     * users being suspended from a course), and extracts the relevant courseids.
     * Finds suspended users for relevant courses and associated groups.
     * Removes users from groups.
     * Sends email to course instructors with information.
     * 
     * @return true
     */
    public function execute() {
        global $DB, $OUTPUT;

        $inthepast = time()-3600; // an hour ago

        // Get logtable name
        $logmanager = get_log_manager();
        $readers = $logmanager->get_readers('core\log\sql_reader');
        $reader = reset($readers);
        if (empty($reader)) {
            // Can't get logs if we don't have a reader.
            mtrace("Could not find any log readers, skipping");
            return;
        }
        $logtable = $reader->get_internal_log_table_name();

        // Get courseids where user enrollments have been updated since $inthepast
        $sql = "SELECT DISTINCT l.courseid
                  FROM {".$logtable."} l
                 WHERE l.eventname LIKE '%user_enrolment_updated%'
                   AND l.timecreated > :inthepast";
        $params = [
            'inthepast' => (int)$inthepast,
            ];
        $log = $DB->get_records_sql($sql, $params);

        $courseids = array_map(function($event) {
            return $event->courseid;
        }, $log);

        // Variables to use later
        $role = $DB->get_record('role', array('shortname' => 'instr'));
        $noreplyuser = \core_user::get_noreply_user();

        foreach($courseids as $courseid) {
            $coursecontext = \context_course::instance($courseid);

            // Get suspended userids per course
            $suspendeduserids = get_suspended_userids($coursecontext);

            if ( $suspendeduserids ) {
                list($suspendeduseridsstring, $suspendeduseridsparams) 
                            = $DB->get_in_or_equal($suspendeduserids, SQL_PARAMS_NAMED, 'suspuserid');

                // Get groups for suspended users
                $sql = "SELECT g.id as groupid,
                               gm.userid,
                               g.name as groupname,
                               u.firstname, u.lastname, u.middlename,
                               u.firstnamephonetic, u.lastnamephonetic, u.alternatename,
                               c.shortname
                          FROM {groups_members} gm
                          JOIN {groups} g ON (g.id = gm.groupid)
                          JOIN {user} u ON (u.id = gm.userid)
                          JOIN {course} c ON (c.id = g.courseid)
                         WHERE gm.userid $suspendeduseridsstring AND g.courseid = :courseid
                         ORDER BY u.lastname, u.firstname";
                $params = array_merge($suspendeduseridsparams, [
                    'courseid' => $courseid,
                ]);

                $usergroups = $DB->get_recordset_sql($sql, $params);
                $usergroupinfo = [];
                $coursename = false;
                foreach ($usergroups as $ug) {

                    // Remove suspended users from their groups
                    groups_remove_member($ug->groupid, $ug->userid);

                    $usergroupinfo[] = [
                        'groupname' => $ug->groupname,
                        'username' => fullname($ug),
                    ];
                    if (!$coursename) {
                        $coursename = $ug->shortname;
                    }
                }
                $usergroups->close();

                // Send email to course instructors
                if (!empty($usergroupinfo) && $role) { // Check there is info to send and someone to send it to
                    $subject = get_string('groupsremovesuspendedusersemailsubject', 'local_blackboard', $coursename);
                    $emailinfo = [
                        'usergroupinfo' => $usergroupinfo,
                    ];

                    $instructors = get_role_users($role->id, $coursecontext);

                    foreach($instructors as $instr) {
                        $emailinfo['instructorname'] = fullname($instr);

                        $emailhtml = $OUTPUT->render_from_template('local_blackboard/groups_remove_suspended_users_email_html', $emailinfo);
                        $emailtext = $OUTPUT->render_from_template('local_blackboard/groups_remove_suspended_users_email_text', $emailinfo);

                        email_to_user($instr, $noreplyuser, $subject, $emailtext, $emailhtml);
                    }
                }
            }
        }

        return true;
    }
}