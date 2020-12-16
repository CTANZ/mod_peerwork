<?php
// This file is part of 3rd party created module for Moodle - http://moodle.org/
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
 * Details.
 *
 * @package    mod_peerwork
 * @copyright  2013 LEARNING TECHNOLOGY SERVICES
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/peerwork/lib.php');
require_once($CFG->dirroot . '/lib/grouplib.php');
require_once($CFG->dirroot . '/mod/peerwork/locallib.php');

$id = required_param('id', PARAM_INT); // Course_module ID, or

if ($id) {
    $cm = get_coursemodule_from_id('peerwork', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $peerwork = $DB->get_record('peerwork', array('id' => $cm->instance), '*', MUST_EXIST);
} else {
    error('You must specify a course_module ID or an instance ID');
}

$groupingid = $cm->groupingid;
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/peerwork:grade', $context);

$params = array(
    'objectid' => $cm->instance,
    'context' => $context
);

$PAGE->set_url('/mod/peerwork/viewallgroupspeersassessments.php', array('id' => $cm->id));
$PAGE->set_title(format_string($peerwork->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$event = \mod_peerwork\event\submission_all_peer_assessments_viewed::create($params);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot($cm->modname, $peerwork);
$event->trigger();


// Output starts here.
echo $OUTPUT->header();

// Show mod details.
echo $OUTPUT->heading(format_string($peerwork->name));
echo $OUTPUT->box(format_string($peerwork->intro));

$duedate = peerwork_due_date($peerwork);
if ($duedate != PEERWORK_DUEDATE_NOT_USED) {
    echo $OUTPUT->box(get_string('duedateat', 'mod_peerwork', userdate($peerwork->duedate)));
    if ($duedate == PEERWORK_DUEDATE_PASSED) {
        echo $OUTPUT->box(get_string('assessmentclosedfor', 'mod_peerwork', format_time(time() - $peerwork->duedate)));
    } else {
        echo $OUTPUT->box(get_string('timeremainingcolon', 'mod_peerwork', format_time(time() - $peerwork->duedate)));
    }
}

$allgroups = groups_get_all_groups($course->id, 0, $groupingid);
// Naturally Sort the groups by name
array_multisort(array_column($allgroups, 'name'), SORT_NATURAL, $allgroups);
$anynongraded = false;

$t = new html_table();
$t->attributes['class'] = 'userenrolment';
$t->id = 'mod-peerwork-summary-table';
$t->head = [
    get_string('group'),
    get_string('nomembers', 'mod_peerwork'),
    get_string('nopeergrades', 'mod_peerwork'),
    get_string('status'),
    get_string('grade', 'mod_peerwork'),
    ''
];
foreach ($allgroups as $group) {
    $members = groups_get_members($group->id);
    $submission = $DB->get_record('peerwork_submission', array('peerworkid' => $peerwork->id, 'groupid' => $group->id));
    $status = peerwork_get_status($peerwork, $group, $submission);

    $grader = new mod_peerwork\group_grader($peerwork, $group->id, $submission);
    $wasgraded = $grader->was_graded();
    $detailsurl = new moodle_url('details.php', ['id' => $cm->id, 'groupid' => $group->id]);
    $anynongraded = $anynongraded || !$wasgraded;

    $menu = new action_menu();
    $menu->add_secondary_action(new action_link(
        $detailsurl,
        $wasgraded ? get_string('edit') : get_string('grade')
    ));
    $menu->add_secondary_action(new action_link(
        new moodle_url('export.php', ['id' => $cm->id, 'groupid' => $group->id, 'sesskey' => sesskey()]),
        get_string('export', 'mod_peerwork')
    ));

    if (!$wasgraded) {
        $menu->add_secondary_action(new action_link(
            new moodle_url('clearsubmissions.php', ['id' => $cm->id, 'groupid' => $group->id, 'sesskey' => sesskey()]),
            get_string('clearsubmission', 'mod_peerwork'),
            new confirm_action(get_string('confimrclearsubmission', 'mod_peerwork'))
        ));
    }

    if ($status->code == PEERWORK_STATUS_GRADED) {
        $menu->add_secondary_action(new action_link(
            new moodle_url('release.php', ['id' => $cm->id, 'groupid' => $group->id, 'sesskey' => sesskey()]),
            get_string('releasegrades', 'mod_peerwork')
        ));
    }

    $gradeinplace = new core\output\inplace_editable(
        'mod_peerwork',
        'groupgrade_' . $peerwork->id,
        $group->id,
        true,
        $wasgraded ? $grader->get_grade() : '-',
        $wasgraded ? $grader->get_grade() : null
    );
    $gradecell = new html_table_cell($OUTPUT->render($gradeinplace));
    $gradecell->attributes['class'] = 'inplace-grading';

    // 1st row with the group's details
    $row = new html_table_row();
    $row->cells = array(
        $OUTPUT->action_link($detailsurl, $group->name),
        count($members),
        peerwork_get_number_peers_graded($peerwork->id, $group->id),
        $status->text,
        $gradecell,
        $OUTPUT->render($menu)
    );
    $t->data[] = $row;


    // 2nd row with the peer assessments
    $lockedgraders = mod_peerwork_get_locked_graders($peerwork->id);
    $isopen        = peerwork_is_open($peerwork, $group->id);
    $canunlock     = !empty($isopen->code) && $submission && !$submission->timegraded;
    // Get the peer grades awarded so far
    $grades        = peerwork_get_peer_grades($peerwork, $group, $members, false);

    // Get the justifications.
    $justifications = [];
    if ($peerwork->justification != MOD_PEERWORK_JUSTIFICATION_DISABLED) {
        $justifications = peerwork_get_justifications($peerwork->id, $group->id);
    }

    $mform = new mod_peerwork_details_form($PAGE->url->out(false), [
        'peerwork' => $peerwork,
        'justifications' => $justifications,
        'submission' => $submission,
        'members' => $members,
        'canunlock' => $canunlock,
    ]);

    // Get the peer grades awarded so far, then for each criteria
    // output a HTML tabulation of the peers and the grades awarded and received.
    // TODO instead of HTML fragment can we build this with form elments?
    $grades = peerwork_get_peer_grades($peerwork, $group, $members, false);
    $pac = new mod_peerwork_criteria( $peerwork->id );
    $data['peergradesawarded'] = '';
    // Is there per criteria justification enabled?
    $justenabled = $peerwork->justification != MOD_PEERWORK_JUSTIFICATION_DISABLED;
    $justcrit = $peerwork->justificationtype == MOD_PEERWORK_JUSTIFICATION_CRITERIA;
    $justenabledcrit = $justenabled && $justcrit;
    $criterion = $pac->get_criteria();
    $summary = new mod_peerwork\output\peerwork_detail_summary(
        $criterion,
        $grades,
        $justifications,
        $members,
        $lockedgraders,
        $peerwork,
        $canunlock,
        $justenabledcrit,
        $cm->id,
        $group->id
    );
    $renderer = $PAGE->get_renderer('mod_peerwork');

    $row = new html_table_row();
    $row->cells = array(
        '',
        '',
        '',
        $renderer->render($summary),
        '',
        ''
    );
    $t->data[] = $row;
}
echo html_writer::table($t);

echo $OUTPUT->box_start('generalbox', null);

echo $OUTPUT->single_button(new moodle_url('view.php', array('id' => $cm->id)),
    get_string("viewallgroups", 'mod_peerwork'), 'get');

echo $OUTPUT->single_button(new moodle_url('exportallgroupspeersassessments.php', array('id' => $cm->id)),
    get_string("exportallgroupspeersassessments", 'mod_peerwork'), 'get');

echo $OUTPUT->single_button(new moodle_url('release.php', ['id' => $cm->id,  'groupid' => 0, 'sesskey' => sesskey()]),
    get_string("releaseallgradesforallgroups", 'mod_peerwork'), 'get');


echo $OUTPUT->box_end();

echo $OUTPUT->footer();
