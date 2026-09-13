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
 * Tests for the outgoing queue of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\queue;
use message_whatsapp\local\recipient;
use message_whatsapp\local\template_mapper;

/**
 * Tests for the outgoing queue of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\local\queue
 */
final class queue_test extends \advanced_testcase {
    /** @var string Core message provider used to drive a real message_send() through the processor. */
    private const PROVIDER = 'coursecompleted';

    /**
     * Every test starts from a configured site with no quiet hours and no daily cap.
     *
     * Both of those are turned off here and switched on by the tests that are about them, so that the rest of the
     * suite is not at the mercy of the hour it happens to run at.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('mode', \message_output_whatsapp::MODE_DIRECT, 'message_whatsapp');
        set_config('sitename_short', 'Demo', 'message_whatsapp');
        set_config('phonesource', 'phone2', 'message_whatsapp');
        set_config('defaultcountry', 'AR', 'message_whatsapp');
        set_config('quiethours', 0, 'message_whatsapp');
        set_config('dailycap', 0, 'message_whatsapp');
    }

    /**
     * A real message_send() through the whole of core leaves exactly one pending row with the mapped template.
     *
     * The rollback reset has to be turned off for this one: core buffers the call to the processors while a
     * database transaction is open and only flushes the buffer on commit, so inside the transaction of a normal
     * test the processor would never be reached at all.
     *
     * @return void
     */
    public function test_message_send_leaves_a_pending_row(): void {
        global $DB;

        $this->preventResetByRollback();
        $this->enable_processor();

        $user = $this->opted_in_user();
        set_user_preference('message_provider_moodle_' . self::PROVIDER . '_enabled', 'whatsapp', $user);

        $message = new \core\message\message();
        $message->courseid = SITEID;
        $message->component = 'moodle';
        $message->name = self::PROVIDER;
        $message->userfrom = get_admin();
        $message->userto = $user;
        $message->subject = 'Curso terminado';
        $message->fullmessage = 'Completaste el curso.';
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<p>Completaste el curso.</p>';
        $message->smallmessage = 'Completaste el curso.';
        $message->notification = 1;
        $message->contexturl = 'https://example.com/course/view.php?id=2';
        $message->contexturlname = 'Curso de prueba';

        $this->assertNotFalse(message_send($message));

        $rows = $DB->get_records(queue::TABLE);
        $this->assertCount(1, $rows);

        $row = reset($rows);
        $this->assertSame(queue::STATUS_PENDING, $row->status);
        $this->assertEquals($user->id, $row->userid);
        $this->assertSame('moodle', $row->component);
        $this->assertSame(self::PROVIDER, $row->name);
        $this->assertSame('+5491112345678', $row->phone);
        $this->assertSame(template_mapper::TEMPLATE, $row->templatekey);
        $this->assertSame('https://example.com/course/view.php?id=2', $row->url);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertGreaterThan(0, (int) $row->savedmessageid);
        $this->assertNull($row->error);

        $params = json_decode($row->params);
        $this->assertCount(3, $params);
        $this->assertSame('Demo', $params[0]);
        $this->assertSame('Curso terminado', $params[1]);
    }

    /**
     * A user with a row but no consent gets a skipped row, not a silent nothing.
     *
     * Core does not normally reach the processor for such a user, because it asks is_user_configured() first, so
     * this is the second barrier: the pre_processor_message_send extension point lets another plugin of the site
     * rewrite the event data on the way in, and the recipient of the data we actually received is resolved again.
     * The processor is therefore called directly here, which is exactly the situation being guarded against.
     *
     * @return void
     */
    public function test_send_message_skips_a_user_that_never_opted_in(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        recipient::resolve($user->id);

        $processor = new \message_output_whatsapp();
        $this->assertTrue($processor->send_message($this->eventdata($user)));

        $row = $this->only_row();
        $this->assertSame(queue::STATUS_SKIPPED, $row->status);
        $this->assertSame(queue::SKIP_NO_OPTIN, $row->error);
        $this->assertSame(0, (int) $row->nextattempt);
        $this->assertSame('+5491112345678', $row->phone);
        $this->assertSame(1, $DB->count_records(queue::TABLE));
    }

    /**
     * A user the site has never looked up has no row and no consent, and is skipped without one being created.
     *
     * @return void
     */
    public function test_send_message_skips_a_user_with_no_recipient_row(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);

        $processor = new \message_output_whatsapp();
        $this->assertTrue($processor->send_message($this->eventdata($user)));

        $row = $this->only_row();
        $this->assertSame(queue::STATUS_SKIPPED, $row->status);
        $this->assertSame(queue::SKIP_NO_OPTIN, $row->error);
        $this->assertSame('', $row->phone);

        // The send path never writes to message_whatsapp_user: no row means no consent, so there is nothing to ask.
        $this->assertSame(0, $DB->count_records('message_whatsapp_user'));
    }

    /**
     * A user who opted in and then cleared their number is skipped as well.
     *
     * @return void
     */
    public function test_send_message_skips_a_user_whose_number_was_removed(): void {
        $user = $this->opted_in_user();
        recipient::find($user->id)->set_phone('');

        $processor = new \message_output_whatsapp();
        $this->assertTrue($processor->send_message($this->eventdata($user)));

        $row = $this->only_row();
        $this->assertSame(queue::STATUS_SKIPPED, $row->status);
        $this->assertSame(queue::SKIP_NO_OPTIN, $row->error);
    }

    /**
     * A user whose number the provider refused is skipped under a reason of their own, not under "no consent".
     *
     * They did consent. Filing this under {@see queue::SKIP_NO_OPTIN} would make the delivery report state
     * something untrue about them, and it is the report that someone reads to find out why nothing arrived.
     *
     * @return void
     */
    public function test_send_message_skips_a_user_whose_number_is_marked_invalid(): void {
        $user = $this->opted_in_user();
        recipient::find($user->id)->mark_undeliverable();

        $processor = new \message_output_whatsapp();
        $this->assertTrue($processor->send_message($this->eventdata($user)));

        $row = $this->only_row();
        $this->assertSame(queue::STATUS_SKIPPED, $row->status);
        $this->assertSame(queue::SKIP_INVALID_PHONE, $row->error);
        $this->assertNotSame(queue::SKIP_NO_OPTIN, $row->error);
    }

    /**
     * Saving the number again clears the mark, which is the only way back that does not need an administrator.
     *
     * @return void
     */
    public function test_saving_the_number_again_puts_the_channel_back_on(): void {
        $user = $this->opted_in_user();
        $recipient = recipient::find($user->id);
        $recipient->mark_undeliverable();
        $this->assertFalse($recipient->is_sendable());

        $recipient->set_phone('011 15 1234-5678');

        $this->assertFalse($recipient->is_invalid());
        $this->assertTrue($recipient->is_sendable());
    }

    /**
     * Personal messages between users are not queued: everything sent is an approved template.
     *
     * @return void
     */
    public function test_send_message_ignores_anything_that_is_not_a_notification(): void {
        global $DB;

        $user = $this->opted_in_user();

        $processor = new \message_output_whatsapp();
        $this->assertTrue($processor->send_message($this->eventdata($user, ['notification' => 0])));

        $this->assertSame(0, $DB->count_records(queue::TABLE));
    }

    /**
     * A site with no sending mode chosen queues nothing, because nothing could ever drain it.
     *
     * @return void
     */
    public function test_send_message_queues_nothing_while_the_site_is_unconfigured(): void {
        global $DB;

        set_config('mode', '', 'message_whatsapp');
        $user = $this->opted_in_user();

        $processor = new \message_output_whatsapp();
        $this->assertTrue($processor->send_message($this->eventdata($user)));

        $this->assertSame(0, $DB->count_records(queue::TABLE));
    }

    /**
     * Event data stripped of everything is survivable: the processor answers true and queues nothing.
     *
     * send_message() runs inside the web request of whoever triggered the event, so an exception here would break
     * a page that has nothing to do with WhatsApp, and a false would make core record the message as undelivered.
     *
     * @return void
     */
    public function test_send_message_survives_event_data_with_nothing_in_it(): void {
        global $DB;

        $processor = new \message_output_whatsapp();

        $this->assertTrue($processor->send_message(new \stdClass()));
        $this->assertTrue($processor->send_message((object) ['notification' => 1]));

        $this->assertSame(0, $DB->count_records(queue::TABLE));
    }

    /**
     * The four CHAR NOT NULL columns of the table carry no default, so enqueue always writes all of them.
     *
     * @return void
     */
    public function test_enqueue_fills_every_column_that_has_no_default(): void {
        global $DB;

        $user = $this->opted_in_user();

        // Event data with a recipient and nothing else: component and name are missing on purpose.
        $id = queue::enqueue((object) ['userto' => $user, 'notification' => 1]);

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('', $row->component);
        $this->assertSame('', $row->name);
        $this->assertSame('+5491112345678', $row->phone);
        $this->assertSame(template_mapper::TEMPLATE, $row->templatekey);
        $this->assertNotSame('', $row->lang);
        $this->assertSame(0, (int) $row->courseid);
        $this->assertNull($row->savedmessageid);
    }

    /**
     * The template language is frozen at enqueue time, not recomputed when the message is sent.
     *
     * The send path has no userto, only a userid, so a profile language change between enqueue and send would
     * otherwise pick a template version that Meta may never have approved. Storing it is what keeps the row and
     * the call to the provider saying the same thing.
     *
     * @return void
     */
    public function test_enqueue_freezes_the_template_language(): void {
        global $DB;

        $user = $this->opted_in_user();
        $eventdata = $this->eventdata($user);

        $id = queue::enqueue($eventdata);
        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(template_mapper::map($eventdata)->lang, $row->lang);

        // Changing the profile language afterwards does not rewrite what was already queued.
        $before = $row->lang;
        $user->lang = $before === 'en' ? 'es' : 'en';
        \core\session\manager::set_user($user);
        $DB->set_field('user', 'lang', $user->lang, ['id' => $user->id]);

        $again = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame($before, $again->lang);
    }

    /**
     * The parameters are stored exactly as the mapper encodes them, not as the queue would encode them again.
     *
     * @return void
     */
    public function test_enqueue_stores_the_parameters_encoded_by_the_mapper(): void {
        global $DB;

        $user = $this->opted_in_user();
        $eventdata = $this->eventdata($user, ['subject' => 'Física &amp; Química']);

        $id = queue::enqueue($eventdata);

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(template_mapper::map($eventdata)->params_json(), $row->params);
        $this->assertStringContainsString('Física & Química', $row->params);
    }

    /**
     * Event data that names no recipient at all is not queued and says so by returning zero.
     *
     * @return void
     */
    public function test_enqueue_returns_zero_when_there_is_no_recipient(): void {
        global $DB;

        $this->assertSame(0, queue::enqueue(new \stdClass()));
        $this->assertSame(0, queue::enqueue((object) ['userto' => 'not a user']));
        $this->assertSame(0, $DB->count_records(queue::TABLE));
    }

    /**
     * The backoff is min(2 ^ attempts, 60) minutes, and it is a pure function of the attempt number.
     *
     * @param int $attempts Attempts made so far.
     * @param int $expected Seconds to wait.
     * @return void
     * @dataProvider backoff_provider
     */
    public function test_backoff_seconds(int $attempts, int $expected): void {
        $this->assertSame($expected, queue::backoff_seconds($attempts));
    }

    /**
     * Cases for the backoff.
     *
     * @return array[] Each case is [attempts, expected seconds].
     */
    public static function backoff_provider(): array {
        return [
            'first failure' => [1, 2 * MINSECS],
            'second failure' => [2, 4 * MINSECS],
            'third failure' => [3, 8 * MINSECS],
            'fourth failure' => [4, 16 * MINSECS],
            'fifth failure' => [5, 32 * MINSECS],
            'capped at an hour' => [6, 60 * MINSECS],
            'still capped much later' => [20, 60 * MINSECS],
            'zero is read as the first failure' => [0, 2 * MINSECS],
            'a negative is read as the first failure' => [-3, 2 * MINSECS],
        ];
    }

    /**
     * The quiet hours window is a pure function of the moment, the two hours and the time zone.
     *
     * @param string $localtime Local time to ask about.
     * @param int $start Hour the window starts at.
     * @param int $end Hour the window ends at.
     * @param bool $expected Whether that moment is inside the window.
     * @return void
     * @dataProvider quiet_hours_provider
     */
    public function test_in_quiet_hours(string $localtime, int $start, int $end, bool $expected): void {
        $timezone = 'America/Argentina/Buenos_Aires';
        $time = (new \DateTimeImmutable($localtime, new \DateTimeZone($timezone)))->getTimestamp();

        $this->assertSame($expected, queue::in_quiet_hours($time, $start, $end, $timezone));
    }

    /**
     * Cases for the quiet hours window.
     *
     * @return array[] Each case is [local time, start hour, end hour, expected].
     */
    public static function quiet_hours_provider(): array {
        return [
            'the night, inside a window that wraps' => ['2026-09-13 03:00:00', 22, 8, true],
            'right when it starts' => ['2026-09-13 22:00:00', 22, 8, true],
            'one minute before it starts' => ['2026-09-13 21:59:00', 22, 8, false],
            'right when it ends' => ['2026-09-13 08:00:00', 22, 8, false],
            'one minute before it ends' => ['2026-09-13 07:59:00', 22, 8, true],
            'midday' => ['2026-09-13 12:00:00', 22, 8, false],
            'a window that does not wrap, inside' => ['2026-09-13 02:30:00', 1, 5, true],
            'a window that does not wrap, outside' => ['2026-09-13 23:30:00', 1, 5, false],
            'the same hour twice is no window' => ['2026-09-13 03:00:00', 8, 8, false],
            'an hour out of range disables it' => ['2026-09-13 03:00:00', 22, 99, false],
            'a negative hour disables it' => ['2026-09-13 03:00:00', -1, 8, false],
        ];
    }

    /**
     * A moment inside the quiet hours is moved to the end of them, and a moment outside is left alone.
     *
     * @return void
     */
    public function test_quiet_hours_end(): void {
        $timezone = 'America/Argentina/Buenos_Aires';
        $zone = new \DateTimeZone($timezone);

        $night = (new \DateTimeImmutable('2026-09-13 03:00:00', $zone))->getTimestamp();
        $expected = (new \DateTimeImmutable('2026-09-13 08:00:00', $zone))->getTimestamp();
        $this->assertSame($expected, queue::quiet_hours_end($night, 22, 8, $timezone));

        // Before midnight the window is still open, so its end is on the following day.
        $evening = (new \DateTimeImmutable('2026-09-13 23:30:00', $zone))->getTimestamp();
        $expected = (new \DateTimeImmutable('2026-09-14 08:00:00', $zone))->getTimestamp();
        $this->assertSame($expected, queue::quiet_hours_end($evening, 22, 8, $timezone));

        $midday = (new \DateTimeImmutable('2026-09-13 12:00:00', $zone))->getTimestamp();
        $this->assertSame($midday, queue::quiet_hours_end($midday, 22, 8, $timezone));
    }

    /**
     * The bounds of a local day are the local midnights around it, whatever the time zone of the site is.
     *
     * @return void
     */
    public function test_day_bounds(): void {
        $timezone = 'America/Argentina/Buenos_Aires';
        $zone = new \DateTimeZone($timezone);

        $time = (new \DateTimeImmutable('2026-09-13 23:30:00', $zone))->getTimestamp();
        [$start, $end] = queue::day_bounds($time, $timezone);

        $this->assertSame((new \DateTimeImmutable('2026-09-13 00:00:00', $zone))->getTimestamp(), $start);
        $this->assertSame((new \DateTimeImmutable('2026-09-14 00:00:00', $zone))->getTimestamp(), $end);
        $this->assertSame(DAYSECS, $end - $start);
    }

    /**
     * The daily cap is a pure comparison, and a cap of zero or less is no cap at all.
     *
     * @param int $queuedtoday Messages already queued today.
     * @param int $cap Configured cap.
     * @param bool $expected Whether one more would be over it.
     * @return void
     * @dataProvider daily_cap_provider
     */
    public function test_exceeds_daily_cap(int $queuedtoday, int $cap, bool $expected): void {
        $this->assertSame($expected, queue::exceeds_daily_cap($queuedtoday, $cap));
    }

    /**
     * Cases for the daily cap.
     *
     * @return array[] Each case is [queued today, cap, expected].
     */
    public static function daily_cap_provider(): array {
        return [
            'no cap configured' => [1000, 0, false],
            'a negative cap is no cap' => [1000, -5, false],
            'under the cap' => [1, 3, false],
            'one below the cap' => [2, 3, false],
            'at the cap' => [3, 3, true],
            'over the cap' => [9, 3, true],
            'a cap of one, nothing sent yet' => [0, 1, false],
            'a cap of one, one sent' => [1, 1, true],
        ];
    }

    /**
     * A message that comes up inside the quiet hours waits for them to end instead of being discarded.
     *
     * The window is built around the hour the test happens to run at, which is what makes the assertion exact
     * without a clock to freeze: every moment of one hour shares the same window end.
     *
     * @return void
     */
    public function test_enqueue_defers_a_message_that_falls_into_the_quiet_hours(): void {
        global $DB;

        $start = $this->current_hour();
        $end = ($start + 2) % 24;
        set_config('quiethours', 1, 'message_whatsapp');
        set_config('quietstart', $start, 'message_whatsapp');
        set_config('quietend', $end, 'message_whatsapp');

        $user = $this->opted_in_user();
        $now = time();
        $id = queue::enqueue($this->eventdata($user));

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $expected = queue::quiet_hours_end($now, $start, $end, \core_date::get_server_timezone());

        $this->assertSame(queue::STATUS_PENDING, $row->status);
        $this->assertSame($expected, (int) $row->nextattempt);
        $this->assertGreaterThan($now, (int) $row->nextattempt);
    }

    /**
     * Outside the quiet hours a message is due immediately.
     *
     * @return void
     */
    public function test_enqueue_does_not_defer_outside_the_quiet_hours(): void {
        global $DB;

        $hour = $this->current_hour();
        set_config('quiethours', 1, 'message_whatsapp');
        set_config('quietstart', ($hour + 2) % 24, 'message_whatsapp');
        set_config('quietend', ($hour + 3) % 24, 'message_whatsapp');

        $user = $this->opted_in_user();
        $now = time();
        $id = queue::enqueue($this->eventdata($user));

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertGreaterThanOrEqual($now, (int) $row->nextattempt);
        $this->assertLessThanOrEqual(time(), (int) $row->nextattempt);
    }

    /**
     * With the quiet hours switched off nothing is deferred, whatever the configured window says.
     *
     * @return void
     */
    public function test_the_quiet_hours_can_be_switched_off(): void {
        global $DB;

        $hour = $this->current_hour();
        set_config('quiethours', 0, 'message_whatsapp');
        set_config('quietstart', $hour, 'message_whatsapp');
        set_config('quietend', ($hour + 2) % 24, 'message_whatsapp');

        $user = $this->opted_in_user();
        $now = time();
        $id = queue::enqueue($this->eventdata($user));

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertLessThanOrEqual(time(), (int) $row->nextattempt);
        $this->assertGreaterThanOrEqual($now, (int) $row->nextattempt);
    }

    /**
     * Once a user has had as many messages as the cap allows, the rest of the day is skipped.
     *
     * @return void
     */
    public function test_enqueue_skips_what_is_over_the_daily_cap(): void {
        set_config('dailycap', 2, 'message_whatsapp');

        $user = $this->opted_in_user();

        $this->assertSame(queue::STATUS_PENDING, $this->status_of(queue::enqueue($this->eventdata($user))));
        $this->assertSame(queue::STATUS_PENDING, $this->status_of(queue::enqueue($this->eventdata($user))));

        $third = queue::enqueue($this->eventdata($user));
        $this->assertSame(queue::STATUS_SKIPPED, $this->status_of($third));
        $this->assertSame(queue::SKIP_DAILY_CAP, $this->error_of($third));
    }

    /**
     * The cap is counted per user: one user reaching it does not silence anybody else.
     *
     * @return void
     */
    public function test_the_daily_cap_is_counted_per_user(): void {
        set_config('dailycap', 1, 'message_whatsapp');

        $first = $this->opted_in_user();
        $second = $this->opted_in_user('011 15 8765-4321');

        $this->assertSame(queue::STATUS_PENDING, $this->status_of(queue::enqueue($this->eventdata($first))));
        $this->assertSame(queue::STATUS_SKIPPED, $this->status_of(queue::enqueue($this->eventdata($first))));
        $this->assertSame(queue::STATUS_PENDING, $this->status_of(queue::enqueue($this->eventdata($second))));
    }

    /**
     * Skipped rows do not eat the allowance: they were never going to be sent.
     *
     * @return void
     */
    public function test_skipped_rows_do_not_count_towards_the_daily_cap(): void {
        set_config('dailycap', 1, 'message_whatsapp');

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        recipient::resolve($user->id);

        // Queued while the user had not consented yet, so it was skipped.
        $this->assertSame(queue::STATUS_SKIPPED, $this->status_of(queue::enqueue($this->eventdata($user))));

        recipient::find($user->id)->set_optin(true);

        $this->assertSame(queue::STATUS_PENDING, $this->status_of(queue::enqueue($this->eventdata($user))));
    }

    /**
     * Claiming hands out the due rows, oldest first, and marks them as being sent.
     *
     * @return void
     */
    public function test_claim_hands_out_the_due_rows_and_marks_them_sending(): void {
        global $DB;

        $now = time();
        $first = $this->queue_row(['nextattempt' => $now - 60]);
        $second = $this->queue_row(['nextattempt' => $now - 30]);

        $claimed = queue::claim(10);

        $this->assertSame([$first, $second], array_map('intval', array_keys($claimed)));
        foreach ($claimed as $row) {
            $this->assertSame(queue::STATUS_SENDING, $row->status);
        }
        $this->assertSame(2, $DB->count_records(queue::TABLE, ['status' => queue::STATUS_SENDING]));
    }

    /**
     * The row a run has claimed cannot be claimed by the next one, which is what stops two runs doubling a message.
     *
     * The claim holds a named lock for its whole duration and flips the rows inside a transaction with an update
     * that still says `AND status = 'pending'`, so a second run either waits for the first or finds nothing left.
     * What is observable from here is the outcome that matters: the same row is never handed out twice.
     *
     * @return void
     */
    public function test_a_second_run_cannot_claim_what_the_first_one_took(): void {
        $this->queue_row();
        $this->queue_row();

        $first = queue::claim(10);
        $second = queue::claim(10);

        $this->assertCount(2, $first);
        $this->assertSame([], $second);
    }

    /**
     * The limit is respected and the rows that are due first are the ones handed out.
     *
     * @return void
     */
    public function test_claim_respects_the_limit(): void {
        $now = time();
        $oldest = $this->queue_row(['nextattempt' => $now - 300]);
        $middle = $this->queue_row(['nextattempt' => $now - 200]);
        $this->queue_row(['nextattempt' => $now - 100]);

        $claimed = queue::claim(2);

        $this->assertSame([$oldest, $middle], array_map('intval', array_keys($claimed)));
    }

    /**
     * A limit of zero or less claims nothing at all.
     *
     * @return void
     */
    public function test_claim_with_no_limit_to_speak_of_does_nothing(): void {
        $id = $this->queue_row();

        $this->assertSame([], queue::claim(0));
        $this->assertSame([], queue::claim(-1));
        $this->assertSame(queue::STATUS_PENDING, $this->status_of($id));
    }

    /**
     * A row whose next attempt is in the future is not due and is left alone.
     *
     * @return void
     */
    public function test_claim_ignores_a_row_that_is_not_due_yet(): void {
        $id = $this->queue_row(['nextattempt' => time() + HOURSECS]);

        $this->assertSame([], queue::claim(10));
        $this->assertSame(queue::STATUS_PENDING, $this->status_of($id));
    }

    /**
     * Rows that are not pending are not claimable, whatever else has happened to them.
     *
     * @return void
     */
    public function test_claim_only_takes_pending_rows(): void {
        $this->queue_row(['status' => queue::STATUS_SENT]);
        $this->queue_row(['status' => queue::STATUS_FAILED]);
        $this->queue_row(['status' => queue::STATUS_SKIPPED]);

        $this->assertSame([], queue::claim(10));
    }

    /**
     * A row left in the sending state by a process that died is put back on the queue with its backoff.
     *
     * It comes back as pending rather than being handed out again straight away, so that a row whose process dies
     * every time is spaced out like any other failure instead of being retried in a tight loop.
     *
     * @return void
     */
    public function test_claim_takes_back_a_row_orphaned_while_sending(): void {
        global $DB;

        $now = time();
        $id = $this->queue_row([
            'status' => queue::STATUS_SENDING,
            'timestatus' => $now - queue::STALE_SENDING_SECONDS - MINSECS,
        ]);

        $this->assertSame([], queue::claim(10));

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_PENDING, $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(queue::ERROR_ORPHANED, $row->error);
        $this->assertGreaterThan($now, (int) $row->nextattempt);
    }

    /**
     * A row that is being sent right now belongs to a live process and is not taken from it.
     *
     * @return void
     */
    public function test_claim_leaves_a_row_that_is_still_being_sent_alone(): void {
        global $DB;

        $id = $this->queue_row(['status' => queue::STATUS_SENDING, 'timestatus' => time()]);

        $this->assertSame([], queue::claim(10));

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_SENDING, $row->status);
        $this->assertSame(0, (int) $row->attempts);
    }

    /**
     * An orphan that has already used up its attempts is failed instead of being queued forever.
     *
     * @return void
     */
    public function test_an_orphan_out_of_attempts_is_failed(): void {
        global $DB;

        $id = $this->queue_row([
            'status' => queue::STATUS_SENDING,
            'attempts' => queue::MAX_ATTEMPTS - 1,
            'timestatus' => time() - queue::STALE_SENDING_SECONDS - MINSECS,
        ]);

        $this->assertSame([], queue::claim(10));

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_FAILED, $row->status);
        $this->assertSame(queue::MAX_ATTEMPTS, (int) $row->attempts);
        $this->assertSame(queue::ERROR_ORPHANED, $row->error);
    }

    /**
     * A message the provider accepted records the id it gave back and stops being due.
     *
     * @return void
     */
    public function test_mark_sent(): void {
        global $DB;

        $id = $this->queue_row(['status' => queue::STATUS_SENDING]);
        $now = time();

        queue::mark_sent($id, 'wamid.HBgLNTQ5MTE=', 'utility');

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_SENT, $row->status);
        $this->assertSame('wamid.HBgLNTQ5MTE=', $row->providermsgid);
        $this->assertSame('utility', $row->pricingcategory);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(0, (int) $row->nextattempt);
        $this->assertGreaterThanOrEqual($now, (int) $row->timesent);
        $this->assertNull($row->error);
    }

    /**
     * A permanent error ends the row there and then, without waiting for the attempt ceiling.
     *
     * @return void
     */
    public function test_mark_failed(): void {
        global $DB;

        $id = $this->queue_row(['status' => queue::STATUS_SENDING]);

        queue::mark_failed($id, 'Meta error 131026: message undeliverable');

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_FAILED, $row->status);
        $this->assertSame('Meta error 131026: message undeliverable', $row->error);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(0, (int) $row->nextattempt);
    }

    /**
     * A transient failure puts the row back on the queue with the backoff of the attempt just made.
     *
     * @return void
     */
    public function test_mark_retry_schedules_the_next_attempt(): void {
        global $DB;

        $id = $this->queue_row(['status' => queue::STATUS_SENDING]);
        $now = time();

        queue::mark_retry($id, 'HTTP 500');

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_PENDING, $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame('HTTP 500', $row->error);
        $this->assertGreaterThanOrEqual($now + queue::backoff_seconds(1), (int) $row->nextattempt);
        $this->assertLessThanOrEqual(time() + queue::backoff_seconds(1), (int) $row->nextattempt);
    }

    /**
     * The delay doubles with every failure, and the fifth one gives up.
     *
     * @return void
     */
    public function test_mark_retry_gives_up_after_the_attempt_ceiling(): void {
        global $DB;

        $id = $this->queue_row();

        for ($attempt = 1; $attempt < queue::MAX_ATTEMPTS; $attempt++) {
            $before = time();
            queue::mark_retry($id, 'HTTP 429');

            $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
            $this->assertSame(queue::STATUS_PENDING, $row->status);
            $this->assertSame($attempt, (int) $row->attempts);
            $this->assertGreaterThanOrEqual($before + queue::backoff_seconds($attempt), (int) $row->nextattempt);
        }

        queue::mark_retry($id, 'HTTP 429');

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_FAILED, $row->status);
        $this->assertSame(queue::MAX_ATTEMPTS, (int) $row->attempts);
        $this->assertSame(0, (int) $row->nextattempt);
    }

    /**
     * A retry that would land inside the quiet hours waits for them to end instead.
     *
     * @return void
     */
    public function test_a_retry_that_lands_in_the_quiet_hours_waits_for_them_to_end(): void {
        global $DB;

        $start = $this->current_hour();
        $end = ($start + 2) % 24;
        set_config('quiethours', 1, 'message_whatsapp');
        set_config('quietstart', $start, 'message_whatsapp');
        set_config('quietend', $end, 'message_whatsapp');

        $id = $this->queue_row(['status' => queue::STATUS_SENDING]);
        $now = time();

        queue::mark_retry($id, 'HTTP 500');

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $expected = queue::quiet_hours_end($now, $start, $end, \core_date::get_server_timezone());
        $this->assertSame($expected, (int) $row->nextattempt);
    }

    /**
     * An error longer than the report can show is cut down instead of being stored whole.
     *
     * @return void
     */
    public function test_a_long_error_is_trimmed(): void {
        global $DB;

        $id = $this->queue_row();

        queue::mark_failed($id, str_repeat('x', 5000));

        $error = $DB->get_field(queue::TABLE, 'error', ['id' => $id], MUST_EXIST);
        $this->assertSame(1000, \core_text::strlen($error));
    }

    /**
     * Marking a row as skipped leaves the reason where the administrator report can read it.
     *
     * @return void
     */
    public function test_mark_skipped(): void {
        global $DB;

        $id = $this->queue_row(['status' => queue::STATUS_SENDING]);

        queue::mark_skipped($id, queue::SKIP_DAILY_CAP);

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_SKIPPED, $row->status);
        $this->assertSame(queue::SKIP_DAILY_CAP, $row->error);
        $this->assertSame(0, (int) $row->nextattempt);
    }

    /**
     * Creates a user with an Argentine mobile in their profile who has consented to the channel.
     *
     * @param string $phone Number as it would be typed into the profile.
     * @return \stdClass The user record.
     */
    private function opted_in_user(string $phone = '011 15 1234-5678'): \stdClass {
        $user = $this->getDataGenerator()->create_user(['phone2' => $phone]);
        recipient::resolve($user->id)->set_optin(true);

        return $user;
    }

    /**
     * Builds event data of the shape core hands to a processor.
     *
     * @param \stdClass $user The recipient.
     * @param array $overrides Fields to replace in the default event data.
     * @return \stdClass The event data.
     */
    private function eventdata(\stdClass $user, array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'userto' => $user,
            'userfrom' => get_admin(),
            'notification' => 1,
            'component' => 'mod_assign',
            'name' => 'assign_notification',
            'courseid' => 0,
            'subject' => 'Tarea 1 calificada',
            'fullmessage' => 'Tu entrega fue calificada.',
            'fullmessageformat' => FORMAT_PLAIN,
            'fullmessagehtml' => '<p>Tu entrega fue calificada.</p>',
            'smallmessage' => 'Tu entrega fue calificada.',
            'contexturl' => 'https://example.com/mod/assign/view.php?id=1',
            'contexturlname' => 'Tarea 1',
        ]);
    }

    /**
     * Writes a queue row directly, so that the claiming and the retries can be tested without a whole message.
     *
     * @param array $overrides Columns to replace in the default row.
     * @return int Id of the row.
     */
    private function queue_row(array $overrides = []): int {
        global $DB;

        $now = time();
        $user = $this->getDataGenerator()->create_user();

        return (int) $DB->insert_record(queue::TABLE, (object) ($overrides + [
            'userid' => $user->id,
            'savedmessageid' => null,
            'component' => 'mod_assign',
            'name' => 'assign_notification',
            'courseid' => 0,
            'phone' => '+5491112345678',
            'templatekey' => template_mapper::TEMPLATE,
            'params' => '["Demo","Tarea 1 calificada","Tu entrega fue calificada."]',
            'url' => 'https://example.com/mod/assign/view.php?id=1',
            'status' => queue::STATUS_PENDING,
            'attempts' => 0,
            'nextattempt' => $now,
            'providermsgid' => null,
            'error' => null,
            'pricingcategory' => null,
            'timecreated' => $now,
            'timesent' => 0,
            'timestatus' => $now,
        ]));
    }

    /**
     * Turns the WhatsApp output on for the whole site and forgets the cached list of processors.
     *
     * @return void
     */
    private function enable_processor(): void {
        global $DB;

        $DB->set_field('message_processors', 'enabled', 1, ['name' => 'whatsapp']);
        get_message_processors(true, true);
    }

    /**
     * Returns the only row of the queue, failing the test when there is not exactly one.
     *
     * @return \stdClass The row.
     */
    private function only_row(): \stdClass {
        global $DB;

        $rows = $DB->get_records(queue::TABLE);
        $this->assertCount(1, $rows);

        return reset($rows);
    }

    /**
     * Returns the status of a queue row.
     *
     * @param int $id Id of the row.
     * @return string The status.
     */
    private function status_of(int $id): string {
        global $DB;

        return (string) $DB->get_field(queue::TABLE, 'status', ['id' => $id], MUST_EXIST);
    }

    /**
     * Returns the error of a queue row.
     *
     * @param int $id Id of the row.
     * @return string The error, or the empty string when there is none.
     */
    private function error_of(int $id): string {
        global $DB;

        return (string) $DB->get_field(queue::TABLE, 'error', ['id' => $id], MUST_EXIST);
    }

    /**
     * Returns the hour of the day it is right now on this site.
     *
     * @return int Hour, 0 to 23.
     */
    private function current_hour(): int {
        $zone = new \DateTimeZone(\core_date::get_server_timezone());

        return (int) (new \DateTimeImmutable('now', $zone))->format('G');
    }
}
