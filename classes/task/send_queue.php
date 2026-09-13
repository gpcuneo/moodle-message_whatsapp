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
 * Scheduled task that sends the pending rows of the WhatsApp queue.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\task;

use core\task\scheduled_task;
use message_whatsapp\local\queue;
use message_whatsapp\transport\factory;
use message_whatsapp\transport\result;
use message_whatsapp\transport\transport_interface;
use message_whatsapp\transport\unconfigured;
use stdClass;

/**
 * Sends the pending rows of the queue through the configured transport.
 *
 * This is the only place of the plugin that reaches the network: `send_message()` never does, because core calls
 * it inside the web request of the user that triggered the event. Everything that task does is glue between two
 * pieces that already exist and are not reimplemented here: {@see queue}, which decides which rows are due and
 * what happens to a row after an attempt, and {@see transport_interface}, which knows how to put a template on
 * the wire. The task owns three things only: how many rows to take at a time, what to hand the transport, and how
 * to survive a row that misbehaves.
 *
 * **Quiet hours and the daily cap are not checked here, on purpose.** T1.5 decided that both are applied when a
 * row is *written* -- at enqueue and at every retry -- and not when it is claimed: `enqueue()` marks a row over
 * the cap as `skipped` there and then, and both `enqueue()` and the retry path date `nextattempt` past the end of
 * the quiet hours. {@see queue::claim()} only ever hands out rows whose `nextattempt` is already due, so a second
 * check here would be redundant at best. At worst it would be a second copy of the same policy, free to drift
 * from the first, in the one place where a disagreement between the two means a notification is silently held or
 * silently sent at three in the morning.
 *
 * **One bad row cannot take the batch with it.** The interface promises never to throw, but a promise is not a
 * guarantee: a transport is HTTP client code, and a library deep under it can raise anything. Every row is
 * processed inside its own try/catch, so an exception costs that row an attempt and nothing else. Without it, the
 * remaining rows of the batch would stay `sending` until the stale reclaim of the queue picked them up fifteen
 * minutes later, having each burned an attempt they never got to use.
 *
 * **The log names rows and codes, never people.** `mtrace()` writes into the cron log of the site, which is read
 * by whoever operates the server, is copied into tickets and is kept for as long as nobody rotates it. A phone
 * number is personal data under Ley 25.326 and the subject of a notification is the content of a private message;
 * neither has any business outliving its purpose in a text file. So the log carries the queue row id, the
 * transport name and the stable failure code, and the free text diagnostic of the provider goes where it belongs:
 * the `error` column of the queue, which the administrator report shows and the privacy provider exports and
 * deletes.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_queue extends scheduled_task {
    /**
     * Rows claimed in one run when the site has not said otherwise.
     *
     * The architecture asks for batches of a hundred a minute with a setting to raise it. The setting belongs to
     * T2.5 and does not exist yet; {@see self::batch_size()} already reads it, so adding it is a change to
     * `settings.php` alone. A hundred a minute is six thousand messages an hour, which is more than the
     * institutions this plugin is written for send in a day, and it is small enough that a run of the task fits
     * comfortably inside a cron minute even when the provider is answering slowly.
     */
    public const DEFAULT_BATCH_SIZE = 100;

    /** Error recorded when the stored parameters of a row cannot be read back. */
    public const ERROR_BAD_PARAMS = 'badparams';

    /** Error recorded when a row reached the sending task without a destination. */
    public const ERROR_NO_PHONE = 'nophone';

    /** Error recorded when the transport raised instead of answering, which its contract says it never does. */
    public const ERROR_EXCEPTION = 'exception';

    /** Outcome of a row the provider accepted. */
    protected const OUTCOME_SENT = 'sent';

    /** Outcome of a row handed back to the queue for another attempt. */
    protected const OUTCOME_DEFERRED = 'deferred';

    /** Outcome of a row written off for good. */
    protected const OUTCOME_FAILED = 'failed';

    /** Outcome of a row whose result could not be written down. It stays claimed and the queue reclaims it later. */
    protected const OUTCOME_UNRECORDED = 'unrecorded';

    /**
     * Returns the name of the task shown in the scheduled tasks administration page.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:sendqueue', 'message_whatsapp');
    }

    /**
     * Runs the task: claims what is due and sends it.
     *
     * @return void
     */
    public function execute(): void {
        $transport = $this->transport();

        if ($transport instanceof unconfigured) {
            $this->report_no_transport($transport);
            return;
        }

        $rows = queue::claim($this->batch_size());

        if (!$rows) {
            mtrace('message_whatsapp: no queue rows are due.');
            return;
        }

        mtrace('message_whatsapp: claimed ' . count($rows) . ' row(s), sending them through ' . $transport->name() . '.');

        $counts = [
            self::OUTCOME_SENT => 0,
            self::OUTCOME_DEFERRED => 0,
            self::OUTCOME_FAILED => 0,
            self::OUTCOME_UNRECORDED => 0,
        ];

        foreach ($rows as $row) {
            $counts[$this->process($transport, $row)]++;
        }

        mtrace(
            'message_whatsapp: ' . $counts[self::OUTCOME_SENT] . ' sent, '
                . $counts[self::OUTCOME_DEFERRED] . ' back in the queue, '
                . $counts[self::OUTCOME_FAILED] . ' failed, '
                . $counts[self::OUTCOME_UNRECORDED] . ' left claimed.'
        );
    }

    /**
     * Says why nothing was claimed, when the site has no transport to claim anything for.
     *
     * **Nothing is claimed and nothing is failed.** The alternative -- claim the batch and write every row off,
     * since {@see unconfigured} answers a permanent failure -- looks consistent and is wrong in three ways. It
     * turns a site wide configuration problem into a hundred individual permanent failures a minute, so an
     * administrator who takes a morning to finish setting the plugin up comes back to a queue of notifications
     * that will never be sent and can only be revived one retry at a time from the report of T3.1. It spends the
     * five attempts of each row on a condition that no number of attempts can change: a missing mode is fixed by
     * a person opening the settings page, not by a backoff. And it loses the only rows that still could be sent,
     * because the mode is the one thing about them that is certain to be fixable.
     *
     * A row left `pending` costs nothing while it waits. `nextattempt` is untouched, no attempt is counted, and
     * the run of the task in the minute after the mode is saved sends it.
     *
     * The reason is asked of the transport with `check()`, which for this one makes no network call of any kind:
     * it is a null object that answers the refusal it was built with. It is not called on a real transport.
     *
     * @param unconfigured $transport The refusing transport the factory handed back.
     * @return void
     */
    protected function report_no_transport(unconfigured $transport): void {
        $refusal = $transport->check();

        mtrace(
            'message_whatsapp: nothing claimed, this site has no usable transport (' . $refusal->code . '). '
                . $refusal->message . ' The rows stay pending and go out once the plugin is configured.'
        );
    }

    /**
     * Sends one claimed row and writes down what happened to it.
     *
     * The whole body is inside one try/catch so that a row that misbehaves, in the transport or in the write that
     * follows it, costs one attempt and never the rest of the batch.
     *
     * @param transport_interface $transport Transport to send through.
     * @param stdClass $row Claimed queue row, already marked `sending`.
     * @return string One of the OUTCOME_* constants.
     */
    protected function process(transport_interface $transport, stdClass $row): string {
        $id = (int) $row->id;

        try {
            $params = $this->params_of($row);

            if ($params === null) {
                queue::mark_failed($id, self::ERROR_BAD_PARAMS);
                mtrace('  queue ' . $id . ': failed, its stored parameters could not be read back.');

                return self::OUTCOME_FAILED;
            }

            $phone = trim((string) $row->phone);

            if ($phone === '') {
                queue::mark_failed($id, self::ERROR_NO_PHONE);
                mtrace('  queue ' . $id . ': failed, it has no destination.');

                return self::OUTCOME_FAILED;
            }

            $result = $transport->send_template(
                $phone,
                (string) $row->templatekey,
                (string) $row->lang,
                $params,
                $this->url_suffix($row),
                (string) $id
            );

            return $this->record($id, $result);
        } catch (\Throwable $e) {
            return $this->recover($id, $e);
        }
    }

    /**
     * Turns the answer of the transport into the new state of the row.
     *
     * {@see result::is_retryable()} is the only question asked of the result. The difference between "try again"
     * and "give up" is two booleans that must be read together, and combining them at each call site is how they
     * eventually get combined differently in two places; the result answers it once and this is the one caller.
     *
     * Nothing is passed as the pricing category: Meta does not bill on the send call, so a `result` carries none.
     * The category arrives later with the delivery status, by webhook in direct mode and by the status cursor in
     * gateway mode, and it is written by whoever handles that.
     *
     * @param int $id Id of the queue row.
     * @param result $result What the transport answered.
     * @return string One of the OUTCOME_* constants.
     */
    protected function record(int $id, result $result): string {
        if ($result->ok) {
            queue::mark_sent($id, $result->providermsgid);
            mtrace('  queue ' . $id . ': accepted by the provider.');

            return self::OUTCOME_SENT;
        }

        if ($result->is_retryable()) {
            queue::mark_retry($id, $this->error_of($result));
            mtrace('  queue ' . $id . ': not sent this time (' . $result->code . '), back in the queue.');

            return self::OUTCOME_DEFERRED;
        }

        queue::mark_failed($id, $this->error_of($result));
        mtrace('  queue ' . $id . ': failed for good (' . $result->code . ').');

        return self::OUTCOME_FAILED;
    }

    /**
     * Deals with a row whose attempt raised instead of answering.
     *
     * The row is handed back to the queue rather than written off. A transport that throws is a transport that is
     * behaving in a way nobody described, so nothing is known about whether trying again would work; putting the
     * row back risks a few wasted attempts, and writing it off risks throwing away a notification because of a
     * bug somewhere below the HTTP client. The ceiling of five attempts bounds the first risk and nothing bounds
     * the second.
     *
     * The exception text goes into the `error` column and not into the log. The column is shown to the
     * administrator next to the row it belongs to, is covered by the privacy provider and is deleted with the
     * row; the cron log is none of those things, and nothing promises that an exception raised by an HTTP library
     * does not quote the request it was building. For the same reason only `getMessage()` is kept: the
     * `debuginfo` of a Moodle database exception carries the SQL with its parameters, and one of those parameters
     * is a phone number.
     *
     * If even the write fails -- the database went away, which is the likeliest way to get here twice -- the row
     * is left as it is. It stays `sending` and {@see queue::claim()} reclaims it once it is stale, counting the
     * lost run as an attempt, which is exactly the case that mechanism exists for.
     *
     * @param int $id Id of the queue row.
     * @param \Throwable $e What was raised.
     * @return string One of the OUTCOME_* constants.
     */
    protected function recover(int $id, \Throwable $e): string {
        mtrace('  queue ' . $id . ': the attempt raised ' . get_class($e) . '; see the error column of the row.');

        try {
            queue::mark_retry($id, self::ERROR_EXCEPTION . ': ' . get_class($e) . ': ' . $e->getMessage());

            return self::OUTCOME_DEFERRED;
        } catch (\Throwable $ignored) {
            mtrace('  queue ' . $id . ': its outcome could not be written either, it stays claimed until it goes stale.');

            return self::OUTCOME_UNRECORDED;
        }
    }

    /**
     * Returns the transport this run sends through.
     *
     * A method and not a direct call to the factory so that it is a seam: a test drives the task with a double
     * without a fake transport having to exist in production code, in the same way
     * {@see factory::registry()} is the seam of the factory. The factory is asked once per run and not once per
     * row, because the mode of a site does not change inside a cron minute.
     *
     * @return transport_interface Always a transport. The factory never returns null and never throws.
     */
    protected function transport(): transport_interface {
        return factory::instance();
    }

    /**
     * Returns how many rows to claim in this run.
     *
     * @return int Rows to claim, never less than one.
     */
    protected function batch_size(): int {
        $configured = (int) get_config('message_whatsapp', 'batchsize');

        return $configured > 0 ? $configured : self::DEFAULT_BATCH_SIZE;
    }

    /**
     * Returns the dynamic suffix of the URL button for a row, or null when there is none to give.
     *
     * A template button has a **fixed base** that is part of what Meta approved, and the only thing a message can
     * change is the suffix appended to it. So the destination of a notification cannot travel in the button: what
     * travels is a short token, and the site resolves it back into the destination. That is what the `url` column
     * of the queue and `message_whatsapp_click` are for, and it is why the architecture gives the plugin a
     * `go.php` whose job is to take the token, record the click and redirect to the stored URL.
     *
     * What is sent is the **queue row id**. It is the only identifier of a notification that is stable across
     * retries, it is already what the idempotency key is spelled with, it is a handful of characters inside any
     * suffix limit, and it says nothing about the recipient to anyone who sees the link.
     *
     * The id travels signed, never bare. The spelling of the token belongs to the side that verifies it, which is
     * `go.php`, so it is not written again here: {@see \message_whatsapp\local\queue::click_token()} produces it
     * and {@see \message_whatsapp\local\queue::queueid_from_click_token()} reads it back. A signing scheme
     * written twice by two hands is a scheme that ships broken, so there is one copy and both sides call it.
     *
     * The fixed base of the button, which lives in the approved template and not here, is
     * `{wwwroot}/message/output/whatsapp/go.php?t=`.
     *
     * @param stdClass $row Claimed queue row.
     * @return string|null Suffix to append to the fixed base of the button.
     */
    protected function url_suffix(stdClass $row): ?string {
        return queue::click_token((int) $row->id);
    }

    /**
     * Reads the template parameters back out of the row.
     *
     * They were written by {@see \message_whatsapp\local\mapped::params_json()} as a JSON list of sanitised
     * strings, which is the form the transport takes them in. Anything that does not come back as a list is a row
     * that cannot be sent: guessing at it would mean handing Meta parameters that do not match the template it
     * approved, and the answer to that is a rejection, minutes later and harder to read than this one.
     *
     * @param stdClass $row Claimed queue row.
     * @return string[]|null The parameters in template order, or null when the column could not be read.
     */
    protected function params_of(stdClass $row): ?array {
        $decoded = json_decode((string) ($row->params ?? ''), true);

        if (!is_array($decoded)) {
            return null;
        }

        return array_map(fn($param) => (string) $param, array_values($decoded));
    }

    /**
     * Builds what goes into the `error` column of a row from the answer of the transport.
     *
     * Both halves are kept. The code is the stable identifier the report branches on and the only half that can
     * be translated; the message is the raw diagnostic of the provider, in whatever language it answered in, and
     * it is what an administrator actually needs when the code is not enough. The queue trims the result to the
     * length of the column.
     *
     * @param result $result Failed result of an attempt.
     * @return string Error to record.
     */
    protected function error_of(result $result): string {
        return $result->message === '' ? $result->code : $result->code . ': ' . $result->message;
    }
}
