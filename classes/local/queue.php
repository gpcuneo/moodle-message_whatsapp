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
 * Outgoing queue of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\local;

use core\lock\lock;
use core\lock\lock_config;
use stdClass;

/**
 * The database queue every WhatsApp notification passes through, and the policy that drains it.
 *
 * Moodle calls `send_message()` inside the web request of whoever triggered the event, so the processor cannot talk
 * to Meta there: it writes one row here and returns. A scheduled task claims those rows a minute later and is the
 * only thing in the plugin that ever reaches the network. This class owns both ends of that handover: it writes the
 * rows, it hands them out, and it decides when a failed one is tried again.
 *
 * Three groups of methods, deliberately kept apart:
 *
 * - {@see self::enqueue()} writes a row, and {@see self::claim()}, {@see self::mark_sent()},
 *   {@see self::mark_failed()}, {@see self::mark_retry()} and {@see self::mark_skipped()} move it through its
 *   states. They touch the database and nothing else.
 * - {@see self::backoff_seconds()}, {@see self::in_quiet_hours()}, {@see self::quiet_hours_end()},
 *   {@see self::day_bounds()} and {@see self::exceeds_daily_cap()} are pure: every input is a parameter, they read
 *   no setting, no clock and no database, and they return the same answer for the same arguments. The rules that
 *   are worth arguing about (when a retry happens, whether a message may leave at this hour, whether a user has had
 *   enough for one day) live there so that they can be tested directly instead of through a row.
 * - The private helpers in between read the settings and the clock and then call the pure ones.
 *
 * The statuses are those of the architecture: `pending` is waiting, `sending` is claimed by a task run, `sent` was
 * accepted by the provider, `delivered` and `read` come back from a webhook, `failed` gave up and `skipped` was
 * never attempted (no consent, or over the daily cap). {@see self::update_by_providermsgid()} is the door the
 * delivery reports come in through, and it only ever moves a row forward.
 *
 * The phone number is personal data: it is copied into the row at enqueue time so that a later profile change does
 * not rewrite history, and it is never written to a log or to an exception message.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue {
    /** @var string Table this class owns. */
    public const TABLE = 'message_whatsapp_queue';

    /** Waiting for a task run to pick it up, not before `nextattempt`. */
    public const STATUS_PENDING = 'pending';

    /** Claimed by a task run that is talking to the provider right now. */
    public const STATUS_SENDING = 'sending';

    /** The provider accepted it. */
    public const STATUS_SENT = 'sent';

    /** The provider reported it as delivered to the phone. */
    public const STATUS_DELIVERED = 'delivered';

    /** The provider reported that the recipient opened it. */
    public const STATUS_READ = 'read';

    /** Given up on: a permanent error, or the attempt ceiling. */
    public const STATUS_FAILED = 'failed';

    /** Never attempted: no consent, or over the daily cap. */
    public const STATUS_SKIPPED = 'skipped';

    /** Attempts a row gets before it is failed for good. */
    public const MAX_ATTEMPTS = 5;

    /** Ceiling of the exponential backoff, in minutes. */
    public const BACKOFF_MAX_MINUTES = 60;

    /**
     * How long a row may stay `sending` before it is treated as orphaned, in seconds.
     *
     * The task runs every minute and a request to Meta times out long before this, so a row still marked `sending`
     * after fifteen minutes belongs to a process that died. Generous on purpose: see {@see self::requeue_stale()}.
     */
    public const STALE_SENDING_SECONDS = 900;

    /** Reason stored in `error` when the recipient has no usable number or has not opted in. */
    public const SKIP_NO_OPTIN = 'nooptin';

    /** Reason stored in `error` when the user already reached the daily cap. */
    public const SKIP_DAILY_CAP = 'dailycap';

    /** Reason stored in `error` when a row was found abandoned in the `sending` state. */
    public const ERROR_ORPHANED = 'orphaned';

    /** Hour the quiet hours start at when the administrator has not said otherwise. */
    public const DEFAULT_QUIET_START = 22;

    /** Hour the quiet hours end at when the administrator has not said otherwise. */
    public const DEFAULT_QUIET_END = 8;

    /** Longest error text kept in a row; a provider can answer with a whole HTML page. */
    protected const MAX_ERROR_LENGTH = 1000;

    /** Length of the `pricingcategory` column, in characters. */
    protected const MAX_PRICING_CATEGORY_LENGTH = 40;

    /** Hexadecimal characters of signature carried by a click token; 128 bits is far more than this needs. */
    protected const CLICK_TOKEN_SIGNATURE_LENGTH = 32;

    /**
     * The delivery statuses a provider reports, and how far along they are.
     *
     * The order is what {@see self::supersedes()} compares, and it is the whole of the out of order policy: a
     * message that was accepted is `sent`, one that never made it to the phone is `failed`, and `delivered` and
     * `read` are proof that it did arrive, so they outrank a failure. A status missing from this list, which is
     * what `pending`, `sending` and `skipped` are, ranks below all of them.
     */
    protected const PROVIDER_STATUS_ORDER = [
        self::STATUS_SENT => 1,
        self::STATUS_FAILED => 2,
        self::STATUS_DELIVERED => 3,
        self::STATUS_READ => 4,
    ];

    /**
     * Puts one notification on the queue and returns the id of the row it created.
     *
     * A row is written even when nothing will be sent, with status `skipped` and the reason in `error`: the
     * administrator report has to be able to answer "why did this person not get the message", and a decision that
     * leaves no trace cannot be audited. The two reasons are a recipient that cannot be sent to and a user that
     * already reached the daily cap.
     *
     * The opt-in is checked here as a second barrier. Core asks `is_user_configured()` before it calls the
     * processor at all, but the `pre_processor_message_send` extension point lets another plugin of the site change
     * the event data on the way in, so the recipient is resolved again from the user id we actually received.
     *
     * The recipient is looked up with {@see recipient::find()} and not with `resolve()`: this runs on the send
     * path, where a missing row means missing consent, so there is nothing to find out and nothing to write.
     *
     * @param stdClass $eventdata Event data as core hands it to the processor, possibly incomplete.
     * @return int Id of the queue row, or 0 when the event data named no recipient to queue anything for.
     */
    public static function enqueue(stdClass $eventdata): int {
        global $DB;

        $userid = self::recipient_userid($eventdata);
        if ($userid <= 0) {
            return 0;
        }

        $recipient = recipient::find($userid);
        $mapped = template_mapper::map($eventdata);
        $now = time();

        // The CHAR NOT NULL columns of this table carry no default, so component, name, phone, templatekey and
        // lang are always given a value here, even when that value is the empty string.
        $record = (object) [
            'userid' => $userid,
            'savedmessageid' => self::int_field($eventdata, 'savedmessageid') ?: null,
            'component' => self::char_field($eventdata, 'component', 100),
            'name' => self::char_field($eventdata, 'name', 100),
            'courseid' => self::int_field($eventdata, 'courseid'),
            'phone' => $recipient === null ? '' : $recipient->phone,
            'templatekey' => $mapped->templatekey,
            'lang' => $mapped->lang,
            'params' => $mapped->params_json(),
            'url' => $mapped->url,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'nextattempt' => 0,
            'providermsgid' => null,
            'error' => null,
            'pricingcategory' => null,
            'timecreated' => $now,
            'timesent' => 0,
            'timestatus' => $now,
        ];

        if ($recipient === null || !$recipient->is_sendable()) {
            $record->status = self::STATUS_SKIPPED;
            $record->error = self::SKIP_NO_OPTIN;
        } else if (self::exceeds_daily_cap(self::count_queued_today($userid, $now), self::daily_cap())) {
            $record->status = self::STATUS_SKIPPED;
            $record->error = self::SKIP_DAILY_CAP;
        } else {
            $record->nextattempt = self::defer_for_quiet_hours($now);
        }

        return (int) $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Hands out up to $limit rows that are due, marking them `sending` so that nothing else takes them.
     *
     * Two runs of the sending task must never get the same row, and three things together guarantee that:
     *
     * 1. A named lock from the core lock API is held for the whole claim, so only one run of this code is ever in
     *    flight against a given site. The lock is taken with a zero timeout: a run that finds the queue busy has
     *    nothing useful to wait for, because the run that holds the lock is already draining the same rows.
     * 2. The claim happens inside one transaction, so the batch is all or nothing. A process that dies between
     *    choosing the rows and marking them cannot leave half a batch claimed.
     * 3. The update that marks them carries `AND status = 'pending'`. This is what makes the claim correct even if
     *    the lock were ever lost or bypassed: the row level write lock of the database serialises the two updates,
     *    and the one that arrives second no longer matches its own WHERE clause, so it cannot take a row that the
     *    first one already flipped.
     *
     * The read back after the update is what turns those three into a usable answer. Moodle's database layer does
     * not report affected rows in a portable way, so the only way to learn which rows were actually won is to read
     * them again; that read is only unambiguous because the lock guarantees nobody else could have touched them in
     * between.
     *
     * @param int $limit Largest number of rows to claim. Zero or less claims nothing.
     * @return stdClass[] The claimed rows, keyed by id, oldest due first. Already marked `sending` in the database.
     */
    public static function claim(int $limit): array {
        if ($limit <= 0) {
            return [];
        }

        $lock = self::claim_lock();
        if ($lock === null) {
            return [];
        }

        try {
            return self::claim_batch($limit);
        } finally {
            $lock->release();
        }
    }

    /**
     * Claims a batch inside a transaction. Only ever called with the claim lock held.
     *
     * @param int $limit Largest number of rows to claim.
     * @return stdClass[] The claimed rows, keyed by id.
     */
    protected static function claim_batch(int $limit): array {
        global $DB;

        $now = time();
        $transaction = $DB->start_delegated_transaction();

        try {
            self::requeue_stale($now);

            $due = $DB->get_records_select(
                self::TABLE,
                'status = :status AND nextattempt <= :now',
                ['status' => self::STATUS_PENDING, 'now' => $now],
                'nextattempt ASC, id ASC',
                'id',
                0,
                $limit
            );

            if (!$due) {
                $transaction->allow_commit();
                return [];
            }

            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($due), SQL_PARAMS_NAMED, 'claimid');

            $DB->execute(
                'UPDATE {' . self::TABLE . '}
                    SET status = :sending, timestatus = :now
                  WHERE id ' . $insql . ' AND status = :pending',
                $inparams + ['sending' => self::STATUS_SENDING, 'now' => $now, 'pending' => self::STATUS_PENDING]
            );

            $claimed = $DB->get_records_select(
                self::TABLE,
                'id ' . $insql . ' AND status = :sending',
                $inparams + ['sending' => self::STATUS_SENDING],
                'nextattempt ASC, id ASC'
            );

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Rollback rethrows what it is given, so the throw below is only there to close the return path.
            $transaction->rollback($e);
            throw $e;
        }

        return $claimed;
    }

    /**
     * Marks a claimed row as accepted by the provider.
     *
     * @param int $id Id of the queue row.
     * @param string|null $providermsgid Message id returned by Meta or by the gateway, used to match the reports.
     * @param string|null $pricingcategory Pricing category the provider reported, when it reported one.
     * @return void
     */
    public static function mark_sent(int $id, ?string $providermsgid = null, ?string $pricingcategory = null): void {
        global $DB;

        $now = time();
        $attempts = (int) $DB->get_field(self::TABLE, 'attempts', ['id' => $id], MUST_EXIST);

        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_SENT,
            'providermsgid' => $providermsgid,
            'pricingcategory' => $pricingcategory,
            'error' => null,
            'attempts' => $attempts + 1,
            'nextattempt' => 0,
            'timesent' => $now,
            'timestatus' => $now,
        ]);
    }

    /**
     * Gives up on a row for good, because the provider rejected it in a way that retrying cannot fix.
     *
     * @param int $id Id of the queue row.
     * @param string $error What went wrong, for the administrator report. Never contains the phone number.
     * @return void
     */
    public static function mark_failed(int $id, string $error): void {
        global $DB;

        $attempts = (int) $DB->get_field(self::TABLE, 'attempts', ['id' => $id], MUST_EXIST);

        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_FAILED,
            'error' => self::trim_error($error),
            'attempts' => $attempts + 1,
            'nextattempt' => 0,
            'timestatus' => time(),
        ]);
    }

    /**
     * Schedules another attempt after a transient failure, or gives up once the ceiling is reached.
     *
     * @param int $id Id of the queue row.
     * @param string $error What went wrong, for the administrator report. Never contains the phone number.
     * @return void
     */
    public static function mark_retry(int $id, string $error): void {
        global $DB;

        $attempts = (int) $DB->get_field(self::TABLE, 'attempts', ['id' => $id], MUST_EXIST);

        self::apply_retry($id, $attempts, $error, time());
    }

    /**
     * Marks a row as never attempted, which is what the daily cap and a missing opt-in produce.
     *
     * @param int $id Id of the queue row.
     * @param string $reason One of the SKIP_* constants.
     * @return void
     */
    public static function mark_skipped(int $id, string $reason): void {
        global $DB;

        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_SKIPPED,
            'error' => self::trim_error($reason),
            'nextattempt' => 0,
            'timestatus' => time(),
        ]);
    }

    /**
     * Writes a delivery status that the provider reported for a message this site already sent.
     *
     * The row is found by the message id the provider handed back when it accepted the message, because that is
     * the only thing of ours a delivery report carries. It does carry the destination number, and matching on
     * that is exactly what must not happen: it is personal data, several rows share it, and it would let anyone
     * who knows a number address a row of somebody else's history.
     *
     * Reports arrive repeated and out of order. Meta retries a webhook it did not get a 200 for, and the
     * `delivered` and the `read` of one message are two separate deliveries that can cross on the wire. So a
     * report is written only when it is strictly ahead of what the row already says, in the order fixed by
     * {@see self::PROVIDER_STATUS_ORDER}: a repeat changes nothing, and a `delivered` that lands after a `read`
     * leaves the `read` where it is instead of walking the row backwards. The alternative, writing whatever
     * arrived last, makes the administrator report disagree with what happened for no gain at all.
     *
     * @param string $providermsgid Message id as the provider returned it when it accepted the message.
     * @param string $status One of `sent`, `failed`, `delivered` or `read`. Anything else is ignored.
     * @param int $time Moment the provider reported the change at, or 0 for the current time.
     * @param string|null $pricingcategory Billing category the report carried, when it carried one.
     * @param string|null $error Diagnostic of a failure. Never contains the phone number or the message text.
     * @return bool True when at least one row was moved forward, false when there was nothing to move.
     */
    public static function update_by_providermsgid(
        string $providermsgid,
        string $status,
        int $time = 0,
        ?string $pricingcategory = null,
        ?string $error = null
    ): bool {
        global $DB;

        if ($providermsgid === '' || !isset(self::PROVIDER_STATUS_ORDER[$status])) {
            return false;
        }

        $now = $time > 0 ? $time : time();
        $rows = $DB->get_records(self::TABLE, ['providermsgid' => $providermsgid], 'id ASC', 'id, status');
        $applied = false;

        foreach ($rows as $row) {
            if (!self::supersedes($status, (string) $row->status)) {
                continue;
            }

            $update = (object) [
                'id' => $row->id,
                'status' => $status,
                'timestatus' => $now,
            ];

            if ($pricingcategory !== null && $pricingcategory !== '') {
                $update->pricingcategory = \core_text::substr($pricingcategory, 0, self::MAX_PRICING_CATEGORY_LENGTH);
            }

            if ($error !== null && $error !== '') {
                $update->error = self::trim_error($error);
            }

            $DB->update_record(self::TABLE, $update);
            $applied = true;
        }

        return $applied;
    }

    /**
     * Tells whether a delivery status just reported is ahead of the one a row already carries.
     *
     * Pure: both statuses are parameters and the order is a constant, so the rule can be argued about without a
     * row. Anything the providers do not report, and anything unknown, ranks below everything they do.
     *
     * @param string $status Status the provider just reported.
     * @param string $current Status the row carries now.
     * @return bool True when the reported status is strictly further along than the current one.
     */
    public static function supersedes(string $status, string $current): bool {
        return (self::PROVIDER_STATUS_ORDER[$status] ?? 0) > (self::PROVIDER_STATUS_ORDER[$current] ?? 0);
    }

    /**
     * Returns the token that names a queue row in the link of its template button.
     *
     * The link on a WhatsApp template button is public: it travels through Meta, it sits in a chat, and anyone who
     * has it can open it. So it may not be a bare row id, which would let anyone walk the queue of the site by
     * counting. The token is the id plus a signature of that id under `siteidentifier`, a per site secret that
     * never leaves the database, so a token cannot be made up and cannot be edited to point at another row.
     *
     * It lives here, and not in `go.php`, because the sending side needs it too: the button of the template is
     * built with this token as its URL suffix, and `go.php` is a script that cannot be loaded from a class.
     *
     * @param int $id Id of the queue row.
     * @return string Token, safe to put in a URL as it is.
     */
    public static function click_token(int $id): string {
        return $id . '.' . self::click_signature($id);
    }

    /**
     * Returns the queue row a click token names, or 0 when the token was not made by this site.
     *
     * The comparison is {@see hash_equals()} and not `===` on purpose: the caller is an unauthenticated endpoint,
     * and a comparison that returns as soon as two bytes differ tells whoever is guessing how much of the
     * signature they already have right.
     *
     * @param string $token Token as it arrived in the request. Hostile until this returns.
     * @return int Id of the queue row, or 0 when the token is malformed or the signature does not match.
     */
    public static function queueid_from_click_token(string $token): int {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || !preg_match('/^[1-9][0-9]{0,15}$/', $parts[0])) {
            return 0;
        }

        $id = (int) $parts[0];

        return hash_equals(self::click_signature($id), $parts[1]) ? $id : 0;
    }

    /**
     * Signs a queue row id with the secret of this site.
     *
     * @param int $id Id of the queue row.
     * @return string Signature, in hexadecimal.
     */
    protected static function click_signature(int $id): string {
        global $CFG;

        $mac = hash_hmac('sha256', self::TABLE . ':' . $id, (string) $CFG->siteidentifier);

        return substr($mac, 0, self::CLICK_TOKEN_SIGNATURE_LENGTH);
    }

    /**
     * Puts a row back in the queue with the backoff applied, or fails it when it has had all its attempts.
     *
     * @param int $id Id of the queue row.
     * @param int $attempts Attempts made before this one.
     * @param string $error What went wrong, for the administrator report.
     * @param int $now Current time.
     * @return void
     */
    protected static function apply_retry(int $id, int $attempts, string $error, int $now): void {
        global $DB;

        $attempts++;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $DB->update_record(self::TABLE, (object) [
                'id' => $id,
                'status' => self::STATUS_FAILED,
                'attempts' => $attempts,
                'error' => self::trim_error($error),
                'nextattempt' => 0,
                'timestatus' => $now,
            ]);

            return;
        }

        // A retry that would land inside the quiet hours is pushed to the end of them, never dropped.
        $next = self::defer_for_quiet_hours($now + self::backoff_seconds($attempts));

        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_PENDING,
            'attempts' => $attempts,
            'error' => self::trim_error($error),
            'nextattempt' => $next,
            'timestatus' => $now,
        ]);
    }

    /**
     * Puts rows abandoned in the `sending` state back on the queue.
     *
     * A row is marked `sending` and then the process dies: a deploy, an out of memory, a killed cron. Nothing else
     * will ever look at that row again, because it is not pending any more and it is not terminal either, so the
     * cleanup task will not remove it and the administrator report will show it as in flight forever.
     *
     * The decision is to reclaim it, and to count the lost run as an attempt. Counting it costs one of the five
     * attempts of a message that may never have been sent, which is the safe direction to be wrong in: a row whose
     * process dies every single time still reaches the ceiling and ends as `failed` instead of looping forever.
     *
     * What is being traded away is the chance of sending the message twice, when the process died after Meta had
     * already accepted it. The window of {@see self::STALE_SENDING_SECONDS} is far longer than the task period plus
     * any plausible timeout precisely so that this only happens to a process that really is gone, and in gateway
     * mode the duplicate is absorbed anyway, because the transport sends the queue row id as its idempotency key.
     *
     * @param int $now Current time.
     * @return void
     */
    protected static function requeue_stale(int $now): void {
        global $DB;

        $stale = $DB->get_records_select(
            self::TABLE,
            'status = :status AND timestatus < :cutoff',
            ['status' => self::STATUS_SENDING, 'cutoff' => $now - self::STALE_SENDING_SECONDS],
            'id ASC',
            'id, attempts'
        );

        foreach ($stale as $row) {
            self::apply_retry((int) $row->id, (int) $row->attempts, self::ERROR_ORPHANED, $now);
        }
    }

    /**
     * Returns the lock that serialises the claims, or null when another run already holds it.
     *
     * @return lock|null The held lock, or null when it could not be taken.
     */
    protected static function claim_lock(): ?lock {
        $factory = lock_config::get_lock_factory('message_whatsapp');
        $lock = $factory->get_lock('claim', 0);

        return $lock === false ? null : $lock;
    }

    /**
     * Returns the number of seconds to wait before the attempt numbered $attempts is made.
     *
     * Pure. The rule of the architecture is `min(2 ^ attempts, 60)` minutes: two minutes after the first failure,
     * then four, eight, sixteen, and the ceiling from there on. Doubling gives a provider that is having a bad
     * minute the time to recover without a queue of one minute retries hammering it, and the ceiling keeps a row
     * that is retried by a later release from being scheduled a month out.
     *
     * @param int $attempts Attempts made so far, one after the first failure. Values below one are read as one.
     * @return int Seconds to wait.
     */
    public static function backoff_seconds(int $attempts): int {
        $attempts = max(1, min($attempts, 30));

        return (int) min(2 ** $attempts, self::BACKOFF_MAX_MINUTES) * MINSECS;
    }

    /**
     * Tells whether a moment falls inside the quiet hours.
     *
     * Pure: the hours and the time zone are parameters, so the rule can be tested at any hour of any day without a
     * clock. The window runs from the start hour to the end hour, start included and end excluded, and it may wrap
     * around midnight, which is the normal case (22 to 8). A window whose two hours are equal is no window at all.
     *
     * @param int $time Moment to ask about, as a Unix timestamp.
     * @param int $starthour Hour the window starts at, 0 to 23.
     * @param int $endhour Hour the window ends at, 0 to 23.
     * @param string $timezone Time zone the hours are expressed in, for instance `America/Argentina/Buenos_Aires`.
     * @return bool True when nothing may be sent at that moment.
     */
    public static function in_quiet_hours(int $time, int $starthour, int $endhour, string $timezone): bool {
        if (!self::valid_hour($starthour) || !self::valid_hour($endhour) || $starthour === $endhour) {
            return false;
        }

        $hour = (int) self::local($time, $timezone)->format('G');

        if ($starthour < $endhour) {
            return $hour >= $starthour && $hour < $endhour;
        }

        return $hour >= $starthour || $hour < $endhour;
    }

    /**
     * Returns the first moment at or after $time at which sending is allowed again.
     *
     * Pure. A message caught by the quiet hours is deferred and never discarded, so this answers "when", not
     * "whether": a moment outside the window is returned untouched.
     *
     * @param int $time Moment to start from, as a Unix timestamp.
     * @param int $starthour Hour the window starts at, 0 to 23.
     * @param int $endhour Hour the window ends at, 0 to 23.
     * @param string $timezone Time zone the hours are expressed in.
     * @return int The same timestamp, or the one at which the current quiet window ends.
     */
    public static function quiet_hours_end(int $time, int $starthour, int $endhour, string $timezone): int {
        if (!self::in_quiet_hours($time, $starthour, $endhour, $timezone)) {
            return $time;
        }

        $end = self::local($time, $timezone)->setTime($endhour, 0, 0);

        if ($end->getTimestamp() <= $time) {
            // The window wrapped past midnight, so its end is on the following day.
            $end = $end->modify('+1 day')->setTime($endhour, 0, 0);
        }

        return $end->getTimestamp();
    }

    /**
     * Returns the bounds of the local day a moment belongs to.
     *
     * Pure. The daily cap counts calendar days as the site sees them, not rolling windows of 24 hours, because that
     * is what an administrator means by "five a day" and what a user experiences.
     *
     * @param int $time Moment inside the day, as a Unix timestamp.
     * @param string $timezone Time zone the day is measured in.
     * @return int[] Pair of [first second of the day, first second of the next day].
     */
    public static function day_bounds(int $time, string $timezone): array {
        $start = self::local($time, $timezone)->setTime(0, 0, 0);
        $end = $start->modify('+1 day')->setTime(0, 0, 0);

        return [$start->getTimestamp(), $end->getTimestamp()];
    }

    /**
     * Tells whether a user has already had as many messages today as the administrator allows.
     *
     * Pure. A cap of zero or less means no cap, which is the default: a limit that silently drops notifications is
     * a decision an administrator has to take on purpose, weighing the cost of a message against the cost of a
     * student not being told something.
     *
     * @param int $queuedtoday Messages already queued for that user today, skipped ones not counted.
     * @param int $cap Largest number of messages a user may be sent in a day, zero or less for no limit.
     * @return bool True when one more message would be over the cap.
     */
    public static function exceeds_daily_cap(int $queuedtoday, int $cap): bool {
        return $cap > 0 && $queuedtoday >= $cap;
    }

    /**
     * Returns the moment a message written now may first be attempted, given the quiet hours of this site.
     *
     * @param int $time Moment the message would otherwise be attempted at.
     * @return int The same moment, or the end of the quiet window it falls into.
     */
    public static function defer_for_quiet_hours(int $time): int {
        if (!self::quiet_hours_enabled()) {
            return $time;
        }

        return self::quiet_hours_end($time, self::quiet_start(), self::quiet_end(), self::site_timezone());
    }

    /**
     * Returns how many messages were queued for a user during the local day of a given moment.
     *
     * Rows that were skipped are not counted: they were never going to be sent, so counting them would let a burst
     * of messages to a user with no opt-in eat the allowance of the day they finally consent on.
     *
     * @param int $userid The id of the user.
     * @param int $time Moment inside the day to count.
     * @return int Number of rows.
     */
    protected static function count_queued_today(int $userid, int $time): int {
        global $DB;

        [$start, $end] = self::day_bounds($time, self::site_timezone());

        return $DB->count_records_select(
            self::TABLE,
            'userid = :userid AND status <> :skipped AND timecreated >= :start AND timecreated < :end',
            ['userid' => $userid, 'skipped' => self::STATUS_SKIPPED, 'start' => $start, 'end' => $end]
        );
    }

    /**
     * Returns the largest number of messages a single user may be sent in one day.
     *
     * @return int The cap, or 0 when there is none.
     */
    public static function daily_cap(): int {
        return max(0, (int) get_config('message_whatsapp', 'dailycap'));
    }

    /**
     * Returns whether this site defers messages during the quiet hours.
     *
     * @return bool True when the quiet hours apply.
     */
    protected static function quiet_hours_enabled(): bool {
        return (bool) get_config('message_whatsapp', 'quiethours');
    }

    /**
     * Returns the hour the quiet window starts at.
     *
     * @return int Hour of the day, 0 to 23.
     */
    protected static function quiet_start(): int {
        return self::configured_hour('quietstart', self::DEFAULT_QUIET_START);
    }

    /**
     * Returns the hour the quiet window ends at.
     *
     * @return int Hour of the day, 0 to 23.
     */
    protected static function quiet_end(): int {
        return self::configured_hour('quietend', self::DEFAULT_QUIET_END);
    }

    /**
     * Reads an hour of the day from the settings, falling back to the documented default.
     *
     * An unset setting is false, which is not the same as the `0` an administrator can choose for midnight, so the
     * two are told apart here instead of being collapsed by an empty check.
     *
     * @param string $name Name of the setting.
     * @param int $default Value to use when the setting has never been written or holds something unusable.
     * @return int Hour of the day, 0 to 23.
     */
    protected static function configured_hour(string $name, int $default): int {
        $value = get_config('message_whatsapp', $name);

        if ($value === false || $value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return self::valid_hour((int) $value) ? (int) $value : $default;
    }

    /**
     * Returns the time zone the site works in, which is the one the quiet hours are expressed in.
     *
     * @return string A time zone identifier.
     */
    protected static function site_timezone(): string {
        return \core_date::get_server_timezone();
    }

    /**
     * Turns a timestamp into a local date and time.
     *
     * @param int $time Unix timestamp.
     * @param string $timezone A valid time zone identifier, as {@see \core_date::get_server_timezone()} returns.
     * @return \DateTimeImmutable The same moment, seen from that time zone.
     */
    protected static function local(int $time, string $timezone): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone($timezone));
    }

    /**
     * Tells whether a number is an hour of the day.
     *
     * @param int $hour The number to check.
     * @return bool True when it is between 0 and 23.
     */
    protected static function valid_hour(int $hour): bool {
        return $hour >= 0 && $hour <= 23;
    }

    /**
     * Returns the id of the user a notification is addressed to.
     *
     * Core always resolves `userto` into a full user record before it reaches a processor, but another plugin can
     * change the event data through `pre_processor_message_send`, so both an object and a plain id are accepted.
     *
     * @param stdClass $eventdata Event data as core hands it to the processor.
     * @return int The user id, or 0 when the event data names no usable recipient.
     */
    protected static function recipient_userid(stdClass $eventdata): int {
        $userto = $eventdata->userto ?? null;

        if (is_object($userto)) {
            $userto = $userto->id ?? null;
        }

        return is_numeric($userto) ? max(0, (int) $userto) : 0;
    }

    /**
     * Reads a field of the event data as text that fits a CHAR column.
     *
     * @param stdClass $eventdata Event data as core hands it to the processor.
     * @param string $field Name of the field.
     * @param int $max Length of the column, in characters.
     * @return string The value, possibly empty, never longer than $max.
     */
    protected static function char_field(stdClass $eventdata, string $field, int $max): string {
        $value = $eventdata->$field ?? null;
        $value = is_scalar($value) ? (string) $value : '';

        return \core_text::substr($value, 0, $max);
    }

    /**
     * Reads a field of the event data as an integer.
     *
     * @param stdClass $eventdata Event data as core hands it to the processor.
     * @param string $field Name of the field.
     * @return int The value, or 0 when the field is missing or is not a number.
     */
    protected static function int_field(stdClass $eventdata, string $field): int {
        $value = $eventdata->$field ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Cuts an error message down to what the administrator report can show.
     *
     * @param string $error The error as the transport reported it.
     * @return string At most self::MAX_ERROR_LENGTH characters.
     */
    protected static function trim_error(string $error): string {
        return \core_text::substr($error, 0, self::MAX_ERROR_LENGTH);
    }
}
