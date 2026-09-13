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
 * Outcome of one attempt of a WhatsApp transport.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\transport;

/**
 * What a transport answers, and what the queue decides from it.
 *
 * This is the value the sending task turns into a row state. Three answers exist and no more:
 *
 * - {@see self::success()}: it left. The provider message id comes back with it, and the delivery reports that
 *   arrive minutes later by webhook or by cursor are matched to the queue row by that id.
 * - {@see self::permanent_failure()}: it will never leave. The number is not on WhatsApp, the template was
 *   rejected, the token is wrong. The row goes to `failed` on the first attempt and the administrator sees it in
 *   the report.
 * - {@see self::transient_failure()}: it did not leave this time. Timeout, 429, 5xx, DNS. The row goes back to
 *   `pending` with its backoff and is attempted again until the ceiling of five attempts.
 *
 * **The class is built so that those three are the only shapes that can exist.** The difference between the last
 * two is one boolean, `permanent`, and getting it the wrong way round is invisible in review and expensive in
 * production: inverted one way the plugin hammers Meta for an hour with a number that will never be valid, and
 * inverted the other way it throws away a notification because a router dropped one packet. So there is no public
 * constructor taking five positional arguments, where a `true` in the third slot is just a `true`. There are three
 * named constructors whose names are the decision, and the reader of the call site does not have to remember the
 * order of the arguments to know which of the three it is. It is the same reason
 * {@see \message_whatsapp\local\recipient} has a private constructor: an object that can only be built correctly
 * cannot be built incorrectly.
 *
 * The object is immutable, like {@see \message_whatsapp\local\mapped}: it crosses from the transport to the queue
 * and is read by the code that writes the row state, and nothing in between has any business editing it.
 *
 * `message` is a diagnostic for the administrator report and the task log, in whatever language the provider
 * happened to answer in. It is **not** a string for the interface: the text of a Meta error arrives in English from
 * Meta and there is nothing to translate it from. What the interface translates, if it wants to, is `code`, which
 * is a stable machine identifier. Neither of the two ever contains the phone number: the report shows the error
 * next to the user it belongs to, and a phone number in a log is personal data that outlives its purpose.
 *
 * There is no pricing category here on purpose. Meta does not bill on the send call; the category arrives with the
 * delivery status, by webhook in direct mode and by the status cursor in gateway mode, which is why
 * {@see \message_whatsapp\local\queue::mark_sent()} takes it as a separate optional argument.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result {
    /** Code used when a transport reports a failure without naming it. Never let a failure be codeless. */
    public const CODE_UNKNOWN = 'unknown';

    /**
     * Private on purpose: a result is always built through one of the three named constructors.
     *
     * @param bool $ok Whether the attempt succeeded.
     * @param string|null $providermsgid Message id the provider assigned, only ever set on success.
     * @param bool $permanent Whether retrying is pointless. Always false on success.
     * @param string $code Stable machine identifier of the failure, empty on success.
     * @param string $message Diagnostic for the administrator, empty on success. Never contains the phone number.
     */
    private function __construct(
        /** @var bool Whether the attempt succeeded. */
        public readonly bool $ok,
        /** @var string|null Message id the provider assigned, only ever set on success. */
        public readonly ?string $providermsgid,
        /** @var bool Whether retrying is pointless. Always false on success. */
        public readonly bool $permanent,
        /** @var string Stable machine identifier of the failure, empty on success. */
        public readonly string $code,
        /** @var string Diagnostic for the administrator, empty on success. */
        public readonly string $message,
    ) {
    }

    /**
     * The message left, or the connection is usable.
     *
     * @param string|null $providermsgid Id the provider assigned to the message, when it assigned one. A `check()`
     *      sends nothing and has none.
     * @return self A successful result, which is never permanent and never retryable.
     */
    public static function success(?string $providermsgid = null): self {
        $id = $providermsgid !== null ? trim($providermsgid) : null;

        return new self(true, ($id === '' ? null : $id), false, '', '');
    }

    /**
     * The message will never leave, and trying again changes nothing.
     *
     * For anything the provider blames on the request itself: a number that is not on WhatsApp, a template that
     * does not exist or was rejected, invalid credentials, a suspended account. The queue writes the row off on
     * the first attempt instead of spending five of them to reach the same conclusion an hour later.
     *
     * @param string $code Stable machine identifier of the failure, for branching and for translation.
     * @param string $message Diagnostic for the administrator. Never the phone number.
     * @return self A failed result that must not be retried.
     */
    public static function permanent_failure(string $code, string $message): self {
        return new self(false, null, true, self::clean_code($code), trim($message));
    }

    /**
     * The message did not leave this time, and the same attempt may work later.
     *
     * For everything that is about the moment and not about the request: timeouts, refused connections, DNS, 429,
     * 5xx. The queue puts the row back to `pending` with its exponential backoff.
     *
     * @param string $code Stable machine identifier of the failure, for branching and for translation.
     * @param string $message Diagnostic for the administrator. Never the phone number.
     * @return self A failed result that is worth retrying.
     */
    public static function transient_failure(string $code, string $message): self {
        return new self(false, null, false, self::clean_code($code), trim($message));
    }

    /**
     * Whether the sending task should put this row back in the queue.
     *
     * The one question the queue asks of a result, asked once and in one place so that no caller has to combine
     * the two flags by hand and get the combination wrong.
     *
     * @return bool True when the attempt failed and failing again is not certain.
     */
    public function is_retryable(): bool {
        return !$this->ok && !$this->permanent;
    }

    /**
     * Normalises the code of a failure so that it is never empty.
     *
     * A failure with no code cannot be branched on, cannot be translated and cannot be counted in a report. Rather
     * than throw at the one moment the plugin is already handling a problem, an unnamed failure is named `unknown`
     * and stays visible in the report.
     *
     * @param string $code Code as the transport supplied it.
     * @return string The trimmed code, or `unknown` when there was none.
     */
    private static function clean_code(string $code): string {
        $code = trim($code);

        return $code === '' ? self::CODE_UNKNOWN : $code;
    }
}
