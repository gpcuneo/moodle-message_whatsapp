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
 * Scheduled task that deletes the old rows of the WhatsApp queue.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\task;

use core\task\scheduled_task;
use message_whatsapp\local\queue;

/**
 * Deletes the queue rows that already reached a terminal status and are older than the retention period.
 *
 * The retention period is a plugin setting, ninety days by default. Three decisions shape what this deletes:
 *
 * 1. **Only finished rows.** A row still `pending` or `sending` is work the site has not done yet, and deleting
 *    it would cancel a notification without telling anybody. Age is measured from `timestatus`, the moment the
 *    row reached the state it is in, because that is when it stopped being of any use.
 * 2. **The clicks go first.** `message_whatsapp_click` hangs off the queue by `queueid` and has no user of its
 *    own, so deleting a queue row before its clicks would leave rows that point at nothing and that the privacy
 *    provider can no longer reach through the user they belong to.
 * 3. **A run is bounded.** Deletions happen in batches and a run stops after {@see self::MAX_ROWS_PER_RUN}. A
 *    site that has never run this and holds a million old rows gets them in several nights instead of one
 *    statement that holds the table long enough to be noticed.
 *
 * A retention of zero or less means "keep everything": it is the same reading the daily cap gives the same
 * number, and the destructive reading -- delete every finished row on the next run -- is not something a
 * typo in a settings field should be able to ask for.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends scheduled_task {
    /** Table holding the clicks, deleted along with the queue rows they hang off. */
    public const TABLE_CLICK = 'message_whatsapp_click';

    /** Retention used when the setting is missing, in days. Matches the default declared in `settings.php`. */
    public const DEFAULT_RETENTION_DAYS = 90;

    /**
     * Rows deleted per statement. Small enough not to hold the table, large enough not to be all round trips.
     *
     * Read through `static::` so that a test can lower it and exercise the loop without writing five hundred rows.
     */
    protected const BATCH = 500;

    /** Most rows one run will delete, so that a first run on an old site stays a night and not a morning. */
    public const MAX_ROWS_PER_RUN = 20000;
    /**
     * Returns the name of the task shown in the scheduled tasks administration page.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:cleanup', 'message_whatsapp');
    }

    /**
     * Deletes the finished rows that are older than the retention period, and their clicks.
     *
     * @return void
     */
    public function execute(): void {
        $days = self::retention_days();

        if ($days <= 0) {
            mtrace('message_whatsapp: retention is off, nothing is deleted.');

            return;
        }

        $before = time() - ($days * DAYSECS);
        $deleted = $this->delete_finished_before($before);

        mtrace('message_whatsapp: ' . $deleted . ' finished queue entries older than ' . $days
            . ' days deleted, with their clicks.');

        if ($deleted >= self::MAX_ROWS_PER_RUN) {
            mtrace('message_whatsapp: the run stopped at its limit of ' . self::MAX_ROWS_PER_RUN
                . '; the rest goes in the next one.');
        }
    }

    /**
     * Deletes finished rows older than a moment, in batches, and returns how many went.
     *
     * @param int $before Unix time; rows whose `timestatus` is older than this are deleted.
     * @return int Number of queue rows deleted.
     */
    protected function delete_finished_before(int $before): int {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal(self::terminal_statuses(), SQL_PARAMS_NAMED, 'st');
        $select = 'status ' . $insql . ' AND timestatus < :before';
        $params = $inparams + ['before' => $before];
        $deleted = 0;

        while ($deleted < self::MAX_ROWS_PER_RUN) {
            $ids = $DB->get_fieldset_select(queue::TABLE, 'id', $select, $params, '', 0, static::BATCH);

            if ($ids === []) {
                break;
            }

            // The clicks first: they point at the queue row, and nothing points at them.
            $DB->delete_records_list(self::TABLE_CLICK, 'queueid', $ids);
            $DB->delete_records_list(queue::TABLE, 'id', $ids);

            $deleted += count($ids);
        }

        return $deleted;
    }

    /**
     * The states a row never leaves again, which are the only ones worth deleting.
     *
     * @return string[] The terminal statuses.
     */
    public static function terminal_statuses(): array {
        return [
            queue::STATUS_SENT,
            queue::STATUS_DELIVERED,
            queue::STATUS_READ,
            queue::STATUS_FAILED,
            queue::STATUS_SKIPPED,
        ];
    }

    /**
     * Days a finished row is kept, as the site has it configured.
     *
     * @return int The retention in days; zero or less means nothing is ever deleted.
     */
    public static function retention_days(): int {
        $value = get_config('message_whatsapp', 'retention');

        if ($value === false || $value === null || $value === '') {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return (int) $value;
    }
}
