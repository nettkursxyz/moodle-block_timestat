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
 * This file contains functions used by the block timestat
 *
 * This files lists the functions that are used during the log report generation.
 *
 * @package    block_timestat
 * @copyright  2014 Barbara Dębska, Łukasz Sanokowski, Łukasz Musiał
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_user\fields;

defined('MOODLE_INTERNAL') || die;

if (!defined('REPORT_LOG_MAX_DISPLAY')) {
    define('REPORT_LOG_MAX_DISPLAY', 150); // Days.
}

/**
 * This function is used to generate and display Mnet selector form
 *
 * @param int $hostid host id
 * @param stdClass $course course instance
 * @param int $selecteduser id of the selected user
 * @param string $selecteddatefrom Date from selected
 * @param string $selecteddateto Date to selected
 * @param int $modid number or 'site_errors'
 * @param int $selectedgroup Group to display
 * @param int $showcourses whether to show courses if we're over our limit.
 * @param int $showusers whether to show users if we're over our limit.
 * @param string $logformat Format of the logs (downloadascsv, showashtml, downloadasods, downloadasexcel)
 * @return void
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 * @uses CONTEXT_SYSTEM
 * @uses COURSE_MAX_COURSES_PER_DROPDOWN
 * @uses CONTEXT_COURSE
 * @uses SEPARATEGROUPS
 */
function block_timestat_report_log_print_mnet_selector_form($hostid, $course, $selecteduser = 0, $selecteddatefrom = 'today',
        $selecteddateto = 'today', $modid = 0, $selectedgroup = -1, $showcourses = 0,
        $showusers = 0, $logformat = 'showashtml', $context): void {

    global $USER, $CFG, $SITE, $DB, $SESSION;
    require_once($CFG->dirroot . '/mnet/peer.php');

    $mnetpeer = new mnet_peer();
    $mnetpeer->set_id($hostid);

    $sql = "SELECT DISTINCT course, hostid, coursename FROM {mnet_log}";
    $courses = $DB->get_records_sql($sql);
    $remotecoursecount = count($courses);

    // First check to see if we can override showcourses and showusers.
    $numcourses = $remotecoursecount + $DB->count_records('course');
    if ($numcourses < COURSE_MAX_COURSES_PER_DROPDOWN && !$showcourses) {
        $showcourses = 1;
    }

    $sitecontext = context_system::instance();

    // Context for remote data is always SITE.
    // Groups for remote data are always OFF.
    if ($hostid == $CFG->mnet_localhost_id) {

        // Setup for group handling.
        if ($course->groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
            $selectedgroup = -1;
            $showgroups = false;
        } else if ($course->groupmode) {
            $showgroups = true;
        } else {
            $selectedgroup = 0;
            $showgroups = false;
        }

        if ($selectedgroup === -1) {
            if (isset($SESSION->currentgroup[$course->id])) {
                $selectedgroup = $SESSION->currentgroup[$course->id];
            } else {
                $selectedgroup = groups_get_all_groups($course->id, $USER->id);
                if (is_array($selectedgroup)) {
                    $selectedgroup = array_shift(array_keys($selectedgroup));
                    $SESSION->currentgroup[$course->id] = $selectedgroup;
                } else {
                    $selectedgroup = 0;
                }
            }
        }

    } else {
        $context = $sitecontext;
    }

    // Get all the possible users.
    $users = [];

    // Define limitfrom and limitnum for queries below.
    // If $showusers is enabled... don't apply limitfrom and limitnum.
    $limitfrom = empty($showusers) ? 0 : '';
    $limitnum = empty($showusers) ? COURSE_MAX_USERS_PER_DROPDOWN + 1 : '';
    $allusernamefields = implode(',', fields::get_name_fields(true));

    if ($hostid == $CFG->mnet_localhost_id && $course->id != SITEID) {
        $courseusers = get_enrolled_users($context, '', $selectedgroup, 'u.id, ' . $allusernamefields,
                null, $limitfrom, $limitnum);
    } else {
        $courseusers = $DB->get_records('user', ['deleted' => 0], 'lastaccess DESC', 'id, ' . $allusernamefields,
                $limitfrom, $limitnum);
    }

    if (count($courseusers) < COURSE_MAX_USERS_PER_DROPDOWN && !$showusers) {
        $showusers = 1;
    }

    if ($showusers) {
        if ($courseusers) {
            foreach ($courseusers as $courseuser) {
                $users[$courseuser->id] = fullname($courseuser, has_capability('moodle/site:viewfullnames', $context));
            }
        }
        $users[$CFG->siteguest] = get_string('guestuser');
    }

    // Get all the hosts that have log records.
    $sql = "select distinct
                h.id,
                h.name
            from
                {mnet_host} h,
                {mnet_log} l
            where
                h.id = l.hostid
            order by
                h.name";

    if ($hosts = $DB->get_records_sql($sql)) {
        foreach ($hosts as $host) {
            $hostarray[$host->id] = $host->name;
        }
    }

    $hostarray[$CFG->mnet_localhost_id] = $SITE->fullname;
    asort($hostarray);

    $dropdown = [];

    foreach ($hostarray as $hostid => $name) {
        $courses = [];
        $sites = [];
        if ($CFG->mnet_localhost_id == $hostid) {
            if (has_capability('report/log:view', $sitecontext) && $showcourses) {
                if ($ccc = $DB->get_records("course", null, "fullname", "id,shortname,fullname,category")) {
                    foreach ($ccc as $cc) {
                        if ($cc->id == SITEID) {
                            $sites["$hostid/$cc->id"] = format_string($cc->fullname) . ' (' . get_string('site') . ')';
                        } else {
                            $courses["$hostid/$cc->id"] = format_string(get_course_display_name_for_list($cc));
                        }
                    }
                }
            }
        } else {
            if (has_capability('report/log:view', $sitecontext) && $showcourses) {
                $sql = "SELECT DISTINCT course, coursename FROM {mnet_log} where hostid = ?";
                if ($ccc = $DB->get_records_sql($sql, [$hostid])) {
                    foreach ($ccc as $cc) {
                        if (1 == $cc->course) { // TODO: this might be wrong - site course may have another id.
                            $sites["$hostid/$cc->course"] = $cc->coursename . ' (' . get_string('site') . ')';
                        } else {
                            $courses["$hostid/$cc->course"] = $cc->coursename;
                        }
                    }
                }
            }
        }

        asort($courses);
        $dropdown[] = [$name => ($sites + $courses)];
    }

    $activities = [];
    $selectedactivity = "";

    $modinfo = get_fast_modinfo($course);
    if (!empty($modinfo->cms)) {
        $section = 0;
        foreach ($modinfo->cms as $cm) {
            if (!$cm->uservisible || !$cm->has_view()) {
                continue;
            }
            if ($cm->sectionnum > 0 && $section <> $cm->sectionnum) {
                $activities["section/$cm->sectionnum"] = '--- ' . get_section_name($course, $cm->sectionnum) . ' ---';
            }
            $section = $cm->sectionnum;
            $modname = strip_tags($cm->get_formatted_name());
            if (core_text::strlen($modname) > 55) {
                $modname = core_text::substr($modname, 0, 50) . "...";
            }
            if (!$cm->visible) {
                $modname = "(" . $modname . ")";
            }
            $activities["$cm->id"] = $modname;

            if ($cm->id == $modid) {
                $selectedactivity = "$cm->id";
            }
        }
    }

    if (has_capability('report/log:view', $sitecontext) && !$course->category) {
        $activities["site_errors"] = get_string("siteerrors");
        if ($modid === "site_errors") {
            $selectedactivity = "site_errors";
        }
    }

    $strftimedate = get_string("strftimedate");
    $strftimedaydate = get_string("strftimedaydate");

    asort($users);

    // Prepare the list of action options.
    $actions = [
            'view' => get_string('view'),
            'add' => get_string('add'),
            'update' => get_string('update'),
            'delete' => get_string('delete'),
            '-view' => get_string('allchanges'),
    ];

    // Conditionally builds an array of course users and the guest user with their full names.
if ($showusers) {
    if ($courseusers) {
        foreach ($courseusers as $courseuser) {
            $users[$courseuser->id] = fullname($courseuser, has_capability('moodle/site:viewfullnames', $context));
        }
    }
    $users[$CFG->siteguest] = get_string('guestuser');
}
// If user doesn't have capability to view participants, only show themselves
if (!has_capability('moodle/course:viewparticipants', $context)) {
    $users = array(); // Clear the users array
    $users[$USER->id] = fullname($USER, has_capability('moodle/site:viewfullnames', $context));
}
    
    $timenow = time(); // GMT.

    // What day is it now for the user, and when is midnight that day (in GMT).
    $timemidnight = $today = usergetmidnight($timenow);

    // Put today up the top of the list.
    $dates = [
            "0" => get_string('alldays'),
            "$timemidnight" => get_string("today") . ", " . userdate($timenow, $strftimedate),
    ];

    if (!$course->startdate || ($course->startdate > $timenow)) {
        $course->startdate = $course->timecreated;
    }

    $numdates = 1;
    while ($timemidnight > $course->startdate && $numdates < 365) {
        $timemidnight = $timemidnight - 86400;
        $timenow = $timenow - 86400;
        $dates["$timemidnight"] = userdate($timenow, $strftimedaydate);
        $numdates++;
    }

    if (!empty($selecteddate)) {
        if ($selecteddate === "today") {
            $selecteddate = $today;
        }
    }

    echo "<form class=\"logselectform\" action=\"$CFG->wwwroot/blocks/timestat/index.php\" method=\"get\">\n";
    echo "<div>\n"; // Invisible fieldset here breaks wrapping.
    echo "<input type=\"hidden\" name=\"chooselog\" value=\"1\" />\n";
    echo "<input type=\"hidden\" name=\"showusers\" value=\"$showusers\" />\n";
    echo "<input type=\"hidden\" name=\"showcourses\" value=\"$showcourses\" />\n";
    if (has_capability('report/log:view', $sitecontext) && $showcourses) {
        $cid = empty($course->id) ? '1' : $course->id;
        echo html_writer::label(get_string('selectacoursesite'), 'menuhost_course', false, ['class' => 'accesshide']);
        echo html_writer::select($dropdown, "host_course", $hostid . '/' . $cid);
    } else {
        $courses = [];
        $courses[$course->id] = get_course_display_name_for_list($course) . ((empty($course->category)) ?
                        ' (' . get_string('site') . ') ' : '');
        echo html_writer::label(get_string('selectacourse'), 'menuid', false, ['class' => 'accesshide']);
        echo html_writer::select($courses, "id", $course->id, false);
        if (has_capability('report/log:view', $sitecontext)) {
            $a = new stdClass();
            $a->url = "$CFG->wwwroot/blocks/timestat/index.php?chooselog=0&group=$selectedgroup&user=$selecteduser"
                    . "&id=$course->id&date=$selecteddate&modid=$selectedactivity&showcourses=1&showusers=$showusers";
            print_string('logtoomanycourses', 'moodle', $a);
        }
    }

    if ($showgroups) {
        if ($cgroups = groups_get_all_groups($course->id)) {
            foreach ($cgroups as $cgroup) {
                $groups[$cgroup->id] = $cgroup->name;
            }
        } else {
            $groups = [];
        }
        echo html_writer::label(get_string('selectagroup'), 'menugroup', false, ['class' => 'accesshide']);
        echo html_writer::select($groups, "group", $selectedgroup, get_string("allgroups"));
    }

    if ($showusers) {
        echo html_writer::label(get_string('participantslist'), 'menuuser', false, ['class' => 'accesshide']);
        echo html_writer::select($users, "user", $selecteduser, get_string("allparticipants"));
    } else {
        $users = [];
        if (!empty($selecteduser)) {
            $user = $DB->get_record('user', ['id' => $selecteduser]);
            $users[$selecteduser] = fullname($user);
        } else {
            $users[0] = get_string('allparticipants');
        }
        echo html_writer::label(get_string('participantslist'), 'menuuser', false, ['class' => 'accesshide']);
        echo html_writer::select($users, "user", $selecteduser, false);
        $a = new stdClass();
        $a->url = "$CFG->wwwroot/blocks/timestat/index.php?chooselog=0&group=$selectedgroup&user=$selecteduser"
                . "&id=$course->id&date=$selecteddate&modid=$selectedactivity&showusers=1&showcourses=$showcourses";
        print_string('logtoomanyusers', 'moodle', $a);
    }

    echo html_writer::label(get_string('showreports'), 'menumodid', false, ['class' => 'accesshide']);
    echo html_writer::select($activities, "modid", $selectedactivity, get_string("allactivities"));
    echo html_writer::label(get_string('actions'), 'menumodaction', false, ['class' => 'accesshide']);

    $logformats = ['showashtml' => get_string('displayonpage'),
            'downloadasexcel' => get_string('downloadexcel')];
    echo html_writer::label(get_string('logsformat', 'report_log'), 'menulogformat', false, ['class' => 'accesshide']);
    echo html_writer::select($logformats, 'logformat', $logformat, false);
    $mform = new block_timestat_calendar();
    $mform->set_data(['datefrom' => $selecteddatefrom]);
    $mform->set_data(['dateto' => $selecteddateto]);
    $mform->display();

    echo '</div>';
    echo '</form>';
}

/**
 * This function is used to generate and display selector form
 *
 * @param stdClass $course course instance
 * @param int $selecteduser id of the selected user
 * @param string $selecteddate Date selected
 * @param string $modid number or 'site_errors'
 * @param int $selectedgroup Group to display
 * @param int $showcourses whether to show courses if we're over our limit.
 * @param int $showusers whether to show users if we're over our limit.
 * @param string $logformat Format of the logs (downloadascsv, showashtml, downloadasods, downloadasexcel)
 * @return void
 * @uses CONTEXT_SYSTEM
 * @uses COURSE_MAX_COURSES_PER_DROPDOWN
 * @uses CONTEXT_COURSE
 * @uses SEPARATEGROUPS
 */
function block_timestat_report_log_print_selector_form($course, $selecteduser = 0, $selecteddate = 'today', $modid = 0,
        $selectedgroup = -1, $showcourses = 0, $showusers = 0,
        $logformat = 'showashtml', $context) {

    global $USER, $CFG, $DB, $SESSION;

    // First check to see if we can override showcourses and showusers.
    $numcourses = $DB->count_records("course");
    if ($numcourses < COURSE_MAX_COURSES_PER_DROPDOWN && !$showcourses) {
        $showcourses = 1;
    }

    $sitecontext = context_system::instance();

    // Setup for group handling.
    if ($course->groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
        $selectedgroup = -1;
        $showgroups = false;
    } else if ($course->groupmode) {
        $showgroups = true;
    } else {
        $selectedgroup = 0;
        $showgroups = false;
    }

    if ($selectedgroup === -1) {
        if (isset($SESSION->currentgroup[$course->id])) {
            $selectedgroup = $SESSION->currentgroup[$course->id];
        } else {
            $selectedgroup = groups_get_all_groups($course->id, $USER->id);
            if (is_array($selectedgroup)) {
                $selectedgroup = array_shift(array_keys($selectedgroup));
                $SESSION->currentgroup[$course->id] = $selectedgroup;
            } else {
                $selectedgroup = 0;
            }
        }
    }

    // Get all the possible users.
    $users = [];

    // Define limitfrom and limitnum for queries below.
    // If $showusers is enabled... don't apply limitfrom and limitnum.
    $limitfrom = empty($showusers) ? 0 : '';
    $limitnum = empty($showusers) ? COURSE_MAX_USERS_PER_DROPDOWN + 1 : '';
    $allusernamefields = implode(',', fields::get_name_fields(true));

    $courseusers = get_enrolled_users($context, '', $selectedgroup, 'u.id, ' . $allusernamefields,
            null, $limitfrom, $limitnum);

    if (count($courseusers) < COURSE_MAX_USERS_PER_DROPDOWN && !$showusers) {
        $showusers = 1;
    }

    if ($showusers) {
        if ($courseusers) {
            foreach ($courseusers as $courseuser) {
                $users[$courseuser->id] = fullname($courseuser, has_capability('moodle/site:viewfullnames', $context));
            }
        }
        $users[$CFG->siteguest] = get_string('guestuser');
    }

    if (has_capability('report/log:view', $sitecontext) && $showcourses) {
        if ($ccc = $DB->get_records("course", null, "fullname", "id,shortname,fullname,category")) {
            foreach ($ccc as $cc) {
                if ($cc->category) {
                    $courses["$cc->id"] = format_string(get_course_display_name_for_list($cc));
                } else {
                    $courses["$cc->id"] = format_string($cc->fullname) . ' (Site)';
                }
            }
        }
        asort($courses);
    }

    $activities = [];
    $selectedactivity = "";

    $modinfo = get_fast_modinfo($course);
    if (!empty($modinfo->cms)) {
        $section = 0;
        foreach ($modinfo->cms as $cm) {
            if (!$cm->uservisible || !$cm->has_view()) {
                continue;
            }
            if ($cm->sectionnum > 0 && $section <> $cm->sectionnum) {
                $activities["section/$cm->sectionnum"] = '--- ' . get_section_name($course, $cm->sectionnum) . ' ---';
            }
            $section = $cm->sectionnum;
            $modname = strip_tags($cm->get_formatted_name());
            if (core_text::strlen($modname) > 55) {
                $modname = core_text::substr($modname, 0, 50) . "...";
            }
            if (!$cm->visible) {
                $modname = "(" . $modname . ")";
            }
            $activities["$cm->id"] = $modname;

            if ($cm->id == $modid) {
                $selectedactivity = "$cm->id";
            }
        }
    }

    if (has_capability('report/log:view', $sitecontext) && ($course->id == SITEID)) {
        $activities["site_errors"] = get_string("siteerrors");
        if ($modid === "site_errors") {
            $selectedactivity = "site_errors";
        }
    }

    $strftimedate = get_string("strftimedate");
    $strftimedaydate = get_string("strftimedaydate");

    asort($users);

    // Prepare the list of action options.
    $actions = [
            'view' => get_string('view'),
            'add' => get_string('add'),
            'update' => get_string('update'),
            'delete' => get_string('delete'),
            '-view' => get_string('allchanges'),
    ];

    // Get all the possible dates.
    // Note that we are keeping track of real (GMT) time and user time.
    // User time is only used in displays - all calcs and passing is GMT.

    $timenow = time(); // GMT.

    // What day is it now for the user, and when is midnight that day (in GMT).
    $timemidnight = $today = usergetmidnight($timenow);

    // Put today up the top of the list.
    $dates = ["$timemidnight" => get_string("today") . ", " . userdate($timenow, $strftimedate)];

    if (!$course->startdate || ($course->startdate > $timenow)) {
        $course->startdate = $course->timecreated;
    }

    $numdates = 1;
    while ($timemidnight > $course->startdate && $numdates < 365) {
        $timemidnight = $timemidnight - 86400;
        $timenow = $timenow - 86400;
        $dates["$timemidnight"] = userdate($timenow, $strftimedaydate);
        $numdates++;
    }

    if ($selecteddate == "today") {
        $selecteddate = $today;
    }

    echo "<form class=\"logselectform\" action=\"$CFG->wwwroot/blocks/timestat/index.php\" method=\"get\">\n";
    echo "<div>\n";
    echo "<input type=\"hidden\" name=\"chooselog\" value=\"1\" />\n";
    echo "<input type=\"hidden\" name=\"showusers\" value=\"$showusers\" />\n";
    echo "<input type=\"hidden\" name=\"showcourses\" value=\"$showcourses\" />\n";
    if (has_capability('report/log:view', $sitecontext) && $showcourses) {
        echo html_writer::label(get_string('selectacourse'), 'menuid', false, ['class' => 'accesshide']);
        echo html_writer::select($courses, "id", $course->id, false);
    } else {
        $courses = [];
        $courses[$course->id] = get_course_display_name_for_list($course) . (($course->id == SITEID) ?
                        ' (' . get_string('site') . ') ' : '');
        echo html_writer::label(get_string('selectacourse'), 'menuid', false, ['class' => 'accesshide']);
        echo html_writer::select($courses, "id", $course->id, false);
        if (has_capability('report/log:view', $sitecontext)) {
            $a = new stdClass();
            $a->url = "$CFG->wwwroot/blocks/timestat/index.php?chooselog=0&group=$selectedgroup&user=$selecteduser"
                    . "&id=$course->id&date=$selecteddate&modid=$selectedactivity&showcourses=1&showusers=$showusers";
            print_string('logtoomanycourses', 'moodle', $a);
        }
    }

    if ($showgroups) {
        if ($cgroups = groups_get_all_groups($course->id)) {
            foreach ($cgroups as $cgroup) {
                $groups[$cgroup->id] = $cgroup->name;
            }
        } else {
            $groups = [];
        }
        echo html_writer::label(get_string('selectagroup'), 'menugroup', false, ['class' => 'accesshide']);
        echo html_writer::select($groups, "group", $selectedgroup, get_string("allgroups"));
    }

    // Conditionally builds an array of course users and the guest user with their full names.
    if ($showusers) {
    if ($courseusers) {
        foreach ($courseusers as $courseuser) {
            $users[$courseuser->id] = fullname($courseuser, has_capability('moodle/site:viewfullnames', $context));
        }
    }
    $users[$CFG->siteguest] = get_string('guestuser');
}

// If user doesn't have capability to view participants, only show themselves
if (!has_capability('moodle/course:viewparticipants', $context)) {
    $users = array(); // Clear the users array
    $users[$USER->id] = fullname($USER, has_capability('moodle/site:viewfullnames', $context));
}
    if ($showusers) {
        echo html_writer::label(get_string('selctauser'), 'menuuser', false, ['class' => 'accesshide']);
        echo html_writer::select($users, "user", $selecteduser, get_string("allparticipants"));
    } else {
        $users = [];
        if (!empty($selecteduser)) {
            $user = $DB->get_record('user', ['id' => $selecteduser]);
            $users[$selecteduser] = fullname($user);
        } else {
            $users[0] = get_string('allparticipants');
        }
        echo html_writer::label(get_string('selctauser'), 'menuuser', false, ['class' => 'accesshide']);
        echo html_writer::select($users, "user", $selecteduser, false);
        $a = new stdClass();
        $a->url = "$CFG->wwwroot/blocks/timestat/index.php?chooselog=0&group=$selectedgroup&user=$selecteduser"
                . "&id=$course->id&date=$selecteddate&modid=$selectedactivity&showusers=1&showcourses=$showcourses";
        print_string('logtoomanyusers', 'moodle', $a);
    }

    echo html_writer::label(get_string('activities'), 'menumodid', false, ['class' => 'accesshide']);
    echo html_writer::select($activities, "modid", $selectedactivity, get_string("allactivities"));
    echo html_writer::label(get_string('actions'), 'menumodaction', false, ['class' => 'accesshide']);

    $logformats = ['showashtml' => get_string('displayonpage'),
            'downloadasexcel' => get_string('downloadexcel')];

    echo html_writer::label(get_string('logsformat', 'report_log'), 'menulogformat', false, ['class' => 'accesshide']);
    echo html_writer::select($logformats, 'logformat', $logformat, false);
    $mform = new block_timestat_calendar();
    $mform->set_data(['datefrom' => $course->startdate]);
    $mform->display();
    echo '</div>';
    echo '</form>';
}

/**
 * This function is used to generate and display selector form
 *
 * @param stdClass $course course instance
 * @param int $user user instance
 * @param int $datefrom
 * @param int $dateto
 * @param string $order
 * @param int $page
 * @param int $perpage
 * @param string $url
 * @param string $modname
 * @param int $modid
 * @param string $modaction
 * @param int $groupid
 * @throws coding_exception
 */
function block_timestat_print_log($course, $user = 0, $datefrom = 0, $dateto = 0, $order = "l.timecreated ASC", $page = 0,
        $perpage = 100, $url = "", $modname = "", $modid = 0, $modaction = "", $groupid = 0, $context) {

    global $CFG, $OUTPUT;

    if (!$logs = block_timestat_build_logs_array($course, $user, $datefrom, $dateto, $order, $page * $perpage, $perpage,
            $modname, $modid, $modaction, $groupid, $context)) {
        echo $OUTPUT->notification(get_string('nologs', 'block_timestat'));
        echo $OUTPUT->footer();
        exit;
    }

    $courses = [];

    if ($course->id == SITEID) {
        $courses[0] = '';
        if ($ccc = get_courses('all', 'c.id ASC', 'c.id,c.shortname')) {
            foreach ($ccc as $cc) {
                $courses[$cc->id] = $cc->shortname;
            }
        }
    } else {
        $courses[$course->id] = $course->shortname;
    }

    $totalcount = $logs['totalcount'];
    $count = 0;
    $ldcache = [];
    $tt = getdate(time());
    $today = mktime(0, 0, 0, $tt["mon"], $tt["mday"], $tt["year"]);

    $strftimedatetime = get_string("strftimedatetime");

    echo "<div class=\"info\">\n";
    print_string("displayingrecords", "", $totalcount);
    echo "</div>\n";

    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, "$url&perpage=$perpage");

    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->align = ['right', 'left', 'left'];
    $table->head = [
            get_string('fullnameuser'),
            get_string('time'),

    ];
    $table->data = [];

    if ($course->id == SITEID) {
        array_unshift($table->align, 'left');
        array_unshift($table->head, get_string('course'));
    }

    // Make sure that the logs array is an array, even it is empty, to avoid warnings from the foreach.
    if (empty($logs['logs'])) {
        $logs['logs'] = [];
    }

    foreach ($logs['logs'] as $log) {

        $row = [];
        if ($course->id == SITEID) {
            if (empty($log->course)) {
                $row[] = get_string('site');
            } else {
                $row[] = "<a href=\"$CFG->wwwroot/course/view.php?id=$log->course\">" .
                        format_string($courses[$log->course]) . "</a>";
            }
        }

        $row[] = html_writer::link(new moodle_url("/user/view.php?id={$log->userid}"),
                fullname($log, has_capability('moodle/site:viewfullnames', $context)));

        $row[] = block_timestat_seconds_to_stringtime($log->{'timespent'});
        $table->data[] = $row;
    }

    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($totalcount, $page, $perpage, "$url&perpage=$perpage");
}

/**
 * This function is used to build array of logs
 *
 * @param stdClass $course course instance
 * @param int $user userid
 * @param int $datefrom
 * @param int $dateto
 * @param string $order
 * @param int $limitfrom
 * @param int $limitnum
 * @param string $modname
 * @param int $modid
 * @param string $modaction
 * @param int $groupid
 * @return array
 * @throws coding_exception
 */
function block_timestat_build_logs_array($course, $user = 0, $datefrom = 0, $dateto = 0, $order = "l.timecreated ASC",
        $limitfrom = 0, $limitnum = 0,
        $modname = "", $modid = 0, $modaction = "", $groupid = 0, $context): array {

    global $DB, $SESSION, $USER;
    // It is assumed that $date is the GMT time of midnight for that day,
    // And so the next 86400 seconds worth of logs are printed.

    // Setup for group handling.

    // If the group mode is separate, and this user does not have editing privileges,
    // Then only the user's group can be viewed.
    if ($course->groupmode == SEPARATEGROUPS && !has_capability('moodle/course:managegroups',
                    , $context)) {
        if (isset($SESSION->currentgroup[$course->id])) {
            $groupid = $SESSION->currentgroup[$course->id];
        } else {
            $groupid = groups_get_all_groups($course->id, $USER->id);
            if (is_array($groupid)) {
                $groupid = array_shift(array_keys($groupid));
                $SESSION->currentgroup[$course->id] = $groupid;
            } else {
                $groupid = 0;
            }
        }
    } else {
        if (!$course->groupmode) {
            $groupid = 0;
        }
    }
    $joins = [];
    $params = [];

    if ($course->id != SITEID || $modid != 0) {
        $joins[] = "l.courseid = :courseid";
        $params['courseid'] = $course->id;
    }

    if ($modname) {
        $joins[] = "l.module = :modname";
        $params['modname'] = $modname;
    }

    if ('site_errors' === $modid) {
        $joins[] = "( l.action='error' OR l.action='infected' )";
    } else if ($modid) {
        $joins[] = "l.contextinstanceid = :modid";
        $params['modid'] = $modid;
    }

    if ($modaction) {
        $firstletter = substr($modaction, 0, 1);
        if ($firstletter == '-') {
            $joins[] = $DB->sql_like('l.action', ':modaction', false, true, true);
            $params['modaction'] = '%' . substr($modaction, 1) . '%';
        } else {
            $joins[] = $DB->sql_like('l.action', ':modaction', false);
            $params['modaction'] = '%' . $modaction . '%';
        }
    }

    // Getting all members of a group.
    if ($groupid && !$user) {
        if ($gusers = groups_get_members($groupid)) {
            $gusers = array_keys($gusers);
            $joins[] = 'l.userid IN (' . implode(',', $gusers) . ')';
        } else {
            $joins[] = 'l.userid = 0'; // No users in groups, so we want something that will always be false.
        }
    } else {
        if ($user) {
            $params['userid'] = $user;
        }
    }

    if ($datefrom) {
        $enddate = $datefrom + 86400;
        $joins[] = "l.timecreated > :date AND l.timecreated < :enddate";
        $params['date'] = $datefrom;
        $params['enddate'] = $dateto;
    }

    $selector = implode(' AND ', $joins);
    $totalcount = 0;  // Initialise.
    $result = [];
    $result['logs'] = block_timestat_get_logs($selector, $totalcount, $params, $limitfrom, $limitnum);
    $result['totalcount'] = $totalcount;
    return $result;
}

/**
 * Select all log records based on SQL criteria
 *
 * @param string $select SQL select criteria
 * @param int $totalcount Passed in by reference.
 * @param array $params named sql type params
 * @param int $limitfrom return a subset of records, starting at this point (optional, required if $limitnum is set)
 * @param int $limitnum return a subset comprising this many records (optional, required if $limitfrom is set)
 * @return array
 */
function block_timestat_get_logs($select, &$totalcount, array $params = null, $limitfrom = 0, $limitnum = 0) {

    global $DB, $CFG;

    $selectsql = "";
    $countsql = "";
    $userid = "";
    $andcount = "";
    $userid = $params['userid'] ?? 0;

    if ($CFG->dbtype != 'mysqli') {
        $select = str_replace('l.', 'l2.', $select);
        if ($select) {
            if ($userid == 0) {
                $andcount = ' AND f2.timespent > 0 ';
            }
            $select = " AND $select" . $andcount;
        }
    } else {
        if ($userid == 0) {
            $andcount = ' AND bt.timespent > 0 ';
        }
        $select = "WHERE $select" . $andcount;
    }
    $allusernamefields = implode(',', fields::get_name_fields(true));

    $useridselect = '';

    if ($userid) {
        $useridselect .= "AND userid = :userid";
    }

    if ($CFG->dbtype != 'mysqli') {
        $sql = "
        SELECT DISTINCT l.userid, $allusernamefields,
        (SELECT SUM(f2.timespent) FROM {logstore_standard_log} l2
        JOIN {block_timestat} f2 ON f2.log_id = l2.id WHERE l2.userid =  l.userid $select)
        as timespent
        FROM  {logstore_standard_log}  l
        JOIN {block_timestat} f2 ON f2.log_id = l.id
        LEFT JOIN {user} u ON l.userid = u.id
        WHERE
        (SELECT SUM(f2.timespent) FROM {logstore_standard_log} l2
        JOIN {block_timestat} f2 ON f2.log_id = l2.id WHERE l2.userid =  l.userid
        ) > 0 $useridselect ORDER BY timespent DESC
        ";
    } else {
        $sql = "SELECT l.userid, SUM(bt.timespent) as timespent, $allusernamefields
        FROM {logstore_standard_log} l
        LEFT JOIN {user} u ON l.userid = u.id RIGHT JOIN {block_timestat} bt ON l.id = bt.log_id
        $select
        GROUP BY l.userid ORDER BY timespent DESC
        ";
    }
    $results = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
    $totalcount = count($results);
    return $results;

}

require_once($CFG->libdir . '/formslib.php');

/**
 *
 * Form to select start and end date ranges and session time.
 *
 * @package    block_timestat
 * @copyright  2010 onwards Barbara Dębska, Łukasz Musiał, Łukasz Sanokowski
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_timestat_calendar extends moodleform {

    /**
     * Define the form - called by parent constructor
     */
    public function definition() {
        $mform = &$this->_form;
        $mform->addElement('date_time_selector', 'datefrom', get_string('start', 'block_timestat'));
        $mform->addElement('date_time_selector', 'dateto', get_string('end', 'block_timestat'));
        // Buttons.
        $this->add_action_buttons(false, get_string('calculate', 'block_timestat'));
    }
}

/**
 * Function to print the log in xls format.
 *
 * @param stdClass $course
 * @param int $user
 * @param int $datefrom
 * @param int $dateto
 * @param string $modname
 * @param int $modid
 * @param string $modaction
 * @param int $groupid
 * @param string $order
 * @throws coding_exception
 */
function block_timestat_print_log_xls($course, $user, $datefrom, $dateto, $modname, $modid, $modaction, $groupid, $order = 'l.time DESC') {

    global $CFG;

    require_once("$CFG->libdir/excellib.class.php");

    if (!$logs = block_timestat_build_logs_array($course, $user, $datefrom, $dateto, $order, '', '',
            $modname, $modid, $modaction, $groupid)) {
        return false;
    }
    $courses = [];

    if ($course->id == SITEID) {
        $courses[0] = '';
        if ($ccc = get_courses('all', 'c.id ASC', 'c.id,c.shortname')) {
            foreach ($ccc as $cc) {
                $courses[$cc->id] = $cc->shortname;
            }
        }
    } else {
        $courses[$course->id] = $course->shortname;
    }

    $count = 0;
    $ldcache = [];
    $tt = getdate(time());
    $today = mktime(0, 0, 0, $tt["mon"], $tt["mday"], $tt["year"]);

    $strftimedatetime = get_string("strftimedatetime");

    $nropages = ceil(count($logs) / (EXCELROWS - FIRSTUSEDEXCELROW + 1));
    $filename = 'logs_' . userdate(time(), get_string('backupnameformat', 'langconfig'), 99, false);
    $filename .= '.xls';

    $workbook = new MoodleExcelWorkbook('-');
    $workbook->send($filename);

    $worksheet = [];
    $headers = [get_string('fullnameuser'), get_string('time')];

    // Creating worksheets.
    for ($wsnumber = 1; $wsnumber <= $nropages; $wsnumber++) {
        $sheettitle = get_string('logs') . ' ' . $wsnumber . '-' . $nropages;
        $worksheet[$wsnumber] = $workbook->add_worksheet($sheettitle);
        $worksheet[$wsnumber]->set_column(1, 1, 30);
        $worksheet[$wsnumber]->write_string(0, 0, get_string('savedat') .
                userdate(time(), $strftimedatetime));
        $col = 0;
        foreach ($headers as $item) {
            $worksheet[$wsnumber]->write(FIRSTUSEDEXCELROW - 1, $col, $item, '');
            $col++;
        }
    }

    if (empty($logs['logs'])) {
        $workbook->close();
        return true;
    }

    $formatdate = $workbook->add_format();
    $formatdate->set_num_format(get_string('log_excel_date_format'));

    $row = FIRSTUSEDEXCELROW;
    $wsnumber = 1;
    $myxls = $worksheet[$wsnumber];
    foreach ($logs['logs'] as $log) {

        if ($nropages > 1 && $row > EXCELROWS) {
            $wsnumber++;
            $myxls = $worksheet[$wsnumber];
            $row = FIRSTUSEDEXCELROW;
        }

        $fullname = $log->firstname . " " . $log->lastname;
        $myxls->write($row, 0, $fullname, '');
        $myxls->write($row, 1, block_timestat_seconds_to_stringtime($log->{'timespent'}), '');
        $row++;
    }
    $workbook->close();
    return true;
}

/**
 * Function to convert a number of seconds to a string with hours, minutes and seconds.
 *
 * @param int $seconds
 * @return string
 * @throws dml_exception|coding_exception
 */
function block_timestat_seconds_to_stringtime($seconds) {
    $conmin = 60;
    $conhour = $conmin * 60;
    $conday = $conhour * 24;

    $tempday = (int) ((int) $seconds / (int) $conday);
    $seconds = $seconds - $tempday * $conday;
    $temphour = (int) ((int) $seconds / (int) $conhour);
    $seconds = $seconds - $temphour * $conhour;
    $tempmin = (int) ((int) $seconds / $conmin);
    $seconds = $seconds - $tempmin * $conmin;

    $str = '';
    if ($tempday != 0) {
        $str = $str . $tempday . get_string('days', 'block_timestat');
    }
    if ($temphour != 0) {
        $str = $str . $temphour . get_string('hours', 'block_timestat');
    }
    if ($tempmin != 0) {
        $str = $str . $tempmin . get_string('minuts', 'block_timestat');
    }
    return $str . $seconds . get_string('seconds', 'block_timestat');
}

/**
 * Function to get the user last log by contextid
 *
 * @param int $contextid
 * @throws dml_exception
 */
function block_timestat_get_user_last_log_by_contextid(int $contextid): stdClass {
    global $DB, $USER;
    $logs = $DB->get_records('logstore_standard_log',
            ['contextid' => $contextid, 'userid' => $USER->id], 'timecreated DESC', '*', 0, 1);
    return reset($logs);
}
