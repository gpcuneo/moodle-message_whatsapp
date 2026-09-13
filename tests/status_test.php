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
 * Tests for the counters behind the status page of the WhatsApp channel.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\queue;
use message_whatsapp\local\status;

/**
 * Tests for the counters behind the status page of the WhatsApp channel.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\local\status
 */
final class status_test extends \advanced_testcase {
    /**
     * Every test starts from an empty queue and a site on a fixed time zone.
     *
     * The time zone is pinned because the counters are taken in the one of the site, and a test that ran at half
     * past nine at night would otherwise count yesterday or tomorrow depending on where the machine thinks it is.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        \core_date::set_default_server_timezone();
        set_config('timezone', 'America/Argentina/Buenos_Aires');
    }

    /**
     * Today counts what reached each status today, and nothing of what reached it yesterday.
     *
     * @return void
     */
    public function test_today_counts_what_the_channel_did_today(): void {
        $noon = $this->noon();

        $this->row(queue::STATUS_SENT, $noon);
        $this->row(queue::STATUS_SENT, $noon);
        $this->row(queue::STATUS_FAILED, $noon);
        $this->row(queue::STATUS_SENT, $noon - DAYSECS);

        $today = status::today($noon);

        $this->assertSame(2, $today[queue::STATUS_SENT]);
        $this->assertSame(1, $today[queue::STATUS_FAILED]);
        $this->assertSame(0, $today[queue::STATUS_SKIPPED]);
    }

    /**
     * Every outcome is in the answer, including the ones nothing reached, so a zero is shown and not missing.
     *
     * A status that disappears from the page when its count is zero is worse than useless: "sent: 0" is the whole
     * point of the screen, and a row that is simply not there reads as a screen that has not loaded.
     *
     * @return void
     */
    public function test_a_status_nothing_reached_is_reported_as_zero(): void {
        $today = status::today($this->noon());

        $this->assertSame(status::outcomes(), array_keys($today));
        $this->assertSame([0, 0, 0, 0, 0], array_values($today));
    }

    /**
     * What is waiting is not an outcome, and is not counted twice under a second name.
     *
     * @return void
     */
    public function test_today_does_not_count_what_is_still_waiting(): void {
        $noon = $this->noon();

        $this->row(queue::STATUS_PENDING, $noon);
        $this->row(queue::STATUS_SENDING, $noon);

        $today = status::today($noon);

        $this->assertArrayNotHasKey(queue::STATUS_PENDING, $today);
        $this->assertArrayNotHasKey(queue::STATUS_SENDING, $today);
        $this->assertSame([0, 0, 0, 0, 0], array_values($today));
    }

    /**
     * The backlog counts what is waiting whatever day it was queued on, and how long the oldest has waited.
     *
     * @return void
     */
    public function test_the_backlog_is_not_a_count_of_today(): void {
        $noon = $this->noon();

        $this->row(queue::STATUS_PENDING, $noon - (7 * DAYSECS));
        $this->row(queue::STATUS_PENDING, $noon - HOURSECS);
        $this->row(queue::STATUS_SENDING, $noon - MINSECS);
        $this->row(queue::STATUS_SENT, $noon);

        $backlog = status::backlog($noon);

        $this->assertSame(2, $backlog['pending']);
        $this->assertSame(1, $backlog['sending']);
        $this->assertSame(7 * DAYSECS, $backlog['oldest']);
    }

    /**
     * An empty queue has nothing waiting and no oldest entry, rather than an age of the epoch.
     *
     * @return void
     */
    public function test_an_empty_queue_has_no_oldest_entry(): void {
        $backlog = status::backlog($this->noon());

        $this->assertSame(0, $backlog['pending']);
        $this->assertSame(0, $backlog['oldest']);
    }

    /**
     * A task that has not run in a while is only a problem once something is waiting for it.
     *
     * @return void
     */
    public function test_a_quiet_task_is_not_a_problem_while_nothing_waits(): void {
        $this->assertFalse(status::sending_has_stalled());

        $this->row(queue::STATUS_SENT, time());
        $this->assertFalse(status::sending_has_stalled());
    }

    /**
     * Something waiting and a task that has not run is what the page has to say out loud.
     *
     * @return void
     */
    public function test_something_waiting_and_a_silent_task_is_a_stall(): void {
        $this->row(queue::STATUS_PENDING, time() - HOURSECS);

        $this->assertTrue(status::sending_has_stalled());

        $this->set_last_run(time() - 60);

        $this->assertFalse(status::sending_has_stalled());
    }

    /**
     * The last run of the sending task is read off the scheduled task of this plugin and not of another.
     *
     * @return void
     */
    public function test_the_last_run_is_the_one_of_the_sending_task(): void {
        $this->assertSame(0, status::last_send_run());

        $when = time() - 120;
        $this->set_last_run($when);

        $this->assertSame($when, status::last_send_run());
    }

    /**
     * Midday of today in the time zone of the site, which is a safe moment to count a day from.
     *
     * @return int Unix time.
     */
    private function noon(): int {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(\core_date::get_server_timezone()));

        return $now->setTime(12, 0, 0)->getTimestamp();
    }

    /**
     * Writes the last run time of the sending task.
     *
     * @param int $when Unix time of the run.
     * @return void
     */
    private function set_last_run(int $when): void {
        global $DB;

        $updated = $DB->set_field(
            'task_scheduled',
            'lastruntime',
            $when,
            ['classname' => status::TASK_SEND]
        );

        // The row is written by the plugin installer, so a test that silently updated nothing would pass for the
        // wrong reason the day the classname or the table changes.
        $this->assertTrue($updated);
    }

    /**
     * Writes one queue row that reached a status at a moment.
     *
     * @param string $status The status the row is in.
     * @param int $when When it reached it, which is also when it was queued.
     * @return int Id of the row.
     */
    private function row(string $status, int $when): int {
        global $DB;

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
}
