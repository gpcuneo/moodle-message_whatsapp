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
 * Tests for the sending task of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\queue;
use message_whatsapp\local\recipient;
use message_whatsapp\local\template_mapper;
use message_whatsapp\task\send_queue;
use message_whatsapp\transport\meta_cloud;
use message_whatsapp\transport\result;
use message_whatsapp\transport\transport_interface;

/**
 * Tests for the sending task of the WhatsApp message processor.
 *
 * The task is driven with a transport double declared inside this file, never with a real transport: nothing here
 * may touch the network, and `meta_cloud` is being written in parallel, so a test that named it would be a test
 * about which files happen to exist rather than about the task. The double is anonymous because `moodle-cs` does
 * not allow two named classes in one file, which is the same way T2.1 solved it.
 *
 * The queue rows are written straight with `$DB` instead of through `message_send()`. What is under test is what
 * the task does with a row that is already due, and going through the whole of core to produce one would make
 * every test here depend on the mapping, on the opt-in and on the hour of the day.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\task\send_queue
 */
final class send_queue_task_test extends \advanced_testcase {
    /** A destination that appears nowhere else, so that a search for it in the log means something. */
    private const PHONE = '+5491198765432';

    /** The subject of the notification of the default row, equally distinctive. */
    private const SUBJECT = 'Entrega de Trabajo Practico Cuatro';

    /** The summary of the notification of the default row. */
    private const SUMMARY = 'Tu entrega fue calificada con 8 sobre 10.';

    /**
     * Every test starts from a site with no quiet hours and no daily cap.
     *
     * Both are off so that the tests do not depend on the hour they run at: T1.5 applies them when a row is
     * written, and a retry written at half past ten at night would otherwise be dated eight in the morning.
     *
     * No sending mode is configured either, and none is needed: every test but one drives the task with a double,
     * and the one that does not is about a site that has no transport. That also keeps this file from depending
     * on which transports happen to be installed while the rest of the group is being written.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('quiethours', 0, 'message_whatsapp');
        set_config('dailycap', 0, 'message_whatsapp');
    }

    /**
     * Three due rows, of which the provider takes two: two end sent and the third goes back in the queue.
     *
     * This is the acceptance test of T2.3 and it is written the way the plan states it.
     *
     * @return void
     */
    public function test_three_pending_rows_end_as_two_sent_and_one_deferred(): void {
        $first = $this->queue_row();
        $second = $this->queue_row();
        $third = $this->queue_row();

        $transport = $this->transport_double([
            $first => result::success('wamid.FIRST'),
            $second => result::transient_failure('http_500', 'Internal server error'),
            $third => result::success('wamid.THIRD'),
        ]);

        $this->run_task($transport);

        $this->assertSame(queue::STATUS_SENT, $this->field($first, 'status'));
        $this->assertSame(queue::STATUS_PENDING, $this->field($second, 'status'));
        $this->assertSame(queue::STATUS_SENT, $this->field($third, 'status'));

        $this->assertSame('wamid.FIRST', $this->field($first, 'providermsgid'));
        $this->assertSame('wamid.THIRD', $this->field($third, 'providermsgid'));

        $this->assertSame(3, count($transport->calls));
        $this->assertSame(1, (int) $this->field($second, 'attempts'));
        $this->assertStringContainsString('http_500', (string) $this->field($second, 'error'));
        $this->assertGreaterThan(time(), (int) $this->field($second, 'nextattempt'));
    }

    /**
     * The second run of the task only picks up the row the first one handed back.
     *
     * The clock is stood in for by rewinding `nextattempt`: what the test is about is that the two rows that left
     * are not sent again, not that two minutes of backoff really elapse.
     *
     * @return void
     */
    public function test_a_second_run_only_retries_the_row_that_was_deferred(): void {
        $first = $this->queue_row();
        $second = $this->queue_row();
        $third = $this->queue_row();

        $this->run_task($this->transport_double([
            $first => result::success('wamid.FIRST'),
            $second => result::transient_failure('http_429', 'Too many requests'),
            $third => result::success('wamid.THIRD'),
        ]));

        $this->make_due($second);

        $retryrun = $this->transport_double([$second => result::success('wamid.SECOND')]);
        $this->run_task($retryrun);

        $this->assertSame([(string) $second], array_column($retryrun->calls, 'idempotencykey'));

        $this->assertSame(queue::STATUS_SENT, $this->field($first, 'status'));
        $this->assertSame(queue::STATUS_SENT, $this->field($second, 'status'));
        $this->assertSame(queue::STATUS_SENT, $this->field($third, 'status'));

        $this->assertSame('wamid.SECOND', $this->field($second, 'providermsgid'));
        $this->assertSame(2, (int) $this->field($second, 'attempts'));
        $this->assertNull($this->field($second, 'error'));
    }

    /**
     * The transport is handed what the row stored, decoded, and nothing it would have to decode itself.
     *
     * @return void
     */
    public function test_the_transport_gets_what_the_row_stored(): void {
        $id = $this->queue_row(['lang' => 'es_AR']);

        $transport = $this->transport_double([]);
        $this->run_task($transport);

        $call = $transport->calls[0];

        $this->assertSame(self::PHONE, $call['phone']);
        $this->assertSame(template_mapper::TEMPLATE, $call['template']);
        $this->assertSame('es_AR', $call['lang']);
        $this->assertSame(['Demo', self::SUBJECT, self::SUMMARY], $call['params']);
        $this->assertSame((string) $id, $call['idempotencykey']);
    }

    /**
     * The idempotency key is the queue row id and it is the same one on every attempt of that row.
     *
     * This is the whole point of the key: a row that is retried, including one reclaimed after its process died,
     * must not make the recipient receive the same notification twice.
     *
     * @return void
     */
    public function test_the_idempotency_key_does_not_change_between_attempts(): void {
        $id = $this->queue_row();

        $first = $this->transport_double([$id => result::transient_failure('timeout', 'Connection timed out')]);
        $this->run_task($first);

        $this->make_due($id);

        $second = $this->transport_double([$id => result::success('wamid.OK')]);
        $this->run_task($second);

        $this->assertSame((string) $id, $first->calls[0]['idempotencykey']);
        $this->assertSame((string) $id, $second->calls[0]['idempotencykey']);
        $this->assertSame(queue::STATUS_SENT, $this->field($id, 'status'));
    }

    /**
     * The suffix of the URL button is the signed click token of the row, and go.php can read it back.
     *
     * This asserts the round trip rather than the spelling. The sending side and the redirecting side have to
     * agree, and they agree by calling the same pair of methods; a test that pinned the format here would keep
     * passing if only one of the two sides changed it.
     *
     * @return void
     */
    public function test_the_url_suffix_is_a_token_that_go_php_can_read_back(): void {
        $id = $this->queue_row();

        $transport = $this->transport_double([]);
        $this->run_task($transport);

        $suffix = $transport->calls[0]['urlsuffix'];

        $this->assertNotSame((string) $id, $suffix, 'the id must not travel bare');
        $this->assertSame($id, queue::queueid_from_click_token($suffix));
    }

    /**
     * A suffix from another site does not name a row here.
     *
     * @return void
     */
    public function test_a_click_token_from_another_site_names_nothing(): void {
        $id = $this->queue_row();
        $token = queue::click_token($id);

        // Same id, a signature this site never produced.
        $this->assertSame(0, queue::queueid_from_click_token($id . '.' . str_repeat('0', 32)));
        $this->assertSame($id, queue::queueid_from_click_token($token));
    }

    /**
     * A permanent failure is written off on the first attempt instead of spending five of them.
     *
     * @return void
     */
    public function test_a_permanent_failure_is_written_off_on_the_first_attempt(): void {
        $id = $this->queue_row();

        $this->run_task($this->transport_double([
            $id => result::permanent_failure('131026', 'Message undeliverable'),
        ]));

        $this->assertSame(queue::STATUS_FAILED, $this->field($id, 'status'));
        $this->assertSame(1, (int) $this->field($id, 'attempts'));
        $this->assertStringContainsString('131026', (string) $this->field($id, 'error'));
        $this->assertStringContainsString('Message undeliverable', (string) $this->field($id, 'error'));
    }

    /**
     * The one failure that is about the person marks their number, so the channel stops asking the same question.
     *
     * @return void
     */
    public function test_an_undeliverable_number_is_marked_invalid(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234 5678']);
        $recipient = recipient::resolve((int) $user->id);
        $recipient->set_optin(true);
        $this->assertTrue($recipient->is_sendable());

        $id = $this->queue_row(['userid' => $user->id]);

        $this->run_task($this->transport_double([
            $id => result::permanent_failure(meta_cloud::CODE_UNDELIVERABLE, 'Message undeliverable'),
        ]));

        $this->assertSame(queue::STATUS_FAILED, $this->field($id, 'status'));

        $after = recipient::find((int) $user->id);
        $this->assertTrue($after->is_invalid());
        $this->assertFalse($after->is_sendable());
        // The number is kept: the user cannot correct a number the form does not show them.
        $this->assertNotSame('', $after->phone);
    }

    /**
     * Every other permanent failure is about the site, and blaming the recipient for one would be wrong.
     *
     * @return void
     */
    public function test_a_permanent_failure_about_the_site_leaves_the_recipient_alone(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234 5678']);
        $recipient = recipient::resolve((int) $user->id);
        $recipient->set_optin(true);

        $id = $this->queue_row(['userid' => $user->id]);

        // 132001 is a template that does not exist: the site has to fix it, and no number is at fault.
        $this->run_task($this->transport_double([
            $id => result::permanent_failure('meta_132001', 'Template does not exist'),
        ]));

        $this->assertSame(queue::STATUS_FAILED, $this->field($id, 'status'));

        $after = recipient::find((int) $user->id);
        $this->assertFalse($after->is_invalid());
        $this->assertTrue($after->is_sendable());
    }

    /**
     * A row that has already used its attempts is failed rather than deferred forever.
     *
     * The decision belongs to the queue and not to the task; what is pinned here is that the task lets it make it,
     * by calling `mark_retry()` and not by writing the state itself.
     *
     * @return void
     */
    public function test_a_row_that_runs_out_of_attempts_is_failed(): void {
        $id = $this->queue_row(['attempts' => queue::MAX_ATTEMPTS - 1]);

        $this->run_task($this->transport_double([$id => result::transient_failure('http_503', 'Unavailable')]));

        $this->assertSame(queue::STATUS_FAILED, $this->field($id, 'status'));
        $this->assertSame(queue::MAX_ATTEMPTS, (int) $this->field($id, 'attempts'));
    }

    /**
     * One row that raises does not cost the other ninety nine.
     *
     * The interface promises that a transport never throws, but a transport is HTTP client code and a library
     * below it can raise anything. Without the guard the rest of the batch would be left `sending` until the
     * stale reclaim of the queue found it a quarter of an hour later, each row having burned an attempt it never
     * used.
     *
     * @return void
     */
    public function test_an_exception_from_one_row_does_not_cost_the_others(): void {
        $first = $this->queue_row();
        $second = $this->queue_row();
        $third = $this->queue_row();

        $transport = $this->transport_double([
            $second => new \RuntimeException('the client blew up'),
        ]);

        $this->run_task($transport);

        $this->assertSame(queue::STATUS_SENT, $this->field($first, 'status'));
        $this->assertSame(queue::STATUS_PENDING, $this->field($second, 'status'));
        $this->assertSame(queue::STATUS_SENT, $this->field($third, 'status'));

        $this->assertSame(3, count($transport->calls));
        $this->assertSame(1, (int) $this->field($second, 'attempts'));
        $this->assertStringContainsString(send_queue::ERROR_EXCEPTION, (string) $this->field($second, 'error'));
        $this->assertStringContainsString('RuntimeException', (string) $this->field($second, 'error'));
    }

    /**
     * The log carries row ids and failure codes, never a phone number and never the text of a notification.
     *
     * The cron log is read by whoever runs the server, is pasted into tickets and is kept until somebody rotates
     * it. A phone number is personal data and the subject of a notification is the content of a private message.
     * The exception here carries the phone number on purpose, which is the case the design is about: the
     * diagnostic goes to the error column of the row, where the privacy provider can reach it, and not to the log.
     *
     * @return void
     */
    public function test_the_log_never_prints_a_phone_number_or_the_text_of_a_notification(): void {
        $sent = $this->queue_row();
        $failed = $this->queue_row();
        $raised = $this->queue_row();

        $log = $this->run_task($this->transport_double([
            $failed => result::permanent_failure('131026', 'Message undeliverable'),
            $raised => new \RuntimeException('POST failed for ' . self::PHONE),
        ]));

        $this->assertStringNotContainsString(self::PHONE, $log);
        $this->assertStringNotContainsString('9198765432', $log);
        $this->assertStringNotContainsString(self::SUBJECT, $log);
        $this->assertStringNotContainsString(self::SUMMARY, $log);

        $this->assertStringContainsString((string) $sent, $log);
        $this->assertStringContainsString('131026', $log);
        $this->assertStringContainsString('RuntimeException', $log);

        // The diagnostic is not lost, it is only kept where it belongs.
        $this->assertStringContainsString(self::PHONE, (string) $this->field($raised, 'error'));
    }

    /**
     * A site with no transport claims nothing at all, and its rows stay pending with their attempts untouched.
     *
     * Claiming them and writing them off would turn a configuration problem the administrator is in the middle of
     * fixing into a queue of notifications that can only be revived one at a time from the report.
     *
     * @return void
     */
    public function test_a_site_with_no_transport_claims_nothing(): void {
        $this->assertEmpty(get_config('message_whatsapp', 'mode'));

        $id = $this->queue_row();

        $log = $this->run_real_task();

        $this->assertSame(queue::STATUS_PENDING, $this->field($id, 'status'));
        $this->assertSame(0, (int) $this->field($id, 'attempts'));
        $this->assertNull($this->field($id, 'error'));
        $this->assertStringContainsString('not_configured', $log);
    }

    /**
     * Rows whose backoff has not elapsed are left alone, because the queue never hands them out.
     *
     * Quiet hours and the daily cap are applied when a row is written, so there is deliberately no second copy of
     * either policy in the task. This is what makes that safe.
     *
     * @return void
     */
    public function test_rows_that_are_not_due_yet_are_left_alone(): void {
        $due = $this->queue_row();
        $later = $this->queue_row(['nextattempt' => time() + HOURSECS]);

        $transport = $this->transport_double([]);
        $this->run_task($transport);

        $this->assertSame(queue::STATUS_SENT, $this->field($due, 'status'));
        $this->assertSame(queue::STATUS_PENDING, $this->field($later, 'status'));
        $this->assertSame([(string) $due], array_column($transport->calls, 'idempotencykey'));
    }

    /**
     * Statuses other than pending are not claimed, so a sent row is never sent twice.
     *
     * @return void
     */
    public function test_only_pending_rows_are_claimed(): void {
        $this->queue_row(['status' => queue::STATUS_SENT]);
        $this->queue_row(['status' => queue::STATUS_SKIPPED]);
        $this->queue_row(['status' => queue::STATUS_FAILED]);

        $transport = $this->transport_double([]);
        $this->run_task($transport);

        $this->assertSame([], $transport->calls);
    }

    /**
     * A row whose stored parameters cannot be read is failed without a network call.
     *
     * Guessing at them would mean handing Meta parameters that do not match the template it approved, and getting
     * the same answer minutes later in a form that is harder to read.
     *
     * @return void
     */
    public function test_a_row_with_unreadable_parameters_fails_without_a_network_call(): void {
        $id = $this->queue_row(['params' => 'not json at all']);

        $transport = $this->transport_double([]);
        $this->run_task($transport);

        $this->assertSame([], $transport->calls);
        $this->assertSame(queue::STATUS_FAILED, $this->field($id, 'status'));
        $this->assertSame(send_queue::ERROR_BAD_PARAMS, $this->field($id, 'error'));
    }

    /**
     * A row with no destination is failed without a network call that is certain to be refused.
     *
     * The queue does not produce these -- a recipient with no phone is skipped at enqueue -- so this is about a
     * row that arrived some other way, from a restore or from a hand edit.
     *
     * @return void
     */
    public function test_a_row_with_no_destination_fails_without_a_network_call(): void {
        $id = $this->queue_row(['phone' => '']);

        $transport = $this->transport_double([]);
        $this->run_task($transport);

        $this->assertSame([], $transport->calls);
        $this->assertSame(queue::STATUS_FAILED, $this->field($id, 'status'));
        $this->assertSame(send_queue::ERROR_NO_PHONE, $this->field($id, 'error'));
    }

    /**
     * An empty queue does not reach the transport at all.
     *
     * @return void
     */
    public function test_an_empty_queue_does_not_reach_the_transport(): void {
        $transport = $this->transport_double([]);

        $log = $this->run_task($transport);

        $this->assertSame([], $transport->calls);
        $this->assertStringContainsString('no queue rows are due', $log);
    }

    /**
     * The batch is a hundred rows by default and follows the setting T2.5 declared.
     *
     * The task reads the setting defensively and falls back to its own constant, which is what made adding the
     * setting a change to `settings.php` alone. The two defaults are pinned against each other here: the setting
     * now ships a default of its own, and a site that never touched it has to get the same batch as a site
     * installed before the setting existed.
     *
     * @return void
     */
    public function test_the_batch_size_is_a_hundred_by_default_and_follows_the_setting(): void {
        $this->assertSame(100, send_queue::DEFAULT_BATCH_SIZE);
        $this->assertSame((string) send_queue::DEFAULT_BATCH_SIZE, get_config('message_whatsapp', 'batchsize'));

        $this->queue_row();
        $this->queue_row();
        $this->queue_row();

        set_config('batchsize', 2, 'message_whatsapp');
        $limited = $this->transport_double([]);
        $this->run_task($limited);
        $this->assertSame(2, count($limited->calls));

        set_config('batchsize', 0, 'message_whatsapp');
        $unlimited = $this->transport_double([]);
        $this->run_task($unlimited);
        $this->assertSame(1, count($unlimited->calls));
    }

    /**
     * Builds a transport that answers a script and remembers everything it was asked.
     *
     * Anonymous so that it stays inside this file, which is how T2.1 solved the same need: `moodle-cs` does not
     * allow a second named class here, and a double under `classes/` would be production code that exists only
     * for a test.
     *
     * @param array $script A result or a throwable to answer, keyed by the id of the queue row. A row that is
     *      not in the script is accepted, which keeps the tests that are not about failures short.
     * @return transport_interface The double, which also records every call it was given.
     */
    private function transport_double(array $script): transport_interface {
        return new class ($script) implements transport_interface {
            /** @var array<int, array<string, mixed>> Every call made to this double, in order. */
            public array $calls = [];

            /**
             * Builds the double.
             *
             * @param array $script What to answer, keyed by the id of the queue row.
             */
            public function __construct(
                /** @var array<int, result|\Throwable> What to answer, keyed by the id of the queue row. */
                private array $script,
            ) {
            }

            /**
             * Records the call and answers whatever the script says, without touching the network.
             *
             * @param string $phone Destination in E.164.
             * @param string $template Template name.
             * @param string $lang Template language.
             * @param string[] $params Body parameters.
             * @param string|null $urlsuffix Suffix of the URL button.
             * @param string $idempotencykey Stable identifier of this attempt.
             * @return result What the script says for this row.
             */
            public function send_template(
                string $phone,
                string $template,
                string $lang,
                array $params,
                ?string $urlsuffix,
                string $idempotencykey
            ): result {
                $this->calls[] = [
                    'phone' => $phone,
                    'template' => $template,
                    'lang' => $lang,
                    'params' => $params,
                    'urlsuffix' => $urlsuffix,
                    'idempotencykey' => $idempotencykey,
                ];

                $answer = $this->script[(int) $idempotencykey] ?? null;

                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer ?? result::success('wamid.' . $idempotencykey);
            }

            /**
             * Says the connection is usable, without testing anything.
             *
             * @return result Always a success.
             */
            public function check(): result {
                return result::success();
            }

            /**
             * Returns the machine name of this double.
             *
             * @return string A name no real transport uses.
             */
            public function name(): string {
                return 'test_double';
            }
        };
    }

    /**
     * Runs the task against a transport double and returns what it wrote to the log.
     *
     * The task asks for its transport through a protected method precisely so that a test can answer it, in the
     * same way the factory leaves its registry open.
     *
     * @param transport_interface $transport Transport this run has to use.
     * @return string Everything `mtrace()` produced.
     */
    private function run_task(transport_interface $transport): string {
        $task = new class extends send_queue {
            /** @var transport_interface|null Transport this run has to use. */
            public ?transport_interface $double = null;

            /**
             * Returns the transport of the test instead of asking the factory.
             *
             * @return transport_interface The double.
             */
            protected function transport(): transport_interface {
                return $this->double;
            }
        };

        $task->double = $transport;

        return $this->capture($task);
    }

    /**
     * Runs the task exactly as cron does, with the transport the factory decides.
     *
     * @return string Everything `mtrace()` produced.
     */
    private function run_real_task(): string {
        return $this->capture(new send_queue());
    }

    /**
     * Runs a task with the output buffer on, so that the log can be read back.
     *
     * @param send_queue $task Task to run.
     * @return string Everything `mtrace()` produced.
     */
    private function capture(send_queue $task): string {
        ob_start();

        try {
            $task->execute();
        } finally {
            $log = (string) ob_get_clean();
        }

        return $log;
    }

    /**
     * Writes one due queue row straight to the database.
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
            'phone' => self::PHONE,
            'templatekey' => template_mapper::TEMPLATE,
            'lang' => 'es_AR',
            'params' => json_encode(['Demo', self::SUBJECT, self::SUMMARY]),
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
     * Brings the backoff of a row forward, standing in for the minutes the test does not wait.
     *
     * @param int $id Id of the queue row.
     * @return void
     */
    private function make_due(int $id): void {
        global $DB;

        $DB->set_field(queue::TABLE, 'nextattempt', time() - MINSECS, ['id' => $id]);
    }

    /**
     * Returns one column of a queue row.
     *
     * @param int $id Id of the queue row.
     * @param string $field Column to read.
     * @return mixed The value, with null preserved.
     */
    private function field(int $id, string $field): mixed {
        global $DB;

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);

        return $row->$field;
    }
}
