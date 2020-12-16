<?php
// This file is part of a 3rd party created module for Moodle - http://moodle.org/
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
 * Export.
 *
 * @package    mod_peerwork
 * @copyright  2013 LEARNING TECHNOLOGY SERVICES
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/mod/peerwork/locallib.php');
require_once($CFG->dirroot . '/lib/grouplib.php');
require_once($CFG->libdir . '/csvlib.class.php');

$id = required_param('id', PARAM_INT);


list($course, $cm) = get_course_and_cm_from_cmid($id, 'peerwork');
$peerwork = $DB->get_record('peerwork', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
// require_sesskey();
require_capability('mod/peerwork:grade', $cm->context);

$PAGE->set_url(new moodle_url('/mod/peerwork/exportallgroupspeersassessments.php', ['id' => $id]));

$event = \mod_peerwork\event\submissions_exported_peer_assessments::create(['context' => $cm->context]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot($cm->modname, $peerwork);
$event->trigger();


$headers = [
    get_string('group'),
    get_string('nomembers', 'mod_peerwork'),
    get_string('nopeergrades', 'mod_peerwork'),
    get_string('status'),
    get_string('grade', 'mod_peerwork'),
];

$filename = clean_filename($peerwork->name . '-' . $id . '_all');
$csvexport = new csv_export_writer();
$csvexport->set_filename($filename);
$csvexport->add_data($headers);

$ingroupparams = [];
$ingroupsql = ' = 0';
if (!empty($groupids)) {
    list($ingroupsql, $ingroupparams) = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
}

$allgroups = groups_get_all_groups($course->id, 0, $cm->groupingid);
// Naturally Sort the groups by name
array_multisort(array_column($allgroups, 'name'), SORT_NATURAL, $allgroups);

foreach ($allgroups as $group) {
    $members = groups_get_members($group->id);
    $submission = $DB->get_record('peerwork_submission', array('peerworkid' => $peerwork->id, 'groupid' => $group->id));
    $status = peerwork_get_status($peerwork, $group, $submission);
    $grader = new mod_peerwork\group_grader($peerwork, $group->id, $submission);

    // 1st row with the group's details
    $csvexport->add_data([
        $group->name,
        count($members),
        peerwork_get_number_peers_graded($peerwork->id, $group->id),
        $status->text,
        $grader->get_grade(),
    ]);

    // 2nd row with the peer assessments
    $lockedgraders = mod_peerwork_get_locked_graders($peerwork->id);
    $isopen        = peerwork_is_open($peerwork, $group->id);
    $canunlock     = !empty($isopen->code) && $submission && !$submission->timegraded;
    // Get the peer grades awarded so far
    $grades        = peerwork_get_peer_grades($peerwork, $group, $members, false);

    // Get an array of the peers and the grades awarded and received.
    $peers_assessments_a = [];
    $pac = new mod_peerwork_criteria( $peerwork->id );
    foreach ($pac->get_criteria() as $criteria) {
        $criteria_description = [];
        $criteria_description[] = strip_tags($criteria->description);
        $peers_assessments_a[] = $criteria_description;

        $critid = $criteria->id;

        $rows_pas = [];
        $row_head = [];
        $row_head[] = '';

        $row_total = [];
        $row_total[] = get_string('total', 'core');

        foreach ($members as $member) {
            $row_head[] = fullname($member) . ' (' . $member->username . ')';
            $row = [];
            $row[] = fullname($member) . ' (' . $member->username . ')';

            $i = 1;
            foreach ($members as $peer) {
                if (!isset($grades->grades[$critid]) || !isset($grades->grades[$critid][$member->id])
                        || !isset($grades->grades[$critid][$member->id][$peer->id])) {
                    $row[] = '-';
                } else {
                    $row[] = $grades->grades[$critid][$member->id][$peer->id];
                    if (isset($row_total[$i])) {
                        $row_total[$i] += (int) $grades->grades[$critid][$member->id][$peer->id];
                    } else {
                        $row_total[$i]  = (int) $grades->grades[$critid][$member->id][$peer->id];
                    }
                }
                $i++;
            }
            $rows_pas[] = $row;
        }
        $peers_assessments_a[] = $row_head;
        foreach ($rows_pas as $row) {
            $peers_assessments_a[] = $row;
        }
        $peers_assessments_a[] = $row_total;
    }

    // Add 3 empty "cells" to the beginning of every row - for formatting purposes only
    foreach ($peers_assessments_a as $row) {
        $csvexport->add_data(
            array_merge(['','',''], $row)
        );
    }
}
$csvexport->download_file();

