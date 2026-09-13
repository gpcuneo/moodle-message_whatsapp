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
 * What the WhatsApp channel did today, and whether it is moving at all.
 *
 * The delivery report answers what became of one message. This page answers the question somebody asks before
 * they know which message to look for: is anything going out. It reads nothing but counters, changes nothing,
 * and is deliberately a page and not a block on the report, because a screen of a hundred rows is the wrong
 * place to notice that the number at the top is zero.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use message_whatsapp\local\status;

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('message/whatsapp:viewlog', context_system::instance());

$pageurl = new moodle_url('/message/output/whatsapp/status.php');

// Same two ways of setting up the page as report.php, and for the same reason: the settings.php of a `message`
// plugin is only ever included for somebody who holds moodle/site:config, so the administration node this page
// registers does not exist for a manager, and asking for it would answer "Access denied" without ever looking at
// a capability. The capability is the barrier; the administration tree is navigation.
if (has_capability('moodle/site:config', context_system::instance())) {
    admin_externalpage_setup('message_whatsapp_status', '', null, '', ['pagelayout' => 'report']);
} else {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url($pageurl);
    $PAGE->set_pagelayout('report');
    $PAGE->set_title(get_string('statuspage', 'message_whatsapp'));
    $PAGE->set_heading($SITE->fullname);
}

$today = status::today();
$backlog = status::backlog();
$lastrun = status::last_send_run();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('statuspage', 'message_whatsapp'));
echo html_writer::tag('p', get_string('statusintro', 'message_whatsapp'));

if (status::sending_has_stalled()) {
    echo $OUTPUT->notification(get_string('statusstalled', 'message_whatsapp'), notification::NOTIFY_ERROR);
}

$counts = new html_table();
$counts->head = [
    get_string('statuswhat', 'message_whatsapp'),
    get_string('statushowmany', 'message_whatsapp'),
];
$counts->attributes['class'] = 'generaltable';

foreach ($today as $state => $total) {
    $counts->data[] = [get_string('logstatus' . $state, 'message_whatsapp'), $total];
}

echo $OUTPUT->heading(get_string('statustoday', 'message_whatsapp'), 3);
echo html_writer::table($counts);

$waiting = new html_table();
$waiting->head = [
    get_string('statuswhat', 'message_whatsapp'),
    get_string('statushowmany', 'message_whatsapp'),
];
$waiting->attributes['class'] = 'generaltable';
$waiting->data[] = [get_string('statuswaiting', 'message_whatsapp'), $backlog['pending']];
$waiting->data[] = [get_string('statusinflight', 'message_whatsapp'), $backlog['sending']];
$waiting->data[] = [
    get_string('statusoldest', 'message_whatsapp'),
    $backlog['oldest'] > 0 ? format_time($backlog['oldest']) : get_string('statusnothingwaiting', 'message_whatsapp'),
];
$waiting->data[] = [
    get_string('statuslastrun', 'message_whatsapp'),
    $lastrun > 0 ? userdate($lastrun) : get_string('statusneverrun', 'message_whatsapp'),
];

echo $OUTPUT->heading(get_string('statusqueue', 'message_whatsapp'), 3);
echo html_writer::table($waiting);

echo html_writer::tag('p', html_writer::link(
    new moodle_url('/message/output/whatsapp/report.php'),
    get_string('statustoreport', 'message_whatsapp')
));

echo $OUTPUT->footer();
