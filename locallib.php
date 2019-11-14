<?php
// This file is part of 3rd party created module for Moodle - http://moodle.org/.
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
 * @package    mod_peerwork
 * @copyright  2013 LEARNING TECHNOLOGY SERVICES
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
define('peerwork_STATUS_NOT_SUBMITTED', 0);
define('peerwork_STATUS_SUBMITTED', 1);
define('peerwork_STATUS_GRADED', 2);
define('peerwork_STATUS_NOT_SUBMITTED_CLOSED', 3);

define('peerwork_DUEDATE_NOT_USED', 0);
define('peerwork_DUEDATE_OK', 1);
define('peerwork_DUEDATE_PASSED', 2);

define('peerwork_FROMDATE_NOT_USED', 0);
define('peerwork_FROMDATE_OK', 1);
define('peerwork_FROMDATE_BEFORE', 2);

define('MOD_PEERWORK_JUSTIFICATION_DISABLED', 0);       // No justification required.
define('MOD_PEERWORK_JUSTIFICATION_HIDDEN', 1);         // Justification hidden to students and peers.
define('MOD_PEERWORK_JUSTIFICATION_VISIBLE_ANON', 2);   // Justification visible to all but anonymously.
define('MOD_PEERWORK_JUSTIFICATION_VISIBLE_USER', 3);   // Justification visible to all with identity visible.

require_once($CFG->dirroot . '/lib/grouplib.php');
require_once($CFG->dirroot . '/mod/peerwork/classes/algorithms/WebPAAlgorithm.php');

function peerwork_get_peers($course, $peerwork, $groupingid, $group = null) {
    global $USER;

    if (!$group) {
        $group = peerwork_get_mygroup($course, $USER->id, $groupingid);
    }

    $members = groups_get_members($group);
    $membersgradeable = $members;

    if (!$peerwork->selfgrading) {
        unset($membersgradeable[$USER->id]);
    }

    return $membersgradeable;
}

/**
 * Gets the group id for the group the user belongs to. Prints errors if
 * the user belongs to none or more than one group. Can be restricted to
 * groups within a grouping.
 *
 * @param int $courseid The id of the course.
 * @param int $userid The id of the user.
 * @param int $groupingid optional returns only groups in the specified grouping.
 * @param bool $die - @TODO check use of this parameter.
 * @return int The group id.
 */
function peerwork_get_mygroup($courseid, $userid, $groupingid = 0, $die = true) {
    global $CFG;

    $mygroups = groups_get_all_groups($courseid, $userid, $groupingid);

    if ($die && count($mygroups) == 0) {
        print_error("You do not belong to any group.");
    } else if ($die && count($mygroups) > 1) {
        print_error("You belong to more than one group, this is currently not supported.");
    }

    $mygroup = array_shift($mygroups);

    return $mygroup->id;
}

/**
 * Gets the status, one of peerwork_STATUS_*
 * @param $peerwork
 * @param int $group returns only groups in the specified grouping.
 */
function peerwork_get_status($peerwork, $group) {
    global $DB;
    $submission = $DB->get_record('peerwork_submission', array('assignment' => $peerwork->id, 'groupid' => $group->id));
    $status = new stdClass();
    $duedate = peerwork_due_date($peerwork);

    if ($submission && $submission->timegraded) {
        $status->code = peerwork_STATUS_GRADED;
        $user = $DB->get_record('user', array('id' => $submission->gradedby));
        $status->text = "Assessment graded by " . fullname($user) . ' on ' .
        userdate($submission->timegraded) . '. Grade: ' . $submission->grade;
        $status->text = "Assessment graded by " . fullname($user) . ' on ' .
        userdate($submission->timegraded) . '.';
        return $status;
    }

    if (!$submission && $duedate == peerwork_DUEDATE_PASSED) {
        $status->code = peerwork_STATUS_NOT_SUBMITTED_CLOSED;
        $status->text = "Nothing submitted yet but due date passed " . format_time(time() - $peerwork->duedate) . ' ago.';
        return $status;
    }

    if (!$submission) {
        $status->code = peerwork_STATUS_NOT_SUBMITTED;
        $status->text = "Nothing submitted yet";
        return $status;
    }

    if ($duedate == peerwork_DUEDATE_PASSED) {
        $submiter = $DB->get_record('user', array('id' => $submission->userid));
        $status->code = peerwork_STATUS_SUBMITTED;
        $status->text = "First submitted by " . fullname($submiter) . ' on ' . userdate($submission->timecreated) .
        ". Due date has passed " . format_time(time() - $peerwork->duedate) . ' ago.';
        return $status;
    } else {
        $submiter = $DB->get_record('user', array('id' => $submission->userid));
        $status->code = peerwork_STATUS_SUBMITTED;
        $status->text = "First submitted by " . fullname($submiter) . ' on ' . userdate($submission->timecreated);
        return $status;
    }
}

/**
 * FOLLOWING METHOD COPIED FROM ASSIGN TO CHECK IF ANY SUBMISSIONS OR GRADES YET.
 * Does an assignment have submission(s) or grade(s) already?
 *
 * @return bool
 */
function has_been_graded($peerwork) {

    global $DB;
    $submissions = $DB->get_records('peerwork_submission', array('assignment' => $peerwork->id));
    $status = new stdClass();
    $status->code = '';

    foreach ($submissions as $submission) {

        if ($submission && $submission->timegraded) {
            $status->code = peerwork_STATUS_GRADED;
        }
    }

    if ($status->code == peerwork_STATUS_GRADED) {
        return true;
    } else {
        return false;
    }

}

/**
 * Was due date used and has it passed?
 * @param $peerwork
 */
function peerwork_due_date($peerwork) {
    if (!$peerwork->duedate) {
        return peerwork_DUEDATE_NOT_USED;
    }

    if ($peerwork->duedate < time()) {
        return peerwork_DUEDATE_PASSED;
    } else {
        return peerwork_DUEDATE_OK;
    }
}

/**
 * Was from date used and is it after?
 * @param $peerwork
 */
function peerwork_from_date($peerwork) {
    if (!$peerwork->fromdate) {
        return peerwork_FROMDATE_NOT_USED;
    }

    if ($peerwork->fromdate > time()) {
        return peerwork_FROMDATE_BEFORE;
    } else {
        return peerwork_FROMDATE_OK;
    }
}

/**
 * Can student $user submit/edit based on the current status?
 * @param $peerwork
 */
function peerwork_is_open($peerwork, $groupid = 0) {
    global $DB;
    $status = new stdClass();
    $status->code = false;

    // Is it before from date?
    $fromdate = peerwork_from_date($peerwork);
    if ($fromdate == peerwork_FROMDATE_BEFORE) {
        $status->text = "Assessment not open yet.";
        return $status;
    }

    $course = $DB->get_record('course', array('id' => $peerwork->course), '*', MUST_EXIST);
    $group = $DB->get_record('groups', array('id' => $groupid), '*', MUST_EXIST);

    // Is it already graded?
    $pstatus = peerwork_get_status($peerwork, $group);
    if ($pstatus->code == peerwork_STATUS_GRADED) {
        $status->text = "Assessment already graded.";
        return $status;
    }

    // Is it after due date?
    $duedate = peerwork_due_date($peerwork);
    if ($duedate == peerwork_DUEDATE_PASSED) {
        if ($peerwork->allowlatesubmissions) {
            $status->code = true;
            $status->text = "After due date but late submissions allowed.";
        } else {
            $status->text = "After due date and late submissions not allowed.";
        }
        return $status;
    }

    // If we are here it means it's between from date and due date.
    $status->code = true;
    $status->text = "Assessment open.";
    return $status;
}

/**
 * Get grades for all peers in a group
 * @param $peerwork
 * @param $group
 */
function peerwork_get_peer_grades($peerwork, $group, $membersgradeable = null, $full = true) {
    global $DB;

    $return = new stdClass();

    $peers = $DB->get_records('peerwork_peers', array('peerwork' => $peerwork->id, 'groupid' => $group->id));
    $grades = array();
    $feedback = array();

    foreach ($peers as $peer) {
        $grades[$peer->criteriaid][$peer->gradedby][$peer->gradefor] = $peer->grade;
        $feedback[$peer->criteriaid][$peer->gradedby][$peer->gradefor] = $peer->feedback;
    }

    // anthing not proceessed about gets a default string)
    if ($full) {
        foreach ($membersgradeable as $member1) {
            foreach ($membersgradeable as $member2) {
                if (!isset($grades[$member1->id][$member2->id])) {
                    $grades[$member1->id][$member2->id] = '-';
                }
                if (!isset($feedback[$member1->id][$member2->id])) {
                    $feedback[$member1->id][$member2->id] = '-';
                }
            }
        }
    }

    $return->grades = $grades;
    $return->feedback = $feedback;

    return $return;
}

/**
 * How was user graded by his peers
 *
 * @param $id peer assessment id
 * @param $userid user id
 */
function peerwork_gradedme($id, $userid, $membersgradeable) {
    global $DB;
    $gradedme = new stdClass();

    // How others graded me.
    $myresults = $DB->get_records('peerwork_peers', array('peerwork' => $id, 'gradefor' => $userid),
        '', 'gradedby,feedback,grade');
    foreach ($membersgradeable as $member) {
        if (isset($myresults[$member->id])) {
            $gradedme->feedback[$member->id] = $myresults[$member->id]->feedback;
            $gradedme->grade[$member->id] = $myresults[$member->id]->grade;
        } else {
            $gradedme->feedback[$member->id] = '-';
            $gradedme->grade[$member->id] = '-';
        }
    }

    return $gradedme;
}


/**
 * Get peer grades for an individual. Takes into account treat0asgrade
 * @param $peerwork
 * @param $group
 * @param $user
 */
function peerwork_get_indpeergrades($peerwork, $group, $user) {
    global $DB;

    if ($peerwork->treat0asgrade) {
        $records = $DB->get_records_sql('SELECT id, grade FROM {peerwork_peers} WHERE peerwork=? AND groupid=?
            AND gradefor=?',
            array($peerwork->id, $group->id, $user->id));
    } else {
        $records = $DB->get_records_sql('SELECT id, grade FROM {peerwork_peers} WHERE grade>0 AND peerwork=?
            AND groupid=? AND gradefor=?',
            array($peerwork->id, $group->id, $user->id));

    }

    $peergrades = array();
    foreach ($records as $record) {
        $peergrades[] = $record->grade;
    }

    return $peergrades;
}


/**
 * Get count of an individuals peer grades. Takes into account treat0asgrade
 * @param $peerwork
 * @param $group
 * @param $user
 */
function peerwork_get_indcount($peerwork, $group, $user) {
    global $DB;

    if ($peerwork->treat0asgrade) {
        $count = (int)$DB->count_records_sql('SELECT COUNT(grade) FROM {peerwork_peers} WHERE peerwork=?
            AND groupid=? AND gradefor=?',
            array($peerwork->id, $group->id, $user->id));
    } else {
        $count = (int)$DB->count_records_sql('SELECT COUNT(grade) FROM {peerwork_peers} WHERE grade>0 AND peerwork=?
            AND groupid=? AND gradefor=?',
            array($peerwork->id, $group->id, $user->id));
    }

    if (!$count) {
        return 0;
    } else {
        return $count;
    }
}


/**
 * Get sum of an individuals peer grades. Rounded to two decimal places.
 * @param $peerwork
 * @param $group
 * @param $user
 */
function peerwork_get_indpeergradestotal($peerwork, $group, $user) {
    global $DB;

    if ($peerwork->treat0asgrade) {
        $gradesum = $DB->get_record_sql('SELECT SUM(grade) AS s FROM {peerwork_peers} WHERE peerwork=? AND
            groupid=? AND gradefor=?',
            array($peerwork->id, $group->id, $user->id));
    } else {
        $gradesum = $DB->get_record_sql('SELECT SUM(grade) AS s FROM {peerwork_peers} WHERE grade>0 AND peerwork=?
            AND groupid=? AND gradefor=?',
            array($peerwork->id, $group->id, $user->id));

    }

    return $gradesum->s;
}


/**
 * Get count of peer grades, multiple criteria will cause more than one grade per peer. Takes into account treat0asgrade
 * @param $peerwork
 * @param $group
 */
function peerwork_get_groupcount($peerwork, $group) {
    global $DB;

    if ($peerwork->treat0asgrade) {
        $count = (int)$DB->count_records_sql('SELECT COUNT(grade) FROM {peerwork_peers} WHERE peerwork=? AND groupid=?',
            array($peerwork->id, $group->id));
    } else {
        $count = (int)$DB->count_records_sql('SELECT COUNT(grade) FROM {peerwork_peers} WHERE grade>0 AND
            peerwork=? AND groupid=?',
            array($peerwork->id, $group->id));
    }

    if (!$count) {
        return 0;
    } else {
        return $count;
    }
}


/**
 * Get sum of peer grades. Rounded to two decimal places.
 * @param $peerwork
 * @param $group
 */
function peerwork_get_grouppeergradestotal($peerwork, $group) {
    global $DB;

    if ($peerwork->treat0asgrade) {
        $gradesum = $DB->get_record_sql('SELECT SUM(grade) AS s FROM {peerwork_peers} WHERE peerwork=? AND groupid=?',
            array($peerwork->id, $group->id));
    } else {
        $gradesum = $DB->get_record_sql('SELECT SUM(grade) AS s FROM {peerwork_peers} WHERE grade>0 AND
            peerwork=? AND groupid=?',
            array($peerwork->id, $group->id));
    }

    return $gradesum->s;
}


/**
 * Get group average. Either simple or adjusted for outlier.
 * @param $peerwork
 * @param $group
 */
function peerwork_get_groupaverage($peerwork, $group) {
    global $DB;

    // Can't calculate grade if student does not belong to any group.
    if (!$group) {
        return null;
    }

    return null;
}


/**
 * Get simple group average ie total marks awarded divided by number in group. Rounded to two decimal places.
 * May return NAN (if $count was zero) which the caller should handle.
 * @param $peerwork
 * @param $group
 */
function peerwork_get_simplegravg($peerwork, $group) {
    global $DB;

    $count = peerwork_get_groupcount($peerwork, $group);
    $total = peerwork_get_grouppeergradestotal($peerwork, $group);

    if($count>0) {
        return round($total / $count, 2);
    } else {
        return NAN;
    }
}


/**
 * Get adjusted group average. Rounded to two decimal places.
 * @param $peerwork
 * @param $group
 */
function peerwork_get_adjustedgravg($peerwork, $group) {
    global $DB;

    $peermarks = array();
    $averagetotal = 0;
    $count = 0;
    $groupaverage = 0;

    $members = groups_get_members($group->id);
    foreach ($members as $member) {
        $standarddev = peerwork_get_indsd($peerwork, $group, $member);
        $indaverage = peerwork_get_simpleindavg($peerwork, $group, $member);

        $peermarks[$member->id] = new stdClass();
        $peermarks[$member->id]->userid = $member->id;
        $peermarks[$member->id]->standarddev = $standarddev;

        if ($peermarks[$member->id]->standarddev <= get_config('peerwork', 'standard_deviation')) {
            $peermarks[$member->id]->indaverage = $indaverage;
        } else {
            $peermarks[$member->id]->indaverage = 0;
        }
    }

    // THIS CAN'T BE DONE UNTIL INDIVIDUAL AVERAGES ARE ALL SET TO INDAV OR 0. NEEDS TO BE A SEPARATE FOREACH.
    foreach ($members as $member) {

        $averagetotal = $averagetotal + $peermarks[$member->id]->indaverage;
        if ($peermarks[$member->id]->standarddev <= get_config('peerwork', 'standard_deviation')) {
            $count = $count + 1;
        }
    }

    $groupaverage = $averagetotal / $count;

    return round($groupaverage, 2);

}


/**
 * Get individual average.
 * @param $peerwork
 * @param $group
 */
function peerwork_get_individualaverage($peerwork, $group, stdClass $member) {
    global $DB;

    // Can't calculate grade if student does not belong to any group.
    if (!$group) {
        return null;
    }

    return null;
}


/**
 * Get individual user average.
 * @param $peerwork
 * @param $group
 */
function peerwork_get_simpleindavg($peerwork, $group, $user) {
    global $DB;

    $count = peerwork_get_indcount($peerwork, $group, $user);
    $total = peerwork_get_indpeergradestotal($peerwork, $group, $user);

    if ($count ==0) {
        return '-';
    } else {
        return round($total / $count, 2);
    }
}


/**
 * Get adjusted individual user average which takes into account the standard deviation also
 * @param $peerwork
 * @param $group
 */
function peerwork_get_adjustedindavg($peerwork, $group, $member) {
    global $DB;

    $thisperson = $member;

    $peermarks = array();
    $averagetotal = 0;
    $count = 0;

    $members = groups_get_members($group->id);
    foreach ($members as $member) {
        $standarddev = peerwork_get_indsd($peerwork, $group, $member);
        $indaverage = peerwork_get_simpleindavg($peerwork, $group, $member);

        $peermarks[$member->id] = new stdClass();
        $peermarks[$member->id]->userid = $member->id;
        $peermarks[$member->id]->standarddev = $standarddev;

        if ($peermarks[$member->id]->standarddev <= get_config('peerwork', 'standard_deviation')) {
            $peermarks[$member->id]->indaverage = $indaverage;
        } else {
            $peermarks[$member->id]->indaverage = 0;
        }
    }

    // THIS CAN'T BE DONE UNTIL INDIVIDUAL AVERAGES ARE ALL SET TO INDAV OR 0. NEEDS TO BE A SEPARATE FOREACH.
    foreach ($members as $member) {

        $averagetotal = $averagetotal + $peermarks[$member->id]->indaverage;
        if ($peermarks[$member->id]->standarddev <= get_config('peerwork', 'standard_deviation')) {
            $count = $count + 1;
        }
    }

    $groupaverage = 0;
    $groupaverage = $averagetotal / $count;

    foreach ($members as $member) {

        if ($peermarks[$member->id]->standarddev > get_config('peerwork', 'standard_deviation')) {
            $peermarks[$member->id]->indaverage = round($groupaverage, 2);
        }
    }

    return $peermarks[$thisperson->id]->indaverage;

}


function peerwork_get_indsd($peerwork, $group, $user) {
    global $DB;

    $count = peerwork_get_indcount($peerwork, $group, $user);

    if ($count == 0) {
        return '-';
    }

    $peergrades = peerwork_get_indpeergrades($peerwork, $group, $user);

    $result = peerwork_get_pstd_dev($peergrades);

    return round($result, 2);
}

/**
 * Get the final awarded grade of the student according to the algorithm.
 */
function peerwork_get_grade($peerwork, $group, stdClass $member) {
    global $DB;

    // Can't calculate grade if student does not belong to any group.
    if (!$group) {
        return null;
    }

    $grade = peerwork_get_webpa_grade($peerwork, $group, $member);
    return $grade;
}

/**
 * Get the final awarded score of the student according to the algorithm.
 * The score is the 'raw', pre-weighted value from the algorithm
 */
function peerwork_get_score($peerwork, $group, stdClass $member) {
    global $DB;

    // Can't calculate grade if student does not belong to any group.
    if (!$group) {
        return null;
    }

    $score = peerwork_get_webpa_score($peerwork, $group, $member);
    return $score;
}


/**
 * Return the final grade for the member using the webpa algorithm.
 *
 * @return number or null if unable to calculate
 */
function peerwork_get_webpa_grade($peerwork, $group, stdClass $member) {
    global $CFG, $DB;

    // Can't calculate grade if student does not belong to any group.
    if (!$group) {
        return null;
    }

    $algorithm = new WebPAAlgorithm($peerwork, $group);
    $algorithm ->calculate();
    return $algorithm ->getGrade($member);
}

/**
* Return the intermediate score for the member using the webpa algorithm.
*
* @return number or null if unable to calculate
*/
function peerwork_get_webpa_score($peerwork, $group, stdClass $member) {
    global $CFG, $DB;

    // Can't calculate grade if student does not belong to any group.
    if (!$group) {
        return null;
    }

    $algorithm = new WebPAAlgorithm($peerwork, $group);
    $algorithm ->calculate();
    return $algorithm ->getScore($member);
}

/**
 * Perform the calculation of a users final grade using the 'simple' calculation.
 * This seems to give very strange results @see issue#3
 *
 * @return number
 */
function peerwork_get_simple_grade($peerwork, $group, stdClass $member) {
    global $CFG, $DB;
    $thisperson = $member;

    $peermarks = array();

    // Can't calculate grade if student does not belong to any group.
    if (!$group) {
        return null;
    }

    // $multiply = get_config('peerwork', 'multiplyby');
    $multiplier = 5;
    $gravg = peerwork_get_simplegravg($peerwork, $group);
    $submission = $DB->get_record('peerwork_submission', array('assignment' => $peerwork->id, 'groupid' => $group->id));

    if (empty($submission) || !isset($submission->grade) || is_nan($gravg) ) {
        return '-';
    }

    $members = groups_get_members($group->id);
    foreach ($members as $member) {

        $peermarks[$member->id] = new stdClass();
        $peermarks[$member->id]->userid = $member->id;
        $psia = peerwork_get_simpleindavg($peerwork, $group, $member);
        $peermarks[$member->id]->indaverage = $psia;
        $peermarks[$member->id]->final_grade = $submission->grade + (($peermarks[$member->id]->indaverage - $gravg) * $multiplier);

        error_log("peerwork_get_simple_grade psia = " . print_r($psia,true) );
        error_log("peerwork_get_simple_grade " . print_r($peermarks[$member->id],true) );
    }

    $grade = $peermarks[$thisperson->id]->final_grade;

    if ($grade > 100) {
        $grade = 100;
    }

    if ($grade < 0) {
        $grade = 0;
    }

    return $grade;
}

/**
 * Description
 * @param type $peerwork
 * @param type $group
 * @param stdClass $member
 * @return type
 */
function peerwork_get_outlier_adjusted_grade($peerwork, $group, stdClass $member) {
    global $CFG, $DB;

    $thisperson = $member;

    $peermarks = array();

    // Can't calculate grade if student does not belong to any group.
    if (!$group) {
        return null;
    }

    // $multiply = get_config('peerwork', 'multiplyby');
    $multiplier = 4;
    $indavg = peerwork_get_simpleindavg($peerwork, $group, $member);
    $groupaverage = peerwork_get_groupaverage($peerwork, $group);
    $submission = $DB->get_record('peerwork_submission', array('assignment' => $peerwork->id, 'groupid' => $group->id));

    if (!$submission || !isset($submission->grade)) {
        return '-';
    }

    $members = groups_get_members($group->id);
    foreach ($members as $member) {
        $standarddev = peerwork_get_indsd($peerwork, $group, $member);
        $indaverage = peerwork_get_simpleindavg($peerwork, $group, $member);

        $peermarks[$member->id] = new stdClass();
        $peermarks[$member->id]->userid = $member->id;
        $peermarks[$member->id]->standarddev = $standarddev;

        if ($peermarks[$member->id]->standarddev <= get_config('peerwork', 'standard_deviation')) {
            $peermarks[$member->id]->indaverage = $indaverage;
        } else {
            $peermarks[$member->id]->indaverage = 0;
        }
    }

    foreach ($members as $member) {

        if ($peermarks[$member->id]->standarddev > get_config('peerwork', 'standard_deviation')) {
            $peermarks[$member->id]->indaverage = $groupaverage;
        }

        $peermarks[$member->id]->mm = round(($peermarks[$member->id]->indaverage - $groupaverage) * $multiplier, 2);

        if (abs($peermarks[$member->id]->mm) < get_config('peerwork', 'moderation')) {
            $peermarks[$member->id]->mm = 0;
        }

        $peermarks[$member->id]->final_grade = $submission->grade + $peermarks[$member->id]->mm;
    }

    $grade = $peermarks[$thisperson->id]->final_grade;

    if ($grade > 100) {
        $grade = 100;
    }

    if ($grade < 0) {
        $grade = 0;
    }

    return $grade;
}

/**
 * Fill up missing assessments with grade "0"
 */
function peerwork_fillup() {

}

/**
 * Create HTML links to files that have been submitted to the assignment.
 * Used by view.php and details.php
 * @return string[] array of formated <A href= strings, possibly empty array
 */
function peerwork_submission_files($context, $group) {
    $allfiles = array();
    $fs = get_file_storage();
    if ($files = $fs->get_area_files($context->id, 'mod_peerwork', 'submission', $group->id, 'sortorder', false)) {
        foreach ($files as $file) {
            $fileurl = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(),
                $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());

            $allfiles[] = "<a href='$fileurl'>" . $file->get_filename() . '</a>';
        }
    }
    return $allfiles;
}


function peerwork_feedback_files($context, $group) {
    $allfiles = array();
    $fs = get_file_storage();
    if ($files = $fs->get_area_files($context->id, 'mod_peerwork', 'feedback_files', $group->id, 'sortorder', false)) {
        foreach ($files as $file) {
            $fileurl = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(),
                $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());

            $allfiles[] = "<a href='$fileurl'>" . $file->get_filename() . '</a>';
        }
    }
    return $allfiles;
}

/**
 * Total all the grades awarded by the $user to other members of the group.
 * Return a structure that can be used to visualise the progress made in providing marks to peers in the group.
 */
function peerwork_grade_by_user($peerwork, $user, $membersgradeable) {
    global $DB;

    $data = new stdClass(); // data->grade[member] data->feedback[member]
    $data->grade = array();
    $data->feedback = array();

    $mygrades = $DB->get_records('peerwork_peers', array('peerwork' => $peerwork->id,
        'gradedby' => $user->id), '', 'id,criteriaid,gradefor,feedback,grade');

    foreach ($mygrades as $grade) {
        $peerid = $grade->gradefor;
        @$data->grade[$peerid] += $grade->grade;
        @$data->feedback[$peerid] |= $grade->feedback;
    }

    // Make sure all the peers have an entry in the returning data array.
    foreach ($membersgradeable as $member) {
        if (!array_key_exists( $member->id, $data->grade)) {
            $data->grade[$member->id] = '-';
        }
        if (!array_key_exists( $member->id, $data->feedback)) {
            $data->feedback[$member->id] = '-';
        }
    }
    return $data;
}

/**
 * Get submission file options.
 *
 * @param stdClass $peerwork The instance from database.
 * @return array
 */
function peerwork_get_fileoptions($peerwork) {
    return array('mainfile' => '', 'subdirs' => 0, 'maxbytes' => -1, 'maxfiles' => $peerwork->maxfiles,
        'accepted_types' => '*', 'return_types' => null);
}

/**
 * Find members of the group that did not submit feedback and graded peers.
 * @param $peerwork
 * @param $group
 */
function peerwork_outstanding($peerwork, $group) {
    global $DB;

    $members = groups_get_members($group->id);
    foreach ($members as $k => $member) {
        if ($DB->get_record('peerwork_peers', array('peerwork' => $peerwork->id, 'groupid' => $group->id,
            'gradedby' => $member->id), 'id', IGNORE_MULTIPLE)) {
            unset($members[$k]);
        }

    }
    return $members;
}

function peerwork_teachers($context) {
    global $CFG;

    $contacts = array();
    if (empty($CFG->coursecontact)) {
        return $contacts;
    }
    $coursecontactroles = explode(',', $CFG->coursecontact);
    foreach ($coursecontactroles as $roleid) {
        $contacts += get_role_users($roleid, $context, true);
    }
    return $contacts;
}

/**
 * Student has provided some grades on their peers using the add_submission_form, save into database and trigger events.
 *
 * @param unknown $peerwork
 * @param unknown $submission - database record in stdClass
 * @param unknown $group
 * @param unknown $course
 * @param unknown $cm
 * @param unknown $context
 * @param unknown $data
 * @param unknown $draftitemid
 * @param unknown $membersgradeable
 * @throws Exception
 */
function peerwork_save($peerwork, $submission, $group, $course, $cm, $context, $data, $draftitemid, $membersgradeable) {
    global $USER, $DB;

    $event = \mod_peerwork\event\assessable_submitted::create(['context' => $context]);
    $event->trigger();

    // Create submission record if none yet.
    if (!$submission) {
        $submission = new stdClass();
        $submission->assignment = $peerwork->id;
        $submission->userid = $USER->id;
        $submission->timecreated = time();
        $submission->timemodified = time();
        $submission->groupid = $group->id;

        $submission->id = $DB->insert_record('peerwork_submission', $submission);

        $params = array(
            'objectid' => $submission->id,
            'context' => $context,
            'other' => array('groupid' => $group->id)
        );

        $event = \mod_peerwork\event\submission_created::create($params);
        $event->trigger();

    } else {
        // Just update.
        $submission->timemodified = time();
        $DB->update_record('peerwork_submission', $submission);

        $params = array(
            'objectid' => $submission->id,
            'context' => $context,
            'other' => array('groupid' => $group->id)
        );

        $event = \mod_peerwork\event\submission_updated::create($params);
        $event->add_record_snapshot('peerwork_submission', $submission);
        $event->trigger();
    }

    // Save the file submitted.
    // Check if the file is different or the same.
    $fs = get_file_storage();
    $usercontext = context_user::instance($USER->id);
    $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);

    // Check special case when there were no files at the time of submission and none were added.
    $skipfile = false;
    if ($data->files == 0 && count($draftfiles) == 0) {
        $skipfile = true;
    }

    if (!$skipfile) {
        // Get all contenthashes being submitted.
        $newhashes = array();
        foreach ($draftfiles as $file) {
            $newhashes[$file->get_contenthash()] = $file->get_contenthash();
        }

        // Get all contenthashes that are already submitted.
        $files = $fs->get_area_files($context->id, 'mod_peerwork', 'submission', $group->id, 'sortorder', false);
        $oldhashes = array();
        foreach ($files as $file) {
            $oldhashes[$file->get_contenthash()] = $file->get_contenthash();
        }

        $samehashes = array_intersect($newhashes, $oldhashes);
        $addedhashes = array_diff($newhashes, $oldhashes);
        $deletedhashes = array_diff($oldhashes, $newhashes);

        $filesubmissioncount = count($newhashes);
        $filelist = array();
        $filedeletedcount = count($deletedhashes);

        if ($samehashes) {
            $filelist[] = ' Resubmitted:<br/>' . join('<br/>', $samehashes);
        }

        if ($addedhashes) {
            $filelist[] = ' Added:<br/>' . join('<br/>', $addedhashes);
        }

        if ($deletedhashes) {
            $deletedlist = 'Deleted:<br/>' . join('<br/>', $deletedhashes);
        }

        $filelist = join('<br/>', $filelist);

        if ($deletedhashes) {
            $params = array(
                'objectid' => $submission->id,
                'context' => $context,
                'other' => array(
                    'filedeletedcount' => $filedeletedcount,
                    'deletedlist' => $deletedlist
                )
            );

            $event = \mod_peerwork\event\submission_files_deleted::create($params);
            $event->trigger();
        }

        if ($filelist) {
            $params = array(
                'objectid' => $submission->id,
                'context' => $context,
                'other' => array(
                    'filesubmissioncount' => $filesubmissioncount,
                    'filelist' => $filelist
                )
            );

            $event = \mod_peerwork\event\submission_files_uploaded::create($params);
            $event->trigger();
        }

        if (count($newhashes) && $oldhashes != $newhashes) {
            // Hashes are different, submission has changed.
            $submission->submissionmodified = time();
            $submission->submissionmodifiedby = $USER->id;

            $DB->update_record('peerwork_submission', $submission);
        }

        file_save_draft_area_files($draftitemid, $context->id, 'mod_peerwork', 'submission', $group->id,
            peerwork_get_fileoptions($peerwork));
    }

    // Remove existing grades, in case it's an update.
    $DB->delete_records('peerwork_peers',
        array('peerwork' => $peerwork->id, 'groupid' => $group->id, 'gradedby' => $USER->id));

    // Save the grades.
    $pac = new peerwork_criteria($peerwork->id);
    $criteria = $pac->getCriteria();
    foreach ($criteria as $criterion) {
        foreach ($membersgradeable as $member) {
            $peer = new stdClass();
            $peer->peerwork = $peerwork->id;
            $peer->criteriaid = $criterion->id;
            $peer->groupid = $group->id;
            $peer->gradedby = $USER->id;
            $peer->gradefor = $member->id;
            $peer->feedback = null;
            $peer->timecreated = time();
            $field = 'grade_idx_'. $criterion->id;
            if (isset($data->{$field}[$peer->gradefor])) {
                $peer->grade = max(0, (int) $data->{$field}[$peer->gradefor]);
            } else {
                $peer->grade = 0;
            }

            $peer->id = $DB->insert_record('peerwork_peers', $peer, true);
        }

        // add a log entry
        $fullname = fullname($member);
        $params = array(
            'objectid' => $peer->id,
            'context' => $context,
            'relateduserid' => $member->id,
            'other' => array(
                'grade' => $peer->grade,
                'fullname' => $fullname
            )
        );

        $event = \mod_peerwork\event\peer_grade_created::create($params);
        $event->add_record_snapshot('peerwork_peers', $peer);
        $event->trigger();
    }

    // Save the justification.
    if ($peerwork->justification != MOD_PEERWORK_JUSTIFICATION_HIDDEN) {
        foreach ($membersgradeable as $member) {
            $params = [
                'peerworkid' => $peerwork->id,
                'groupid' => $group->id,
                'gradefor' => $member->id,
                'gradedby' => $USER->id
            ];
            $record = $DB->get_record('peerwork_justification', $params);
            if (!$record) {
                $record = (object) $params;
            }
            $record->justification = isset($data->justifications[$member->id]) ? $data->justifications[$member->id] : '';
            if (!empty($record->id)) {
                $DB->update_record('peerwork_justification', $record);
            } else {
                $DB->insert_record('peerwork_justification', $record);
            }
        }
    }

    // Send email confirmation.
    if (!mail_confirmation_submission($course, $submission, $draftfiles, $membersgradeable, $data)) {
        throw new moodle_exception("Submission saved but no email sent.");
    }
}

function mail_confirmation_submission($course, $submission, $draftfiles, $membersgradeable, $data) {
    global $CFG, $USER;
error_log("mail_confirmation_submission TODO ");
return true;

    $subject = get_string('confirmationmailsubject', 'peerwork', $course->fullname);

    $a = new stdClass();
    $a->time = userdate(time());

    $files = array();
    foreach ($draftfiles as $draftfile) {
        $files[] = $draftfile->get_filename();
    }
    $a->files = implode("\n", $files);

    $grades = '';
    foreach ($membersgradeable as $member) {
        $grades .= fullname($member) . ': ' . $data->grade[$member->id] . "\n";
    }
    $a->grades = $grades;

    $a->url = $CFG->wwwroot . "/mod/peerwork/view.php?n=" . $submission->assignment;

    $body = get_string('confirmationmailbody', 'peerwork', $a);
    return email_to_user($USER, core_user::get_noreply_user(), $subject, $body);
}

function peerwork_get_pstd_dev(array $a, $sample = false) {
    $n = count($a);

    if ($n === 0) {
        trigger_error("The array has zero elements", E_USER_WARNING);
        return false;
    }
    if ($sample && $n === 1) {
        trigger_error("The array has only 1 element", E_USER_WARNING);
        return false;
    }
    $mean = array_sum($a) / $n;

    $carry = 0.0;
    foreach ($a as $val) {
        $d = ((double) $val) - $mean;

        $carry += ($d * $d);
    }

    if ($sample) {
        --$n;
    }

    return sqrt($carry / $n);
}
