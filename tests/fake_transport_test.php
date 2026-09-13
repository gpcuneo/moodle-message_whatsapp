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
 * Tests for the in memory transport of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_output_whatsapp;
use message_whatsapp\transport\factory;
use message_whatsapp\transport\fake;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');

/**
 * Tests for the in memory transport and for the flag that puts it in place of a real one.
 *
 * What is worth testing here is not that a double returns what it was written to return. It is the **wiring**: that
 * the class carries the exact name the factory looks for, that the flag reaches it through the real factory rather
 * than through a factory a test bent into shape, and that the flag wins over a configured mode. That path -- the
 * one Behat and a site trying the plugin out both take -- had no test until this class existed, because until this
 * class existed the factory ignored the flag on purpose.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\transport\fake
 */
final class fake_transport_test extends \advanced_testcase {
    /**
     * Every test starts with an empty record of sends: a static outlives the reset PHPUnit does between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        fake::reset();
    }

    /**
     * Leaves nothing of this test in the static for whatever runs next.
     *
     * @return void
     */
    protected function tearDown(): void {
        fake::reset();
        parent::tearDown();
    }

    /**
     * The class has the name the factory looks for, and is built the way the factory builds a transport.
     *
     * The factory names this class in a string and instantiates it with no arguments. A rename or a constructor
     * that started asking for something would leave the flag silently ignored, which is the one failure mode this
     * transport cannot have: a site that asked not to touch the network would touch it.
     */
    public function test_the_class_has_the_name_and_the_shape_the_factory_expects(): void {
        $method = new \ReflectionMethod(factory::class, 'fake_class');
        $method->setAccessible(true);

        $this->assertSame(fake::class, $method->invoke(null));
        $this->assertSame('message_whatsapp\\transport\\fake', fake::class);
        $this->assertSame(0, (new \ReflectionClass(fake::class))->getConstructor()->getNumberOfRequiredParameters());
        $this->assertInstanceOf(transport\transport_interface::class, new fake());
        $this->assertSame('fake', (new fake())->name());
    }

    /**
     * With the flag set, the real factory hands out the in memory transport instead of the configured one.
     */
    public function test_the_real_factory_builds_it_when_the_flag_is_set(): void {
        global $CFG;

        set_config('mode', message_output_whatsapp::MODE_DIRECT, 'message_whatsapp');

        $this->assertNotInstanceOf(fake::class, factory::instance());

        $CFG->message_whatsapp_fake_transport = true;

        $this->assertInstanceOf(fake::class, factory::instance());
        $this->assertSame(fake::NAME, factory::instance()->name());
    }

    /**
     * The flag also replaces a site with no mode at all, which is what a fresh site trying the plugin out is.
     */
    public function test_the_flag_replaces_the_refusal_of_an_unconfigured_site(): void {
        global $CFG;

        $this->assertFalse(factory::instance()->check()->ok);

        $CFG->message_whatsapp_fake_transport = true;

        $this->assertTrue(factory::instance()->check()->ok);
    }

    /**
     * A check succeeds and carries no message id, because a check sends nothing.
     */
    public function test_a_check_succeeds_and_sends_nothing(): void {
        $result = (new fake())->check();

        $this->assertTrue($result->ok);
        $this->assertNull($result->providermsgid);
        $this->assertSame('', $result->code);
        $this->assertSame([], fake::sent());
    }

    /**
     * A send is recorded exactly as it arrived and answered with a provider message id of its own.
     */
    public function test_a_send_is_recorded_and_answered_with_a_message_id(): void {
        $transport = new fake();

        $first = $transport->send_template('+5491112345678', 'moodle_notification', 'es_AR', ['a', 'b', 'c'], '7x', '7');
        $second = $transport->send_template('+5491187654321', 'moodle_notification', 'en', ['d', 'e', 'f'], null, '8');

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok);
        $this->assertNotSame($first->providermsgid, $second->providermsgid);
        $this->assertStringStartsWith(fake::MSGID_PREFIX, (string) $first->providermsgid);

        $sent = fake::sent();

        $this->assertCount(2, $sent);
        $this->assertSame([
            'phone' => '+5491112345678',
            'template' => 'moodle_notification',
            'lang' => 'es_AR',
            'params' => ['a', 'b', 'c'],
            'urlsuffix' => '7x',
            'idempotencykey' => '7',
        ], $sent[0]);
        $this->assertSame('en', $sent[1]['lang']);
        $this->assertNull($sent[1]['urlsuffix']);
    }

    /**
     * Set to `fail`, every answer is a permanent failure and nothing is recorded as sent.
     *
     * The error path of the administration screen is the one that is easy to get wrong and hard to look at, and
     * this is what lets it be looked at without breaking a real account on purpose.
     */
    public function test_the_fail_value_refuses_everything(): void {
        global $CFG;

        $CFG->message_whatsapp_fake_transport = fake::FAIL;
        $transport = new fake();

        $check = $transport->check();
        $send = $transport->send_template('+5491112345678', 'moodle_notification', 'es_AR', ['a'], null, '1');

        foreach ([$check, $send] as $result) {
            $this->assertFalse($result->ok);
            $this->assertTrue($result->permanent);
            $this->assertFalse($result->is_retryable());
            $this->assertSame(fake::CODE_ASKED_TO_FAIL, $result->code);
        }

        $this->assertSame([], fake::sent());
    }

    /**
     * Anything else the flag is set to is a request for the transport that works.
     */
    public function test_any_other_value_of_the_flag_is_a_working_transport(): void {
        global $CFG;

        $CFG->message_whatsapp_fake_transport = 1;

        $this->assertTrue((new fake())->check()->ok);

        $CFG->message_whatsapp_fake_transport = 'yes';

        $this->assertTrue((new fake())->check()->ok);
    }

    /**
     * The record of what was sent can be emptied, which is the only way a static can be trusted between tests.
     */
    public function test_the_record_of_sends_can_be_emptied(): void {
        (new fake())->send_template('+5491112345678', 'moodle_notification', 'es_AR', ['a'], null, '1');

        $this->assertCount(1, fake::sent());

        fake::reset();

        $this->assertSame([], fake::sent());
    }
}
