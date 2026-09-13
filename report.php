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
 * The WhatsApp delivery report, and the one action that can be taken from it.
 *
 * The listing itself is a report builder system report, {@see \message_whatsapp\reportbuilder\local\systemreports\log}.
 * What lives here is the page around it and the retry action, which is the only thing on this screen that changes
 * anything.
 *
 * Retrying is a three step affair on purpose. The link in the row carries a session key and does nothing but open
 * a confirmation. The confirmation is a form that POSTs, so the change never happens on a GET: a re-queued
 * notification is a message that will be delivered to somebody's phone and billed to the site, and no such thing
 * should be reachable by a link that a browser, a mail client or another site can follow on behalf of the person
 * who is logged in. The change itself is then a single statement that carries its own `status = 'failed'`
 * condition, so a row that a delivery report moved on in the meantime is left where it is.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use core_reportbuilder\system_report_factory;
use message_whatsapp\local\queue;
use message_whatsapp\reportbuilder\local\systemreports\log;

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

/** Value of the `action` parameter that asks for a failed message to be queued again. */
const MESSAGE_WHATSAPP_REPORT_RETRY = 'retry';

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

require_login();
require_capability('message/whatsapp:viewlog', context_system::instance());

$pageurl = new moodle_url('/message/output/whatsapp/report.php');

// The page is set up in one of two ways, and the reason is a property of core, not of this plugin. The settings.php
// of a `message` plugin is only ever included from admin/settings/messaging.php, whose whole body is wrapped in
// `if ($hassiteconfig)`, and \core\plugininfo\message::load_settings() closes the same door again from inside. So
// for anyone without moodle/site:config the admin node registered there does not exist, and asking
// admin_externalpage_setup() for it answers "Access denied" without ever looking at a capability. That would make
// message/whatsapp:viewlog unusable by exactly the role §3.8 grants it to.
//
// The capability above is the barrier; the admin tree is navigation. A site administrator gets the page inside the
// admin tree, where it belongs and where the breadcrumb works. Anybody else who holds the capability gets the same
// report on a plain page.
if (has_capability('moodle/site:config', context_system::instance())) {
    admin_externalpage_setup('message_whatsapp_log', '', null, '', ['pagelayout' => 'report']);
} else {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url($pageurl);
    $PAGE->set_pagelayout('report');
    $PAGE->set_title(get_string('logpage', 'message_whatsapp'));
    $PAGE->set_heading(get_string('logpage', 'message_whatsapp'));
}

if ($action === MESSAGE_WHATSAPP_REPORT_RETRY) {
    require_sesskey();

    $row = $DB->get_record(queue::TABLE, ['id' => $id], 'id, component, name, status');

    if (!$row) {
        redirect($pageurl, get_string('retrynotfound', 'message_whatsapp'), null, notification::NOTIFY_ERROR);
    }

    if ($row->status !== queue::STATUS_FAILED) {
        redirect(
            $pageurl,
            get_string('retrynotfailed', 'message_whatsapp', $row->id),
            null,
            notification::NOTIFY_WARNING
        );
    }

    if (!$confirm || !message_whatsapp_report_is_post()) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('logpage', 'message_whatsapp'));
        echo $OUTPUT->confirm(
            get_string('retryconfirm', 'message_whatsapp', (object) [
                'id' => $row->id,
                'component' => s($row->component . ' / ' . $row->name),
            ]),
            new moodle_url($pageurl, [
                'action' => MESSAGE_WHATSAPP_REPORT_RETRY,
                'id' => $row->id,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            $pageurl,
            ['continuestr' => get_string('retry', 'message_whatsapp')]
        );
        echo $OUTPUT->footer();
        die;
    }

    [$message, $type] = message_whatsapp_report_retry((int) $row->id);
    redirect($pageurl, $message, null, $type);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('logpage', 'message_whatsapp'));
echo html_writer::tag('p', get_string('logintro', 'message_whatsapp'));

$report = system_report_factory::create(log::class, context_system::instance());

echo $report->output();

echo $OUTPUT->footer();

/**
 * Whether the current request is a form submission and not a link being followed.
 *
 * @return bool True when the request method is POST.
 */
function message_whatsapp_report_is_post(): bool {
    return isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST';
}

/**
 * Puts one failed message back on the queue, and says what happened.
 *
 * What the row keeps and what it loses is the whole decision here:
 *
 * - `attempts` goes back to zero, because the plan says so and because otherwise the row would arrive at the
 *   ceiling of the retry policy on its first attempt and fail again without being tried.
 * - `error` is **left alone**. Zeroing the attempts already erases the only number that said this row had a
 *   history, and clearing the diagnostic as well would leave a re-queued row indistinguishable from one that was
 *   enqueued a second ago. The report would then have no way to answer "has this failed before, and why", which
 *   is the question an administrator presses this button to act on. The staleness costs nothing, because the
 *   error does not survive the next outcome either way: {@see \message_whatsapp\local\queue::mark_sent()} sets it
 *   to null and the two failure paths overwrite it.
 * - `providermsgid` is cleared. A failed row normally has none, but one abandoned mid send can: the provider
 *   accepted the message and the process died before the row was updated. Keeping that id on a row that is about
 *   to be sent again would let the delivery report of the *old* message land on the *new* attempt and mark it
 *   delivered when nothing had been delivered yet.
 * - `nextattempt` is dated through {@see \message_whatsapp\local\queue::defer_for_quiet_hours()} rather than set
 *   to zero. Nothing else in the plugin schedules a send without asking the quiet hours first, and a button that
 *   did would ring a phone at three in the morning because somebody was clearing a backlog.
 *
 * The condition on the statement is what makes this safe rather than merely checked: the caller read the row a
 * moment ago, and in that moment a webhook can have moved it to `delivered`. The database is the only place
 * where that race can be settled.
 *
 * @param int $id Id of the queue row, already known to have been failed a moment ago.
 * @return array{0: string, 1: string} The message to show and the notification type to show it as.
 */
function message_whatsapp_report_retry(int $id): array {
    global $DB;

    $now = time();
    $next = queue::defer_for_quiet_hours($now);
    $table = '{' . queue::TABLE . '}';

    $DB->execute(
        "UPDATE $table
            SET status = :pending, attempts = 0, nextattempt = :next, providermsgid = NULL,
                timesent = 0, timestatus = :now
          WHERE id = :id AND status = :failed",
        [
            'pending' => queue::STATUS_PENDING,
            'next' => $next,
            'now' => $now,
            'id' => $id,
            'failed' => queue::STATUS_FAILED,
        ]
    );

    // Moodle does not report affected rows portably, so the row is read back. It is the same technique
    // {@see \message_whatsapp\local\queue::claim()} uses, and for the same reason.
    $after = $DB->get_record(queue::TABLE, ['id' => $id], 'id, status, nextattempt');

    if (!$after || $after->status !== queue::STATUS_PENDING) {
        return [get_string('retrynotfailed', 'message_whatsapp', $id), notification::NOTIFY_WARNING];
    }

    if ((int) $after->nextattempt > $now) {
        return [
            get_string('retrydeferred', 'message_whatsapp', (object) [
                'id' => $after->id,
                'time' => userdate((int) $after->nextattempt),
            ]),
            notification::NOTIFY_INFO,
        ];
    }

    return [get_string('retryqueued', 'message_whatsapp', $after->id), notification::NOTIFY_SUCCESS];
}
