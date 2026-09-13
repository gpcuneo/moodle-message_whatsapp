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
 * Endpoint Meta calls with the delivery reports of the messages this site sent, in direct mode.
 *
 * Two requests arrive here. A GET is the subscription handshake: Meta asks for a token it was given in the app
 * configuration and expects its own challenge echoed back. A POST is a delivery report, and it is signed: the body
 * carries an HMAC of itself under the app secret, in `X-Hub-Signature-256`.
 *
 * Everything that arrives here is hostile until the signature says otherwise, and the signature is checked over the
 * bytes that came in, before anything is parsed and before anything is written. Reading the JSON and encoding it
 * again to check the signature cannot work: a whitespace, a key order or an escaped slash changes the bytes and the
 * HMAC with them. There is no login, no session and no token of ours in the URL, so the signature is the only thing
 * standing between the queue and the internet.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Meta calls this with no cookies and reads only the status code, so there is no session to start and no debug
// output that may corrupt the body. Both constants are already set when the PHPUnit bootstrap loaded this file,
// which is why they are defined in the idempotent form. The sniff below knows a bare define() may come before the
// inclusion of config.php, as these two must, but does not recognise that form of it.
// phpcs:disable moodle.Files.MoodleInternal
defined('NO_MOODLE_COOKIES') || define('NO_MOODLE_COOKIES', true);
defined('NO_DEBUG_DISPLAY') || define('NO_DEBUG_DISPLAY', true);
// phpcs:enable moodle.Files.MoodleInternal

require_once(__DIR__ . '/../../../config.php');

// Largest body this endpoint will look at, in bytes. A delivery report is a couple of kilobytes; anything of this
// size is not one, and refusing it before the JSON parser sees it costs one comparison instead of a megabyte of
// parsing done on behalf of whoever sent it.
defined('MESSAGE_WHATSAPP_WEBHOOK_MAX_BODY') || define('MESSAGE_WHATSAPP_WEBHOOK_MAX_BODY', 1048576);

/**
 * Runs the endpoint: the subscription handshake on GET, a delivery report on POST.
 *
 * @return void
 */
function message_whatsapp_webhook_handle_request(): void {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        message_whatsapp_webhook_handle_verification();
        return;
    }

    if ($method === 'POST') {
        message_whatsapp_webhook_handle_report();
        return;
    }

    message_whatsapp_webhook_respond(405, '');
}

/**
 * Answers the subscription handshake Meta makes when the webhook is configured.
 *
 * @return void
 */
function message_whatsapp_webhook_handle_verification(): void {
    $challenge = message_whatsapp_webhook_challenge(
        message_whatsapp_webhook_query_param('hub.mode'),
        message_whatsapp_webhook_query_param('hub.verify_token'),
        message_whatsapp_webhook_query_param('hub.challenge'),
        (string) get_config('message_whatsapp', 'metaverifytoken')
    );

    if ($challenge === null) {
        message_whatsapp_webhook_respond(403, '');
        return;
    }

    message_whatsapp_webhook_respond(200, $challenge);
}

/**
 * Takes a delivery report off the wire and answers with the status code it earned.
 *
 * @return void
 */
function message_whatsapp_webhook_handle_report(): void {
    message_whatsapp_webhook_respond(
        message_whatsapp_webhook_report(
            message_whatsapp_webhook_raw_body(),
            (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''),
            (string) get_config('message_whatsapp', 'metaappsecret')
        ),
        ''
    );
}

/**
 * Applies a signed delivery report and returns the HTTP status code the caller deserves.
 *
 * The whole of the POST path, with the request and the configuration as parameters so that it can be exercised
 * without a web server. The order of the three checks is the security property: size, then signature, then
 * content. Nothing below the signature check runs for a body that did not carry a good one, which is what "an
 * invalid signature has no effect" means in practice: the JSON is never parsed, no row is read or written, and
 * nothing of the body is recorded anywhere, not even to complain about it.
 *
 * @param string $body Raw request body, exactly as it arrived.
 * @param string $signature Contents of the `X-Hub-Signature-256` header, `sha256=` and hexadecimal.
 * @param string $secret App secret of the Meta application, from the settings. Empty means "not configured".
 * @return int HTTP status code: 200 accepted, 400 unparseable, 403 unsigned or badly signed, 413 too big.
 */
function message_whatsapp_webhook_report(string $body, string $signature, string $secret): int {
    if (strlen($body) > MESSAGE_WHATSAPP_WEBHOOK_MAX_BODY) {
        return 413;
    }

    if (!message_whatsapp_webhook_signature_matches($body, $signature, $secret)) {
        return 403;
    }

    return message_whatsapp_webhook_apply($body) === null ? 400 : 200;
}

/**
 * Tells whether a body really carries the signature of the app secret of this site.
 *
 * The HMAC is taken over the bytes that arrived and compared with {@see hash_equals()}, never with `==`: this runs
 * for anyone who can reach the site, and a comparison that stops at the first byte that differs hands a forger the
 * signature one byte at a time. A site with no app secret configured never matches, because an empty secret would
 * otherwise let anyone who knows it is empty write delivery reports into the queue.
 *
 * @param string $body Raw request body, exactly as it arrived.
 * @param string $signature Contents of the `X-Hub-Signature-256` header.
 * @param string $secret App secret of the Meta application.
 * @return bool True only when the body was signed with that secret.
 */
function message_whatsapp_webhook_signature_matches(string $body, string $signature, string $secret): bool {
    if ($secret === '' || strncmp($signature, 'sha256=', 7) !== 0) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $body, $secret), substr($signature, 7));
}

/**
 * Returns the challenge to echo back in the subscription handshake, or null when the handshake is refused.
 *
 * @param string $mode Value of `hub.mode`; Meta sends `subscribe`.
 * @param string $token Value of `hub.verify_token`, chosen by whoever configured the app.
 * @param string $challenge Value of `hub.challenge`, a random string Meta wants back verbatim.
 * @param string $expected The verify token this site was configured with. Empty means "not configured".
 * @return string|null The challenge when the token is right, null otherwise.
 */
function message_whatsapp_webhook_challenge(
    string $mode,
    string $token,
    string $challenge,
    string $expected
): ?string {
    if ($expected === '' || $mode !== 'subscribe') {
        return null;
    }

    return hash_equals($expected, $token) ? $challenge : null;
}

/**
 * Applies every delivery status a verified payload carries.
 *
 * A payload that is not about delivery statuses is not an error: Meta sends template approvals, account alerts and
 * inbound messages through the same subscription, and a webhook that answered anything but 200 to those would be
 * retried and eventually disabled by Meta. They are read for what this plugin understands and ignored otherwise.
 *
 * @param string $body Raw request body whose signature has already been verified.
 * @return int|null Number of queue rows moved forward, or null when the body was not valid JSON.
 */
function message_whatsapp_webhook_apply(string $body): ?int {
    $payload = json_decode($body, true);

    if (!is_array($payload)) {
        return null;
    }

    $applied = 0;
    $inbound = 0;

    foreach (message_whatsapp_webhook_values($payload) as $value) {
        foreach (message_whatsapp_webhook_list($value, 'statuses') as $status) {
            $applied += message_whatsapp_webhook_apply_status($status) ? 1 : 0;
        }

        $inbound += count(message_whatsapp_webhook_list($value, 'messages'));
    }

    if ($inbound > 0) {
        // Inbound messages are what the free service window is built on and v2 will act on them. Nothing they
        // carry may be written down here: the number they came from and the text that was typed are both personal
        // data under Ley 25.326, so what is recorded is how many arrived and not one byte of what they said.
        debugging(
            'message_whatsapp: ' . $inbound . ' inbound WhatsApp message(s) received; v1 does not answer them',
            DEBUG_DEVELOPER
        );
    }

    return $applied;
}

/**
 * Applies one entry of the `statuses` list of a payload.
 *
 * @param array $status One delivery status as Meta shaped it.
 * @return bool True when it moved a queue row forward.
 */
function message_whatsapp_webhook_apply_status(array $status): bool {
    $id = $status['id'] ?? null;
    $state = $status['status'] ?? null;

    if (!is_string($id) || !is_string($state) || $id === '') {
        return false;
    }

    $pricing = is_array($status['pricing'] ?? null) ? ($status['pricing']['category'] ?? null) : null;

    return \message_whatsapp\local\queue::update_by_providermsgid(
        $id,
        $state,
        message_whatsapp_webhook_timestamp($status['timestamp'] ?? null),
        is_string($pricing) ? $pricing : null,
        message_whatsapp_webhook_error_text($status)
    );
}

/**
 * Returns the diagnostic of a failed status, with the parts that may name a person left out.
 *
 * Meta reports a failure as a code, a short title and a longer `message` with `error_data.details` beside it. Only
 * the code and the title are kept. The other two are written for a human reading a console and do quote the
 * recipient, as in "Recipient phone number not in allowed list", and this text ends up in the administrator report
 * of the site, where the phone number of a student has no business being.
 *
 * @param array $status One delivery status as Meta shaped it.
 * @return string|null The diagnostic, or null when the status reported no error.
 */
function message_whatsapp_webhook_error_text(array $status): ?string {
    $errors = is_array($status['errors'] ?? null) ? $status['errors'] : [];
    $parts = [];

    foreach ($errors as $error) {
        if (!is_array($error)) {
            continue;
        }

        $code = isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : '';
        $title = isset($error['title']) && is_string($error['title']) ? $error['title'] : '';
        $text = trim($code . ' ' . $title);

        if ($text !== '') {
            $parts[] = $text;
        }
    }

    return $parts === [] ? null : implode('; ', $parts);
}

/**
 * Reads the moment a status was reported at.
 *
 * @param mixed $value Value of `timestamp`, which Meta sends as a string of digits.
 * @return int Unix timestamp, or 0 when there was nothing usable to read.
 */
function message_whatsapp_webhook_timestamp($value): int {
    if (!is_scalar($value) || !is_numeric($value)) {
        return 0;
    }

    return max(0, (int) $value);
}

/**
 * Returns every `changes[].value` of a payload, which is where Meta puts what a notification is about.
 *
 * @param array $payload Decoded payload.
 * @return array[] The value objects, in the order they arrived.
 */
function message_whatsapp_webhook_values(array $payload): array {
    $values = [];
    $entries = is_array($payload['entry'] ?? null) ? $payload['entry'] : [];

    foreach ($entries as $entry) {
        $changes = is_array($entry) && is_array($entry['changes'] ?? null) ? $entry['changes'] : [];

        foreach ($changes as $change) {
            $value = is_array($change) ? ($change['value'] ?? null) : null;

            if (is_array($value)) {
                $values[] = $value;
            }
        }
    }

    return $values;
}

/**
 * Returns one of the lists of a `value` object, with anything that is not an object dropped.
 *
 * @param array $value One `changes[].value` of a payload.
 * @param string $key Name of the list, `statuses` or `messages`.
 * @return array[] The entries of the list, possibly none.
 */
function message_whatsapp_webhook_list(array $value, string $key): array {
    $list = $value[$key] ?? null;

    return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
}

/**
 * Reads a query string parameter whose name contains a dot, as every parameter of the handshake does.
 *
 * PHP rewrites the dot of `hub.mode` into an underscore when it builds `$_GET`, so both spellings are looked for
 * rather than trusting that behaviour to stay as it is.
 *
 * @param string $name Name of the parameter as Meta spells it, for instance `hub.mode`.
 * @return string The value, or the empty string when it was absent or was not a single value.
 */
function message_whatsapp_webhook_query_param(string $name): string {
    $value = $_GET[$name] ?? ($_GET[str_replace('.', '_', $name)] ?? '');

    return is_scalar($value) ? (string) $value : '';
}

/**
 * Returns the raw request body, which is the only form of it the signature can be checked against.
 *
 * @return string The bytes that arrived, possibly none.
 */
function message_whatsapp_webhook_raw_body(): string {
    $body = file_get_contents('php://input');

    return is_string($body) ? $body : '';
}

/**
 * Writes the answer. The body is either empty or the handshake challenge; Meta only reads the status code.
 *
 * @param int $code HTTP status code.
 * @param string $body Body to write.
 * @return void
 */
function message_whatsapp_webhook_respond(int $code, string $body): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo $body;
}

if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
    message_whatsapp_webhook_handle_request();
}
