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
 * Tests for the task that deletes the old entries of the WhatsApp queue.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\queue;
use message_whatsapp\task\cleanup;

/**
 * Tests for the task that deletes the old entries of the WhatsApp queue.
 *
 * What is pinned here is what the task must never delete. Deleting too little costs disk; deleting a row that
 * was still waiting to be sent cancels a notification, and nothing anywhere would say that it happened.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\task\cleanup
 */
final class cleanup_task_test extends \advanced_testcase {
    /** @var int Retention used by most tests, in days. */
    private const DAYS = 30;

    /**
     * Every test starts from an empty queue and a thirty day retention.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('retention', self::DAYS, 'message_whatsapp');
    }

    /**
     * A finished entry older than the retention goes, and its clicks go with it.
     *
     * @return void
     */
    public function test_an_old_finished_entry_and_its_clicks_are_deleted(): void {
        global $DB;

        $old = $this->row(queue::STATUS_SENT, self::DAYS + 1);
        $this->click($old);
        $this->click($old);

        $this->run_task();

        $this->assertFalse($DB->record_exists(queue::TABLE, ['id' => $old]));
        $this->assertSame(0, $DB->count_records(cleanup::TABLE_CLICK, ['queueid' => $old]));
    }

    /**
     * An entry that has not waited out the retention stays, whatever became of it.
     *
     * @return void
     */
    public function test_a_recent_finished_entry_is_kept(): void {
        global $DB;

        $recent = $this->row(queue::STATUS_FAILED, self::DAYS - 1);

        $this->run_task();

        $this->assertTrue($DB->record_exists(queue::TABLE, ['id' => $recent]));
    }

    /**
     * An entry still waiting to be sent is never deleted, however old it is.
     *
     * This is the one that matters. A `pending` row is a notification the site owes somebody, and an old one is
     * more likely to be a queue that has been stuck for a month than a row nobody wants any more.
     *
     * @return void
     */
    public function test_an_unfinished_entry_is_never_deleted(): void {
        global $DB;

        $pending = $this->row(queue::STATUS_PENDING, self::DAYS * 10);
        $sending = $this->row(queue::STATUS_SENDING, self::DAYS * 10);

        $this->run_task();

        $this->assertTrue($DB->record_exists(queue::TABLE, ['id' => $pending]));
        $this->assertTrue($DB->record_exists(queue::TABLE, ['id' => $sending]));
    }

    /**
     * Every terminal status is deleted, and none of them is forgotten.
     *
     * @return void
     */
    public function test_every_finished_status_is_deleted(): void {
        global $DB;

        foreach (cleanup::terminal_statuses() as $status) {
            $this->row($status, self::DAYS + 1);
        }

        $this->assertSame(5, $DB->count_records(queue::TABLE));

        $this->run_task();

        $this->assertSame(0, $DB->count_records(queue::TABLE));
    }

    /**
     * A retention of zero keeps everything, instead of reading as "delete it all on the next run".
     *
     * @return void
     */
    public function test_a_retention_of_zero_deletes_nothing(): void {
        global $DB;

        set_config('retention', 0, 'message_whatsapp');
        $this->row(queue::STATUS_SENT, 3650);

        $this->run_task();

        $this->assertSame(1, $DB->count_records(queue::TABLE));
    }

    /**
     * The age is counted from when the entry finished, not from when it was queued.
     *
     * A message queued four months ago and delivered yesterday is a recent entry: it is the delivery that the
     * report and the person reading it care about.
     *
     * @return void
     */
    public function test_the_age_is_counted_from_the_moment_the_entry_finished(): void {
        global $DB;

        $id = $this->row(queue::STATUS_DELIVERED, 1);
        $DB->set_field(queue::TABLE, 'timecreated', time() - (120 * DAYSECS), ['id' => $id]);

        $this->run_task();

        $this->assertTrue($DB->record_exists(queue::TABLE, ['id' => $id]));
    }

    /**
     * A click of an entry that stays is not deleted along with the ones of the entries that go.
     *
     * @return void
     */
    public function test_the_clicks_of_an_entry_that_stays_are_kept(): void {
        global $DB;

        $old = $this->row(queue::STATUS_READ, self::DAYS + 1);
        $recent = $this->row(queue::STATUS_READ, 1);
        $this->click($old);
        $this->click($recent);

        $this->run_task();

        $this->assertSame(0, $DB->count_records(cleanup::TABLE_CLICK, ['queueid' => $old]));
        $this->assertSame(1, $DB->count_records(cleanup::TABLE_CLICK, ['queueid' => $recent]));
    }

    /**
     * More rows than fit in one batch are deleted, so the batching is not a limit of its own.
     *
     * @return void
     */
    public function test_more_rows_than_one_batch_are_deleted(): void {
        global $DB;

        for ($i = 0; $i < 12; $i++) {
            $this->row(queue::STATUS_SKIPPED, self::DAYS + 1);
        }

        // Two rows per statement, so that a dozen of them take several passes through the loop.
        $task = new class extends cleanup {
            /** @var int Rows per statement, lowered so that the batching is actually exercised. */
            protected const BATCH = 2;
        };

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertSame(0, $DB->count_records(queue::TABLE));
    }

    /**
     * Runs the task with its output swallowed.
     *
     * @return string What the task traced.
     */
    private function run_task(): string {
        $task = new cleanup();

        ob_start();
        $task->execute();

        return (string) ob_get_clean();
    }

    /**
     * Writes one queue row that reached a status a number of days ago.
     *
     * @param string $status The status the row is in.
     * @param int $daysago How long ago it reached it.
     * @return int Id of the row.
     */
    private function row(string $status, int $daysago): int {
        global $DB;

        $when = time() - ($daysago * DAYSECS);
        $user = $this->getDataGenerator()->create_user();

        return (int) $DB->insert_record(queue::TABLE, (object) [
            'userid' => $user->id,
            'savedmessageid' => null,
            'component' => 'mod_assign',
            'name' => 'assign_notification',
            'courseid' => 0,
            'phone' => '+5491112345678',
            'templatekey' => 'moodle_notification',
            'lang' => 'es_AR',
            'params' => json_encode(['Demo', 'Asunto', 'Cuerpo']),
            'url' => '',
            'status' => $status,
            'attempts' => 1,
            'nextattempt' => 0,
            'providermsgid' => null,
            'error' => null,
            'pricingcategory' => null,
            'timecreated' => $when,
            'timesent' => $status === queue::STATUS_SENT ? $when : 0,
            'timestatus' => $when,
        ]);
    }

    /**
     * Writes one click of a queue row.
     *
     * @param int $queueid The row the click belongs to.
     * @return void
     */
    private function click(int $queueid): void {
        global $DB;

        $DB->insert_record(cleanup::TABLE_CLICK, (object) [
            'queueid' => $queueid,
            'timeclicked' => time(),
            'useragent' => 'Mozilla/5.0',
        ]);
    }
}
