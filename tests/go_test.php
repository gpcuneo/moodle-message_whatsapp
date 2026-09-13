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
 * Tests for the redirection the button of a WhatsApp notification points at.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\queue;
use message_whatsapp\local\template_mapper;

/**
 * Tests for the redirection the button of a WhatsApp notification points at.
 *
 * Two things are being pinned down here. That the link cannot be forged or edited to name another row, and that the
 * endpoint cannot be talked into redirecting anywhere outside the site: the destination is read from the queue row,
 * never from the request, and it is checked against `wwwroot` on the way out.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::message_whatsapp_go_target
 * @covers     ::message_whatsapp_go_safe_url
 * @covers     \message_whatsapp\local\queue::click_token
 * @covers     \message_whatsapp\local\queue::queueid_from_click_token
 */
final class go_test extends \advanced_testcase {
    /** @var string User agent used by the tests that record a click. */
    private const AGENT = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36';

    /**
     * Every test starts from a clean site with the endpoint loaded.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        require_once(__DIR__ . '/../go.php');
    }

    /**
     * A token made by this site names the row it was made for.
     *
     * @return void
     */
    public function test_a_token_round_trips(): void {
        $id = $this->queue_row();

        $this->assertSame($id, queue::queueid_from_click_token(queue::click_token($id)));
    }

    /**
     * A token whose signature was touched names nothing.
     *
     * @return void
     */
    public function test_a_tampered_signature_names_nothing(): void {
        $id = $this->queue_row();
        $token = queue::click_token($id);

        $this->assertSame(0, queue::queueid_from_click_token(substr($token, 0, -1) . 'f'));
        $this->assertSame(0, queue::queueid_from_click_token($id . '.' . str_repeat('0', 32)));
    }

    /**
     * The id cannot be swapped for another one while keeping the signature, which is the point of signing it.
     *
     * @return void
     */
    public function test_the_id_cannot_be_swapped(): void {
        $mine = $this->queue_row();
        $other = $this->queue_row();
        $signature = explode('.', queue::click_token($mine))[1];

        $this->assertSame(0, queue::queueid_from_click_token($other . '.' . $signature));
    }

    /**
     * Anything that is not a token at all names nothing, and nothing throws on the way.
     *
     * @return void
     */
    public function test_rubbish_names_nothing(): void {
        foreach (['', '.', '7', 'abc.def', '0.' . str_repeat('0', 32), '-1.x', '../../etc/passwd'] as $token) {
            $this->assertSame(0, queue::queueid_from_click_token($token), 'token: ' . $token);
        }
    }

    /**
     * A genuine token sends the reader to the page of the row and leaves a click behind.
     *
     * @return void
     */
    public function test_a_genuine_token_redirects_to_the_page_of_the_row(): void {
        global $CFG, $DB;

        $url = $CFG->wwwroot . '/mod/assign/view.php?id=42';
        $id = $this->queue_row(['url' => $url]);

        $target = message_whatsapp_go_target(queue::click_token($id), self::AGENT);

        $this->assertSame($url, $target->out(false));

        $click = $DB->get_record('message_whatsapp_click', ['queueid' => $id], '*', MUST_EXIST);
        $this->assertSame(self::AGENT, $click->useragent);
        $this->assertGreaterThan(0, (int) $click->timeclicked);
    }

    /**
     * A token that does not verify goes to the front page and is not recorded as a click of anybody.
     *
     * @return void
     */
    public function test_a_forged_token_goes_home_and_records_nothing(): void {
        global $CFG, $DB;

        $id = $this->queue_row(['url' => $CFG->wwwroot . '/mod/assign/view.php?id=42']);

        $target = message_whatsapp_go_target($id . '.' . str_repeat('a', 32), self::AGENT);

        $this->assertSame($CFG->wwwroot . '/', $target->out(false));
        $this->assertSame(0, $DB->count_records('message_whatsapp_click'));
    }

    /**
     * A token for a row that is no longer there goes to the front page and records nothing.
     *
     * @return void
     */
    public function test_a_token_for_a_deleted_row_goes_home(): void {
        global $CFG, $DB;

        $id = $this->queue_row();
        $token = queue::click_token($id);
        $DB->delete_records(queue::TABLE, ['id' => $id]);

        $this->assertSame($CFG->wwwroot . '/', message_whatsapp_go_target($token, self::AGENT)->out(false));
        $this->assertSame(0, $DB->count_records('message_whatsapp_click'));
    }

    /**
     * A destination outside the site is refused, which is what stops this being an open redirect.
     *
     * The click is still recorded: it did happen, and the row it belongs to is known. What is refused is only the
     * destination, and the reader lands on the front page of the site they were told to come to.
     *
     * @return void
     */
    public function test_a_destination_outside_the_site_is_refused(): void {
        global $CFG, $DB;

        $hostile = [
            'https://evil.example.net/phish',
            'https://evil.example.net/?next=' . $CFG->wwwroot . '/',
            $CFG->wwwroot . '.evil.example.net/mod/assign/view.php',
            '//evil.example.net/phish',
            'javascript:alert(1)',
            '',
        ];

        foreach ($hostile as $url) {
            $id = $this->queue_row(['url' => $url]);
            $target = message_whatsapp_go_target(queue::click_token($id), self::AGENT);

            $this->assertSame($CFG->wwwroot . '/', $target->out(false), 'url: ' . $url);
            $this->assertSame(1, $DB->count_records('message_whatsapp_click', ['queueid' => $id]));
        }
    }

    /**
     * The destination check on its own, which is the rule the tests above exercise through a row.
     *
     * @return void
     */
    public function test_only_addresses_inside_the_site_survive(): void {
        global $CFG;

        $inside = $CFG->wwwroot . '/course/view.php?id=2';

        $this->assertSame($inside, message_whatsapp_go_safe_url($inside)->out(false));
        $this->assertSame($CFG->wwwroot . '/', message_whatsapp_go_safe_url($CFG->wwwroot)->out(false));
        $this->assertSame($CFG->wwwroot . '/', message_whatsapp_go_safe_url('http://localhost/x')->out(false));
    }

    /**
     * A user agent longer than the column is cut instead of blowing up the insert.
     *
     * @return void
     */
    public function test_a_long_user_agent_is_cut_to_the_column(): void {
        global $CFG, $DB;

        $id = $this->queue_row(['url' => $CFG->wwwroot . '/my/']);

        message_whatsapp_go_target(queue::click_token($id), str_repeat('x', 400));

        $click = $DB->get_record('message_whatsapp_click', ['queueid' => $id], '*', MUST_EXIST);
        $this->assertSame(255, \core_text::strlen($click->useragent));
    }

    /**
     * A browser that announces no user agent still gets its click recorded.
     *
     * @return void
     */
    public function test_a_click_without_a_user_agent_is_still_recorded(): void {
        global $CFG, $DB;

        $id = $this->queue_row(['url' => $CFG->wwwroot . '/my/']);

        message_whatsapp_go_target(queue::click_token($id), null);

        $click = $DB->get_record('message_whatsapp_click', ['queueid' => $id], '*', MUST_EXIST);
        $this->assertNull($click->useragent);
    }

    /**
     * Every click is a row of its own, because two people opening the same link is two facts.
     *
     * @return void
     */
    public function test_two_clicks_leave_two_rows(): void {
        global $CFG, $DB;

        $id = $this->queue_row(['url' => $CFG->wwwroot . '/my/']);
        $token = queue::click_token($id);

        message_whatsapp_go_target($token, self::AGENT);
        message_whatsapp_go_target($token, self::AGENT);

        $this->assertSame(2, $DB->count_records('message_whatsapp_click', ['queueid' => $id]));
    }

    /**
     * The endpoint reads its parameter and redirects, which under PHPUnit is a redirect that refuses to happen.
     *
     * @return void
     */
    public function test_the_endpoint_reads_its_parameter_and_redirects(): void {
        global $CFG, $DB;

        $id = $this->queue_row(['url' => $CFG->wwwroot . '/my/']);
        $_GET['t'] = queue::click_token($id);
        $_POST = [];

        try {
            message_whatsapp_go_handle_request();
            $this->fail('redirect() should have thrown under PHPUnit');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode);
        } finally {
            unset($_GET['t']);
        }

        $this->assertSame(1, $DB->count_records('message_whatsapp_click', ['queueid' => $id]));
    }

    /**
     * Writes a queue row that has already been sent, which is the only kind whose button can be clicked.
     *
     * @param array $overrides Fields to change.
     * @return int Id of the row.
     */
    private function queue_row(array $overrides = []): int {
        global $CFG, $DB;

        $now = time();
        $user = $this->getDataGenerator()->create_user();

        return (int) $DB->insert_record(queue::TABLE, (object) ($overrides + [
            'userid' => $user->id,
            'savedmessageid' => null,
            'component' => 'mod_assign',
            'name' => 'assign_notification',
            'courseid' => 0,
            'phone' => '+5491122334444',
            'templatekey' => template_mapper::TEMPLATE,
            'lang' => 'es_AR',
            'params' => '["Demo","Tarea 1 calificada","Tu entrega fue calificada."]',
            'url' => $CFG->wwwroot . '/mod/assign/view.php?id=1',
            'status' => queue::STATUS_SENT,
            'attempts' => 1,
            'nextattempt' => 0,
            'providermsgid' => 'wamid.HBgLNTQ5MTEyMjMzNDQ0FQIAERgSQTM1RDA5RDk1QzJDMUE5NkE2AA==',
            'error' => null,
            'pricingcategory' => 'utility',
            'timecreated' => $now,
            'timesent' => $now,
            'timestatus' => $now,
        ]));
    }
}
