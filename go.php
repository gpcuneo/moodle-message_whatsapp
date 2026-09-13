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
 * Where the button of a WhatsApp notification lands: records the click and sends the reader on to the page.
 *
 * The URL button of an approved template has a fixed base and one variable suffix, so the link that reaches a phone
 * is this script plus a token. The token names a queue row and is signed with the `siteidentifier` of the site, so
 * it cannot be made up and cannot be edited to point at somebody else's row.
 *
 * **This is not an open redirect, and the reason is where the destination comes from.** The request carries a
 * signed row id and nothing else: there is no address in it, no parameter that could hold one, and no code path
 * that reads one. The destination is read from the queue row, where the template mapper wrote the `contexturl` of a
 * Moodle event, and it is then checked against `wwwroot` before it is used, so a destination outside this site is
 * refused even if it somehow got into the database. A request that fails any of that is sent to the front page of
 * the site, which is the most conservative place a redirect can end.
 *
 * There is no login: the reader is coming from WhatsApp on a phone, and whatever page they land on applies its own
 * access rules, which is also why someone already logged in on that phone stays logged in at the destination.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This endpoint needs no session of its own: it reads a signed id, writes a click and redirects. The reader keeps
// whatever session cookie they already had, which is what the page they land on will look at, and not taking the
// session lock on every click of every notification is worth having. The constant is already set when the PHPUnit
// bootstrap loaded this file, hence the idempotent form, which the sniff below does not recognise.
// phpcs:disable moodle.Files.MoodleInternal
defined('NO_MOODLE_COOKIES') || define('NO_MOODLE_COOKIES', true);
// phpcs:enable moodle.Files.MoodleInternal

require_once(__DIR__ . '/../../../config.php');

/**
 * Runs the endpoint: reads the token of the request and redirects.
 *
 * @return void
 */
function message_whatsapp_go_handle_request(): void {
    $token = optional_param('t', '', PARAM_RAW);

    redirect(message_whatsapp_go_target($token, message_whatsapp_go_useragent()));
}

/**
 * Returns where a click on $token has to land, recording the click when the token names a real row.
 *
 * Nothing is recorded for a token that does not verify or for a row that is no longer there: a click that cannot
 * be attributed to a message this site sent is not evidence of anything, and writing it would let anyone who can
 * reach the endpoint put rows in a table of the site.
 *
 * @param string $token Token as it arrived in the request. Hostile until it verifies.
 * @param string|null $useragent User agent of the click, when the browser announced one.
 * @return moodle_url Where to send the browser; the front page when there is nothing better.
 */
function message_whatsapp_go_target(string $token, ?string $useragent): moodle_url {
    global $DB;

    $queueid = \message_whatsapp\local\queue::queueid_from_click_token($token);

    if ($queueid <= 0) {
        return new moodle_url('/');
    }

    $row = $DB->get_record(\message_whatsapp\local\queue::TABLE, ['id' => $queueid], 'id, url');

    if (!$row) {
        return new moodle_url('/');
    }

    message_whatsapp_go_record_click($queueid, $useragent);

    return message_whatsapp_go_safe_url((string) ($row->url ?? ''));
}

/**
 * Turns the address stored in a queue row into somewhere this endpoint is willing to send a browser.
 *
 * The second lock on the same door. The address already comes from the site rather than from the request, and this
 * refuses anything that is not inside `wwwroot` anyway, so no value that could ever reach this column turns the
 * endpoint into a way of sending a reader off the site under the name of the site.
 *
 * @param string $url Address stored in the queue row, possibly empty.
 * @return moodle_url That address when it is inside this site, the front page otherwise.
 */
function message_whatsapp_go_safe_url(string $url): moodle_url {
    global $CFG;

    $root = rtrim($CFG->wwwroot, '/');

    if ($url === '' || strpos($url, $root . '/') !== 0) {
        return new moodle_url('/');
    }

    return new moodle_url($url);
}

/**
 * Writes one row for a click on the button of a message.
 *
 * @param int $queueid Queue row whose button was clicked.
 * @param string|null $useragent User agent of the click, when the browser announced one.
 * @return int Id of the row that was written.
 */
function message_whatsapp_go_record_click(int $queueid, ?string $useragent): int {
    global $DB;

    return (int) $DB->insert_record('message_whatsapp_click', (object) [
        'queueid' => $queueid,
        'timeclicked' => time(),
        'useragent' => $useragent === null ? null : \core_text::substr($useragent, 0, 255),
    ]);
}

/**
 * Returns the user agent of the request.
 *
 * @return string|null What the browser announced itself as, or null when it announced nothing.
 */
function message_whatsapp_go_useragent(): ?string {
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    return is_string($agent) && $agent !== '' ? $agent : null;
}

if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
    message_whatsapp_go_handle_request();
}
