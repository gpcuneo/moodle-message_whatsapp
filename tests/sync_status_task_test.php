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
 * Tests for the scheduled task that pulls delivery statuses from the gateway.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use message_output_whatsapp;
use message_whatsapp\local\queue;
use message_whatsapp\local\template_mapper;
use message_whatsapp\task\sync_status;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');
// The task with its transport pointed at a mock handler. It lives in a fixture and not in this file because the
// code standard of Moodle asks for one class per file, and it is not production code.
require_once($CFG->dirroot . '/message/output/whatsapp/tests/fixtures/sync_status_with_mock.php');

/**
 * Tests for the task that keeps the queue up to date in gateway mode.
 *
 * This is the other half of `webhook.php`: in direct mode Meta pushes a delivery report at the site, and here the
 * site pulls, because a service cannot reach a Moodle behind a firewall. What the tests pin is the part that is
 * easy to get wrong and expensive when it is: **the cursor**. Advancing it after a failure loses every status in
 * between for ever, and not advancing it after a success makes the task ask for the same page until the end of
 * time.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\task\sync_status
 */
final class sync_status_task_test extends \advanced_testcase {
    /** Address of the service in these tests. */
    private const URL = 'https://wa.example.com';

    /** Destination of the queue rows. */
    private const PHONE = '+5491122334455';

    /**
     * Every test starts from a site in gateway mode with no cursor stored.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('mode', message_output_whatsapp::MODE_GATEWAY, 'message_whatsapp');
        set_config('gatewayurl', self::URL, 'message_whatsapp');
        set_config('gatewayapikey', 'clave-de-prueba', 'message_whatsapp');
    }

    /**
     * Runs the task against the given answers and returns what it printed.
     *
     * @param array $answers Responses the mock handler returns, in order.
     * @return string The trace output of the run.
     */
    private function run_task(array $answers): string {
        sync_status_with_mock::$mock = new MockHandler($answers);

        ob_start();
        (new sync_status_with_mock())->execute();

        return (string) ob_get_clean();
    }

    /**
     * Builds one page of changes as the gateway answers it.
     *
     * @param array $messages Entries of the `messages` list.
     * @param string $cursor Cursor of the page.
     * @param bool $hasmore Whether another page is waiting.
     * @return Response The answer.
     */
    private static function page(array $messages, string $cursor, bool $hasmore = false): Response {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'messages' => $messages,
            'cursor' => $cursor,
            'has_more' => $hasmore,
        ]));
    }

    /**
     * Builds one change of a page.
     *
     * @param string $id Id the gateway gave the message, which is what the queue row carries.
     * @param string $status Status reported now.
     * @param array $overrides Anything else to set on the entry.
     * @return array The entry.
     */
    private static function change(string $id, string $status, array $overrides = []): array {
        return $overrides + [
            'id' => $id,
            'status' => $status,
            // Lo que Meta le asignó al gateway. Este sitio nunca lo vio y no puede emparejar nada con él: está
            // acá para que el test falle si alguna vez se vuelve a leer esta clave en vez de `id`.
            'provider_msgid' => 'wamid.DE-META',
            'error' => null,
            'pricing_category' => null,
            'billable' => false,
            'attempts' => 1,
            'sent_at' => '2026-09-13T14:35:00Z',
            'status_at' => '2026-09-13T14:36:00Z',
            'cursor' => 'CURSOR-' . $id,
        ];
    }

    /**
     * Puts a sent row in the queue, which is what a delivery status can be about.
     *
     * @param string $providermsgid Id the gateway gave it, as `send_template()` stored it.
     * @param string $status Status it carries now.
     * @return int Id of the row.
     */
    private function sent_row(string $providermsgid, string $status = queue::STATUS_SENT): int {
        global $DB;

        $now = time();
        $user = $this->getDataGenerator()->create_user();

        return (int) $DB->insert_record(queue::TABLE, (object) [
            'userid' => $user->id,
            'savedmessageid' => null,
            'component' => 'mod_assign',
            'name' => 'assign_notification',
            'courseid' => 0,
            'phone' => self::PHONE,
            'templatekey' => template_mapper::TEMPLATE,
            'lang' => 'es_AR',
            'params' => json_encode(['Demo', 'Nota publicada', 'Álgebra I']),
            'url' => 'https://example.com/mod/assign/view.php?id=1',
            'status' => $status,
            'attempts' => 1,
            'nextattempt' => 0,
            'providermsgid' => $providermsgid,
            'error' => null,
            'pricingcategory' => null,
            'timecreated' => $now,
            'timesent' => $now,
            'timestatus' => $now,
        ]);
    }

    /**
     * Reads a queue row back.
     *
     * @param int $id Id of the row.
     * @return \stdClass The row.
     */
    private function reload(int $id): \stdClass {
        global $DB;

        return $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * In direct mode the statuses arrive by webhook, so the task does nothing and asks the service nothing.
     *
     * @return void
     */
    public function test_it_does_nothing_in_direct_mode(): void {
        set_config('mode', message_output_whatsapp::MODE_DIRECT, 'message_whatsapp');
        $id = $this->sent_row('wamid.UNO');

        $output = $this->run_task([self::page([self::change('wamid.UNO', 'delivered')], 'CURSOR-1')]);

        $this->assertStringContainsString('not in gateway mode', $output);
        $this->assertSame(queue::STATUS_SENT, $this->reload($id)->status);
        $this->assertEmpty(get_config('message_whatsapp', sync_status::CURSOR_SETTING));
    }

    /**
     * The ordinary run: the changes move the rows forward and the cursor is stored for the next one.
     *
     * @return void
     */
    public function test_it_applies_the_changes_and_stores_the_cursor(): void {
        $delivered = $this->sent_row('wamid.UNO');
        $failed = $this->sent_row('wamid.DOS');

        $this->run_task([self::page([
            self::change('wamid.UNO', 'delivered', ['pricing_category' => 'utility']),
            self::change('wamid.DOS', 'failed', ['error' => '131026 Message undeliverable']),
        ], 'CURSOR-FINAL')]);

        $first = $this->reload($delivered);
        $this->assertSame(queue::STATUS_DELIVERED, $first->status);
        $this->assertSame('utility', $first->pricingcategory);
        $this->assertSame(strtotime('2026-09-13T14:36:00Z'), (int) $first->timestatus);

        $second = $this->reload($failed);
        $this->assertSame(queue::STATUS_FAILED, $second->status);
        $this->assertStringContainsString('131026', (string) $second->error);

        $this->assertSame('CURSOR-FINAL', get_config('message_whatsapp', sync_status::CURSOR_SETTING));
    }

    /**
     * The cursor of the previous run is what the next one asks from.
     *
     * @return void
     */
    public function test_it_asks_from_the_stored_cursor(): void {
        set_config(sync_status::CURSOR_SETTING, 'CURSOR-ANTERIOR', 'message_whatsapp');
        sync_status_with_mock::$mock = new MockHandler([self::page([], 'CURSOR-ANTERIOR')]);

        ob_start();
        (new sync_status_with_mock())->execute();
        ob_end_clean();

        $this->assertStringContainsString(
            'since=' . rawurlencode('CURSOR-ANTERIOR'),
            (string) sync_status_with_mock::$mock->getLastRequest()->getUri()
        );
    }

    /**
     * A backlog is walked page by page, and each page moves the cursor, so a run that dies halfway does not
     * repeat what it already applied.
     *
     * @return void
     */
    public function test_it_walks_every_page_of_a_backlog(): void {
        $first = $this->sent_row('wamid.UNO');
        $second = $this->sent_row('wamid.DOS');

        $this->run_task([
            self::page([self::change('wamid.UNO', 'delivered')], 'CURSOR-1', true),
            self::page([self::change('wamid.DOS', 'delivered')], 'CURSOR-2', false),
        ]);

        $this->assertSame(queue::STATUS_DELIVERED, $this->reload($first)->status);
        $this->assertSame(queue::STATUS_DELIVERED, $this->reload($second)->status);
        $this->assertSame('CURSOR-2', get_config('message_whatsapp', sync_status::CURSOR_SETTING));
    }

    /**
     * One run does not walk for ever. A site that was down for a day catches up over several runs instead of in
     * one that is the reason cron is late.
     *
     * @return void
     */
    public function test_one_run_stops_after_the_page_ceiling(): void {
        $answers = [];
        for ($page = 0; $page < sync_status::MAX_PAGES + 5; $page++) {
            $answers[] = self::page([], 'CURSOR-' . $page, true);
        }

        $this->run_task($answers);

        $this->assertSame(
            'CURSOR-' . (sync_status::MAX_PAGES - 1),
            get_config('message_whatsapp', sync_status::CURSOR_SETTING)
        );
    }

    /**
     * **The cursor does not move when the gateway did not answer.** This is the one that matters: advancing it
     * here would skip every status change between the stored position and whatever came next, for ever, and
     * nothing would ever say so.
     *
     * @return void
     */
    public function test_a_failure_does_not_move_the_cursor(): void {
        set_config(sync_status::CURSOR_SETTING, 'CURSOR-ANTERIOR', 'message_whatsapp');
        $id = $this->sent_row('wamid.UNO');

        $output = $this->run_task([
            new Response(503, ['Content-Type' => 'application/json'], json_encode([
                'code' => 'database_unavailable',
                'detail' => 'El servicio no puede alcanzar la base de datos.',
            ])),
        ]);

        $this->assertStringContainsString('did not answer', $output);
        $this->assertSame('CURSOR-ANTERIOR', get_config('message_whatsapp', sync_status::CURSOR_SETTING));
        $this->assertSame(queue::STATUS_SENT, $this->reload($id)->status);
    }

    /**
     * A failure halfway through a backlog keeps what the pages before it applied, and leaves the cursor where
     * the last good page left it.
     *
     * @return void
     */
    public function test_a_failure_halfway_keeps_what_was_already_applied(): void {
        $first = $this->sent_row('wamid.UNO');

        $this->run_task([
            self::page([self::change('wamid.UNO', 'delivered')], 'CURSOR-1', true),
            new Response(502, ['Content-Type' => 'application/json'], json_encode([
                'code' => 'meta_error',
                'detail' => 'Meta contestó mal.',
            ])),
        ]);

        $this->assertSame(queue::STATUS_DELIVERED, $this->reload($first)->status);
        $this->assertSame('CURSOR-1', get_config('message_whatsapp', sync_status::CURSOR_SETTING));
    }

    /**
     * A change about a message this site does not have is ordinary: the cursor of a subscription reports every
     * message of it, and a queue that was cleaned up no longer has the older rows.
     *
     * @return void
     */
    public function test_a_change_about_an_unknown_message_is_not_an_error(): void {
        $this->run_task([self::page([self::change('wamid.DE-OTRO', 'delivered')], 'CURSOR-1')]);

        $this->assertSame('CURSOR-1', get_config('message_whatsapp', sync_status::CURSOR_SETTING));
    }

    /**
     * A status that is not ahead of the one the row carries does not walk it backwards, which is what makes
     * applying a page twice harmless.
     *
     * @return void
     */
    public function test_a_stale_status_does_not_walk_a_row_backwards(): void {
        $id = $this->sent_row('wamid.UNO', queue::STATUS_READ);

        $this->run_task([self::page([self::change('wamid.UNO', 'sent')], 'CURSOR-1')]);

        $this->assertSame(queue::STATUS_READ, $this->reload($id)->status);
    }

    /**
     * The task has a name for the scheduled tasks page.
     *
     * @return void
     */
    public function test_get_name(): void {
        $this->assertNotEmpty((new sync_status())->get_name());
    }
}
