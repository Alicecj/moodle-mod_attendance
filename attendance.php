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
 * Prints attendance info for particular user
 *
 * @package   mod_attendance
 * @copyright  2014 Dan Marsden
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__).'/../../config.php');
require_once(dirname(__FILE__).'/locallib.php');
require_once(dirname(__FILE__).'/student_attendance_form.php');

$pageparams = new mod_attendance_sessions_page_params();

// Check that the required parameters are present.
$id = required_param('sessid', PARAM_INT);

$attforsession = $DB->get_record('attendance_sessions', array('id' => $id), '*', MUST_EXIST);
$attendance = $DB->get_record('attendance', array('id' => $attforsession->attendanceid), '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('attendance', $attendance->id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

// Require the user is logged in.
require_login($course, true, $cm);

if (!attendance_can_student_mark($attforsession)) {
    // TODO: should we add a log message here? - student has got to submit page but cannot save attendance (time ran out?)
    redirect(new moodle_url('/mod/attendance/view.php', array('id' => $cm->id)));
    exit;
}

// Check if subnet is set and if the user is in the allowed range.
if (!empty($attforsession->subnet) && !address_in_subnet(getremoteaddr(), $attforsession->subnet)) {
    notice(get_string('subnetwrong', 'attendance'));
    exit; // Notice calls this anyway.
}

$pageparams->sessionid = $id;
$att = new mod_attendance_structure($attendance, $cm, $course, $PAGE->context, $pageparams);

// Require that a session key is passed to this page.
require_sesskey();

// Create the form.
$mform = new mod_attendance_student_attendance_form(null,
        array('course' => $course, 'cm' => $cm, 'modcontext' => $PAGE->context, 'session' => $attforsession, 'attendance' => $att));

$PAGE->set_url($att->url_sessions());

if ($mform->is_cancelled()) {
    // The user cancelled the form, so redirect them to the view page.
    $url = new moodle_url('/mod/attendance/view.php', array('id' => $cm->id));
    redirect($url);
} else if ($fromform = $mform->get_data()) {
    // Check if password required and if set correctly.
    if (!empty($attforsession->studentpassword) &&
        $attforsession->studentpassword !== $fromform->studentpassword) {

        $url = new moodle_url('/mod/attendance/attendance.php', array('sessid' => $id, 'sesskey' => sesskey()));
        redirect($url, get_string('incorrectpassword', 'mod_attendance'), null, \core\output\notification::NOTIFY_ERROR);
    }
    if ($attforsession->autoassignstatus) {
        // Find the status to set here.
        $statuses = $att->get_statuses();
        $highestavailablegrade = 0;
        $highestavailablestatus;
        foreach ($statuses as $status) {
            if ($status->studentavailability === '0') {
                // This status is never available to students.
                continue;
            }
            if (!empty($status->studentavailability)) {
                $toolateforstatus = (($attforsession->sessdate + ($status->studentavailability * 60)) < time());
                if ($toolateforstatus) {
                    continue;
                }
            }
            // This status is available to the student.
            if ($status->grade > $highestavailablegrade) {
                // This is the most favourable grade so far; save it.
                $highestavailablegrade = $status->grade;
                $highestavailablestatus = $status;
            }
        }
        $fromform->status = $highestavailablestatus->id;
    }

    if (!empty($fromform->status)) {
        $success = $att->take_from_student($fromform);

        $url = new moodle_url('/mod/attendance/view.php', array('id' => $cm->id));
        if ($success) {
            // Redirect back to the view page for the block.
            redirect($url);
        } else {
            print_error('attendance_already_submitted', 'mod_attendance', $url);
        }
    }

    // The form did not validate correctly so we will set it to display the data they submitted.
    $mform->set_data($fromform);
}

$PAGE->set_title($course->shortname. ": ".$att->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_cacheable(true);
$PAGE->navbar->add($att->name);

$output = $PAGE->get_renderer('mod_attendance');
echo $output->header();
$mform->display();
echo $output->footer();
