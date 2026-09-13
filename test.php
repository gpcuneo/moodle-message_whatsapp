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
 * The two things an administrator wants to do right after filling the settings in: check and try.
 *
 * "Test connection" asks the configured transport whether it could send right now. In direct mode that is a real
 * request to Meta with the credentials of the site, which is the point of the button: nothing else on the screen
 * can tell an administrator that the token they pasted is the wrong one.
 *
 * "Send a test to my number" **queues** a notification for the administrator and stops there. It does not send it.
 * The whole plugin is built on the rule that nothing reaches the network from a web request, and a test button
 * that broke the rule would be testing a path that no notification ever takes. What it does test is the path every
 * notification does take: the recipient, the opt-in, the template, the queue, and then the scheduled task.
 *
 * Both actions are POSTs with a session key. They are not decoration: one of them opens a connection to a third
 * party with the credentials of the site and the other one writes a row that will become a paid message, and
 * neither may be triggerable by loading an image.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use message_whatsapp\local\queue;
use message_whatsapp\local\recipient;
use message_whatsapp\transport\factory;
use message_whatsapp\transport\result;

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// The class that owns the mode constants lives at the root of the plugin, because that is where core looks for a
// message processor, so the autoloader does not know about it. `$CFG->dirroot` is the servable tree in both 4.5
// and 5.2, which makes this one string correct in both.
require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');

/** Value of the `action` parameter that asks for a connection check. */
const MESSAGE_WHATSAPP_TEST_CHECK = 'check';

/** Value of the `action` parameter that asks for a test notification to be queued. */
const MESSAGE_WHATSAPP_TEST_SEND = 'send';

admin_externalpage_setup('message_whatsapp_test');

// The call above already required a login and the capability the page was registered with. Both are
// repeated here on purpose: this file is an endpoint that opens a connection with the credentials of the site and
// writes rows that turn into paid messages, and its protection should be readable in the file that does those two
// things rather than inferred from a registration written in another one.
require_login();
require_capability('message/whatsapp:managesettings', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/message/output/whatsapp/test.php');

$checked = null;

if ($action === MESSAGE_WHATSAPP_TEST_CHECK) {
    require_sesskey();

    // The session is released before the request goes out. A `check()` in direct mode waits on Meta for as long as
    // the transport allows, and with the default file session handler a request that holds the session lock blocks
    // every other request of the same administrator: the rest of their tabs would hang on a button they pressed
    // here. Nothing below this line writes to the session, and the result is rendered into this response rather
    // than handed to the next one through it, which is also why this action does not redirect.
    \core\session\manager::write_close();

    $checked = factory::instance()->check();
}

if ($action === MESSAGE_WHATSAPP_TEST_SEND) {
    require_sesskey();

    [$message, $type] = message_whatsapp_test_queue_notification();

    // This one redirects, because unlike the check it leaves something behind: reloading the page afterwards must
    // not queue a second notification.
    redirect($pageurl, $message, null, $type);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('testpage', 'message_whatsapp'));

if ($checked !== null) {
    echo $OUTPUT->notification(
        get_string($checked->ok ? 'testconnectionok' : 'testconnectionfailed', 'message_whatsapp'),
        $checked->ok ? notification::NOTIFY_SUCCESS : notification::NOTIFY_ERROR
    );
    echo $OUTPUT->box(message_whatsapp_test_result_detail($checked), 'generalbox');
}

echo message_whatsapp_test_configuration_summary();

echo $OUTPUT->heading(get_string('testconnection', 'message_whatsapp'), 3);
echo html_writer::tag('p', get_string('testconnection_desc', 'message_whatsapp'));
echo $OUTPUT->single_button(
    new moodle_url($pageurl, ['action' => MESSAGE_WHATSAPP_TEST_CHECK, 'sesskey' => sesskey()]),
    get_string('testconnection', 'message_whatsapp'),
    'post'
);

echo $OUTPUT->heading(get_string('testsend', 'message_whatsapp'), 3);
echo html_writer::tag('p', get_string('testsend_desc', 'message_whatsapp'));
echo $OUTPUT->single_button(
    new moodle_url($pageurl, ['action' => MESSAGE_WHATSAPP_TEST_SEND, 'sesskey' => sesskey()]),
    get_string('testsend', 'message_whatsapp'),
    'post'
);

echo $OUTPUT->footer();

/**
 * Renders what the site is configured to do, so that a result on this page can be read against it.
 *
 * The transport is named because "connection OK" means something different depending on which one answered, and
 * the in memory transport is called out in as many words: a site running with it reports a working connection
 * whatever the credentials say, and an administrator who took that for an answer would have tested nothing.
 *
 * @return string HTML of the summary table.
 */
function message_whatsapp_test_configuration_summary(): string {
    global $CFG, $OUTPUT;

    $mode = trim((string) get_config('message_whatsapp', 'mode'));
    $modes = [
        message_output_whatsapp::MODE_DIRECT => get_string('modedirect', 'message_whatsapp'),
        message_output_whatsapp::MODE_GATEWAY => get_string('modegateway', 'message_whatsapp'),
    ];

    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->data = [
        [
            get_string('mode', 'message_whatsapp'),
            $modes[$mode] ?? get_string('testmodenone', 'message_whatsapp'),
        ],
        [
            get_string('testtransport', 'message_whatsapp'),
            html_writer::tag('code', s(factory::instance()->name())),
        ],
        [
            get_string('webhookurl', 'message_whatsapp'),
            html_writer::tag('code', s($CFG->wwwroot . '/message/output/whatsapp/webhook.php')),
        ],
    ];

    $out = $OUTPUT->heading(get_string('testcurrentconfig', 'message_whatsapp'), 3);

    if (factory::fake_transport_requested()) {
        $out .= $OUTPUT->notification(get_string('testfakewarning', 'message_whatsapp'), notification::NOTIFY_WARNING);
    }

    return $out . html_writer::table($table);
}

/**
 * Turns the answer of a transport into something an administrator can act on.
 *
 * The two halves of a failure are not the same kind of text and are not presented the same way. `code` is a stable
 * identifier that this plugin defines for everything it decides itself -- no mode configured, no credentials, the
 * connection never left the building -- so each of those gets a sentence in the language of the site that says
 * what to do about it. `message` is the diagnostic of the provider, in whatever language the provider answered in,
 * which for Meta is English: there is nothing to translate it from, and rewording it would mean guessing. So it is
 * shown verbatim, escaped, labelled as coming from the provider, below the translated line. The code is printed
 * too, because it is the string that is stable enough to search the documentation of Meta for and to quote in a
 * support ticket, while the message around it is not.
 *
 * A code this plugin has no sentence for is by definition one that came from the provider, and it gets the generic
 * line naming it. That is the case of every `meta_NNNNNN`: there are forty three of them, they change without
 * notice, and a translation of each one would be a second copy of the error table of Meta going stale in a
 * language file.
 *
 * @param result $result Answer of the transport.
 * @return string HTML of the detail block.
 */
function message_whatsapp_test_result_detail(result $result): string {
    if ($result->ok) {
        $transport = html_writer::tag('code', s(factory::instance()->name()));

        return html_writer::tag('p', get_string('testconnectionokdetail', 'message_whatsapp', $transport));
    }

    $key = 'testcode_' . $result->code;
    $reason = get_string_manager()->string_exists($key, 'message_whatsapp')
        ? get_string($key, 'message_whatsapp')
        : get_string('testcodeother', 'message_whatsapp', html_writer::tag('code', s($result->code)));

    $out = html_writer::tag('p', $reason);

    if (trim($result->message) !== '') {
        $out .= html_writer::tag(
            'p',
            get_string('testproviderdetail', 'message_whatsapp', html_writer::tag('samp', s($result->message)))
        );
    }

    return $out . html_writer::tag(
        'p',
        get_string('testerrorcode', 'message_whatsapp', html_writer::tag('code', s($result->code))),
        ['class' => 'text-muted']
    );
}

/**
 * Queues one test notification for the administrator that pressed the button, and says what became of it.
 *
 * Nothing is sent from here. The row goes into the queue exactly as a real notification does and the scheduled
 * task picks it up, which is the only way this button can test what actually happens to a notification.
 *
 * The refusals are told apart instead of being collapsed into one "cannot send", because each one is fixed in a
 * different place: no row at all means the administrator never opened their notification preferences, no consent
 * means the box is unticked, no number means the profile had none to find, and an invalid or blocked number means
 * the channel already tried and was turned away. Failing silently here would be the worst possible outcome on a
 * screen whose entire job is to explain why nothing is arriving.
 *
 * The number itself is never printed. It is personal data, the administrator can read it in their own preferences,
 * and a screen that echoes phone numbers is a screen that ends up in a screenshot.
 *
 * @return array{0: string, 1: string} The message to show and the notification type to show it as.
 */
function message_whatsapp_test_queue_notification(): array {
    global $DB, $USER;

    $prefs = (new moodle_url('/message/notificationpreferences.php', ['userid' => $USER->id]))->out();
    $recipient = recipient::find((int) $USER->id);

    if ($recipient === null) {
        return [get_string('testsendnorecipient', 'message_whatsapp', $prefs), notification::NOTIFY_WARNING];
    }

    if ($recipient->phone === '') {
        return [get_string('testsendnophone', 'message_whatsapp', $prefs), notification::NOTIFY_WARNING];
    }

    if ($recipient->status !== recipient::STATUS_ACTIVE) {
        return [get_string('testsendnotactive', 'message_whatsapp', $prefs), notification::NOTIFY_WARNING];
    }

    if (!$recipient->optin) {
        return [get_string('testsendnooptin', 'message_whatsapp', $prefs), notification::NOTIFY_WARNING];
    }

    $id = queue::enqueue(message_whatsapp_test_eventdata());

    if ($id <= 0) {
        return [get_string('testsendnotqueued', 'message_whatsapp'), notification::NOTIFY_ERROR];
    }

    return message_whatsapp_test_queued_outcome($DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST));
}

/**
 * Builds the event data of the test notification, in the shape core hands to a message processor.
 *
 * It goes through {@see \message_whatsapp\local\template_mapper} like any other notification, so what arrives on
 * the phone is the same template with the same three parameters and the same button as a real one. A test that
 * took a shortcut around the mapper would not be a test of anything the plugin does.
 *
 * @return stdClass Event data for {@see \message_whatsapp\local\queue::enqueue()}.
 */
function message_whatsapp_test_eventdata(): stdClass {
    global $CFG, $SITE, $USER;

    $sitename = format_string($SITE->fullname);

    return (object) [
        'userto' => $USER,
        'component' => 'message_whatsapp',
        'name' => 'test',
        'courseid' => SITEID,
        'subject' => get_string('testmessagesubject', 'message_whatsapp'),
        'smallmessage' => get_string('testmessagebody', 'message_whatsapp', $sitename),
        'fullmessage' => get_string('testmessagebody', 'message_whatsapp', $sitename),
        'fullmessageformat' => FORMAT_PLAIN,
        'fullmessagehtml' => '',
        'contexturl' => $CFG->wwwroot,
        'contexturlname' => $sitename,
    ];
}

/**
 * Reads back the queued row and reports what the queue decided to do with it.
 *
 * The queue applies the quiet hours and the daily limit when it writes, not when it sends, so by the time this row
 * exists the answer already is what it is. Reporting "queued" for a row that the daily limit skipped, or for one
 * that will not move for another nine hours, would send an administrator looking for a bug in cron.
 *
 * @param stdClass $row The queue row as it was written.
 * @return array{0: string, 1: string} The message to show and the notification type to show it as.
 */
function message_whatsapp_test_queued_outcome(stdClass $row): array {
    if ($row->status === queue::STATUS_SKIPPED && $row->error === queue::SKIP_DAILY_CAP) {
        return [get_string('testsendcapped', 'message_whatsapp', $row->id), notification::NOTIFY_WARNING];
    }

    if ($row->status === queue::STATUS_SKIPPED) {
        return [get_string('testsendskipped', 'message_whatsapp', $row->id), notification::NOTIFY_WARNING];
    }

    if ((int) $row->nextattempt > time()) {
        return [
            get_string('testsenddeferred', 'message_whatsapp', (object) [
                'id' => $row->id,
                'time' => userdate((int) $row->nextattempt),
            ]),
            notification::NOTIFY_INFO,
        ];
    }

    return [get_string('testsendqueued', 'message_whatsapp', $row->id), notification::NOTIFY_SUCCESS];
}
