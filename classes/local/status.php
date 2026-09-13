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
 * Counters that answer whether the WhatsApp channel is working right now.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\local;

/**
 * Counters that answer whether the WhatsApp channel is working right now.
 *
 * The delivery report of T3.1 answers "what happened to this message". This answers the other question, the one
 * somebody asks when a person says they got nothing: **is the channel moving at all today**. They are different
 * questions and they read differently: the report is a list to search through, this is a handful of numbers that
 * are either reassuring at a glance or not.
 *
 * Every count is taken in the time zone of the site, the same one the daily cap is counted in, so that "today"
 * means the same thing on both screens.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status {
    /** Class name of the sending task, whose last run is the one worth showing. */
    public const TASK_SEND = '\message_whatsapp\task\send_queue';

    /**
     * How long the sending task may go without running before the page says something is wrong, in seconds.
     *
     * The task is scheduled every minute, so ten of them is not a hiccup: it means cron is not running, or is
     * running and never reaching this task. Either way nothing is being sent and nobody would otherwise notice,
     * because a queue that is not drained looks exactly like a queue with nothing in it.
     */
    public const STALE_AFTER_SECONDS = 600;

    /**
     * Returns how many entries reached each status today.
     *
     * Counted by `timestatus` and not by `timecreated`: the question is what the channel did today, and a message
     * queued last night and sent this morning was sent today.
     *
     * Only the states a message ends in are counted. `pending` and `sending` are not outcomes, they are the
     * backlog, and {@see self::backlog()} already answers for them without a day attached; counting them here as
     * well would put the same row on the screen twice under two labels that mean different things.
     *
     * @param int|null $now Moment to take "today" from, for the tests. Defaults to now.
     * @return array<string, int> One entry per outcome, in the order a message reaches them.
     */
    public static function today(?int $now = null): array {
        global $DB;

        $now ??= time();
        [$start, $end] = queue::day_bounds($now, self::timezone());

        $counts = $DB->get_records_sql(
            'SELECT status, COUNT(1) AS total
               FROM {' . queue::TABLE . '}
              WHERE timestatus >= :start AND timestatus < :end
           GROUP BY status',
            ['start' => $start, 'end' => $end]
        );

        $totals = [];

        foreach (self::outcomes() as $status) {
            $totals[$status] = isset($counts[$status]) ? (int) $counts[$status]->total : 0;
        }

        return $totals;
    }

    /**
     * Returns what is still waiting to go out, whatever day it was queued on.
     *
     * The backlog is not a count of today: a row queued last week and never sent is exactly the thing this page
     * exists to show, and counting it only on the day it was written would hide it.
     *
     * @param int|null $now Moment to measure the age of the oldest entry from, for the tests. Defaults to now.
     * @return array{pending: int, sending: int, oldest: int} Counts, and the age of the oldest waiting entry in
     *     seconds, zero when there is nothing waiting.
     */
    public static function backlog(?int $now = null): array {
        global $DB;

        $now ??= time();
        $waiting = [queue::STATUS_PENDING, queue::STATUS_SENDING];
        [$insql, $params] = $DB->get_in_or_equal($waiting, SQL_PARAMS_NAMED, 'st');

        $oldest = (int) $DB->get_field_sql(
            'SELECT MIN(timecreated) FROM {' . queue::TABLE . '} WHERE status ' . $insql,
            $params
        );

        return [
            'pending' => $DB->count_records(queue::TABLE, ['status' => queue::STATUS_PENDING]),
            'sending' => $DB->count_records(queue::TABLE, ['status' => queue::STATUS_SENDING]),
            'oldest' => $oldest > 0 ? max(0, $now - $oldest) : 0,
        ];
    }

    /**
     * Returns when the sending task last finished a run.
     *
     * @return int Unix time of the last run, zero when it has never run.
     */
    public static function last_send_run(): int {
        $task = \core\task\manager::get_scheduled_task(self::TASK_SEND);

        // Asked of the task manager and not of `task_scheduled` directly: the classname is stored with a leading
        // backslash and the column is not a contract, while this is the API core uses to answer the same question
        // on its own scheduled tasks page.
        return $task === false ? 0 : (int) $task->get_last_run_time();
    }

    /**
     * Tells whether the sending task has gone quiet for long enough to be worth saying so.
     *
     * A task that has never run on a site where nothing has ever been queued is not a problem, so the answer is
     * only true once there is something waiting for it to do.
     *
     * @param int|null $now Moment to measure from, for the tests. Defaults to now.
     * @return bool True when something is waiting and the task has not run recently.
     */
    public static function sending_has_stalled(?int $now = null): bool {
        $now ??= time();
        $backlog = self::backlog($now);

        if ($backlog['pending'] + $backlog['sending'] === 0) {
            return false;
        }

        return ($now - self::last_send_run()) > self::STALE_AFTER_SECONDS;
    }

    /**
     * The states a message ends in, in the order it reaches them.
     *
     * @return string[] The outcomes.
     */
    public static function outcomes(): array {
        return [
            queue::STATUS_SENT,
            queue::STATUS_DELIVERED,
            queue::STATUS_READ,
            queue::STATUS_FAILED,
            queue::STATUS_SKIPPED,
        ];
    }

    /**
     * The time zone counts are taken in, which is the one of the site.
     *
     * @return string A time zone usable by \DateTimeZone.
     */
    private static function timezone(): string {
        return \core_date::get_server_timezone();
    }
}
