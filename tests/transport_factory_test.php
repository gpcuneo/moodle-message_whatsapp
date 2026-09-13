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
 * Tests for the transport factory, the result and the transport interface of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_output_whatsapp;
use message_whatsapp\transport\factory;
use message_whatsapp\transport\result;
use message_whatsapp\transport\transport_interface;
use message_whatsapp\transport\unconfigured;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');

/**
 * Tests for the transport factory, the result and the transport interface of the WhatsApp message processor.
 *
 * No transport of production code exists yet: T2.2 of the plan writes `meta_cloud` and T4.4 writes `gateway`. The
 * tests that need something instantiable build an anonymous double and a factory whose registry points at it,
 * rather than a class under `classes/transport/` that would compete with the in memory transport of T2.5. That
 * also keeps these tests describing the factory instead of describing which transports happen to exist, so none of
 * them has to be rewritten when either of the two lands.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\transport\factory
 * @covers     \message_whatsapp\transport\result
 * @covers     \message_whatsapp\transport\unconfigured
 */
final class transport_factory_test extends \advanced_testcase {
    /** Name the transport double of these tests reports. Public: the double itself reads it. */
    public const DOUBLE_NAME = 'test_double';

    /** Name the second double, the one the fake transport flag points at, reports. */
    public const FAKE_DOUBLE_NAME = 'test_fake_double';

    /** A class name that is not, and will not be, a transport of this plugin. */
    private const MISSING = 'message_whatsapp\\transport\\not_written_yet';

    /**
     * Every test starts from a site with no leftovers of the previous one.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Builds a transport that sends nothing and says it worked.
     *
     * Anonymous so that it stays inside this file: the factory only ever needs a class name, which
     * {@see self::factory_with()} takes from the instance.
     *
     * @return transport_interface A transport that succeeds at everything.
     */
    private function sending_double(): transport_interface {
        return new class implements transport_interface {
            /**
             * Pretends the template left.
             *
             * @param string $phone Destination in E.164.
             * @param string $template Template name.
             * @param string $lang Template language.
             * @param string[] $params Body parameters.
             * @param string|null $urlsuffix Suffix of the URL button.
             * @param string $idempotencykey Stable identifier of this attempt.
             * @return result Always a success.
             */
            public function send_template(
                string $phone,
                string $template,
                string $lang,
                array $params,
                ?string $urlsuffix,
                string $idempotencykey
            ): result {
                return result::success('wamid.DOUBLE');
            }

            /**
             * Pretends the connection is usable.
             *
             * @return result Always a success.
             */
            public function check(): result {
                return result::success();
            }

            /**
             * Returns the machine name of this double.
             *
             * @return string The name of the sending double.
             */
            public function name(): string {
                return transport_factory_test::DOUBLE_NAME;
            }
        };
    }

    /**
     * Builds a second transport double, told apart from the first only by its name.
     *
     * It is a separate declaration and not a second instance of the first one because the factory is asked for a
     * class name, and two instances of one anonymous class share theirs.
     *
     * @return transport_interface A transport that succeeds at everything.
     */
    private function fake_double(): transport_interface {
        return new class implements transport_interface {
            /**
             * Pretends the template left.
             *
             * @param string $phone Destination in E.164.
             * @param string $template Template name.
             * @param string $lang Template language.
             * @param string[] $params Body parameters.
             * @param string|null $urlsuffix Suffix of the URL button.
             * @param string $idempotencykey Stable identifier of this attempt.
             * @return result Always a success.
             */
            public function send_template(
                string $phone,
                string $template,
                string $lang,
                array $params,
                ?string $urlsuffix,
                string $idempotencykey
            ): result {
                return result::success('wamid.FAKE');
            }

            /**
             * Pretends the connection is usable.
             *
             * @return result Always a success.
             */
            public function check(): result {
                return result::success();
            }

            /**
             * Returns the machine name of this double.
             *
             * @return string The name of the fake double.
             */
            public function name(): string {
                return transport_factory_test::FAKE_DOUBLE_NAME;
            }
        };
    }

    /**
     * Builds a factory whose registry and fake transport are the ones this test wants.
     *
     * This is the seam the factory leaves open: a transport is added by naming its class in the registry, and a
     * test gets one by overriding the registry rather than by adding a class to production code.
     *
     * @param array<string, string> $registry Mode mapped to the class that implements it.
     * @param string $fakeclass Class the fake transport flag has to point at.
     * @return factory A factory that answers with that registry.
     */
    private function factory_with(array $registry, string $fakeclass = self::MISSING): factory {
        $stub = new class extends factory {
            /** @var array<string, string> Registry this factory answers with. */
            public static array $map = [];

            /** @var string Class the fake transport flag points at. */
            public static string $fake = '';

            /**
             * Public on purpose: the parent hides its constructor, and this one has to be built to be used.
             */
            public function __construct() {
            }

            /**
             * Returns the registry of the test.
             *
             * @return array<string, string> Mode mapped to the class that implements it.
             */
            protected static function registry(): array {
                return static::$map;
            }

            /**
             * Returns the class the fake transport flag points at during the test.
             *
             * @return string Fully qualified class name.
             */
            protected static function fake_class(): string {
                return static::$fake;
            }
        };

        $stub::$map = $registry;
        $stub::$fake = $fakeclass;

        return $stub;
    }

    /**
     * Asserts that a result is a refusal with the expected code and nothing that looks like a success.
     *
     * @param result $result Result to check.
     * @param string $code Code the refusal is expected to carry.
     * @return void
     */
    private function assert_permanent_refusal(result $result, string $code): void {
        $this->assertFalse($result->ok);
        $this->assertTrue($result->permanent);
        $this->assertFalse($result->is_retryable());
        $this->assertNull($result->providermsgid);
        $this->assertSame($code, $result->code);
        $this->assertNotSame('', $result->message);
    }

    /**
     * The interface carries the signature the architecture fixes in section 3.7.
     *
     * Four pieces of code written apart from each other implement or call this interface, so its shape is pinned
     * here instead of being left to whoever writes the next one.
     */
    public function test_the_interface_has_the_signature_of_the_architecture(): void {
        $reflection = new \ReflectionClass(transport_interface::class);

        $this->assertTrue($reflection->isInterface());
        $this->assertSame(
            ['send_template', 'check', 'name'],
            array_map(fn($method) => $method->getName(), $reflection->getMethods())
        );

        $send = $reflection->getMethod('send_template');
        $this->assertSame(result::class, (string) $send->getReturnType());
        $this->assertSame(
            [
                'string $phone',
                'string $template',
                'string $lang',
                'array $params',
                '?string $urlsuffix',
                'string $idempotencykey',
            ],
            array_map(fn($p) => $p->getType() . ' $' . $p->getName(), $send->getParameters())
        );

        $this->assertSame(result::class, (string) $reflection->getMethod('check')->getReturnType());
        $this->assertSame('string', (string) $reflection->getMethod('name')->getReturnType());
    }

    /**
     * A site that never chose a sending mode gets a transport that refuses, not a null and not an exception.
     *
     * This is the state of every site between installing the plugin and configuring it, and the state the test
     * connection button of the settings page is pressed in most often.
     */
    public function test_a_site_with_no_mode_gets_a_transport_that_refuses(): void {
        $transport = factory::instance();

        $this->assertInstanceOf(transport_interface::class, $transport);
        $this->assertInstanceOf(unconfigured::class, $transport);
        $this->assertSame(unconfigured::NAME, $transport->name());
        $this->assert_permanent_refusal($transport->check(), factory::CODE_NOT_CONFIGURED);
        $this->assert_permanent_refusal(
            $transport->send_template('+5491141234567', 'moodle_notification', 'es_AR', ['a', 'b', 'c'], null, '1'),
            factory::CODE_NOT_CONFIGURED
        );
    }

    /**
     * A setting that holds something nobody wrote a transport for is a different problem, and says so.
     *
     * It separates "you have not finished setting this up" from "what is stored here cannot be right": two
     * different sentences for the administrator and two different things to do about them.
     */
    public function test_an_unknown_mode_gets_a_transport_that_refuses(): void {
        set_config('mode', 'carrier_pigeon', 'message_whatsapp');

        $transport = factory::instance();

        $this->assertInstanceOf(unconfigured::class, $transport);
        $this->assert_permanent_refusal($transport->check(), factory::CODE_UNKNOWN_MODE);
        $this->assertStringContainsString('carrier_pigeon', $transport->check()->message);
    }

    /**
     * The unknown mode is printed as an identifier or not at all, never as whatever the column happened to hold.
     *
     * The message ends up in the error column of the queue and on an administration screen.
     */
    public function test_an_unknown_mode_is_not_echoed_back_raw(): void {
        set_config('mode', "<script>alert(1)</script>\n", 'message_whatsapp');

        $message = factory::instance()->check()->message;

        $this->assertStringNotContainsString('<', $message);
        $this->assertStringNotContainsString("\n", $message);
    }

    /**
     * A mode of nothing but whitespace is as unconfigured as an empty one.
     */
    public function test_a_blank_mode_counts_as_unconfigured(): void {
        set_config('mode', '   ', 'message_whatsapp');

        $this->assert_permanent_refusal(factory::instance()->check(), factory::CODE_NOT_CONFIGURED);
    }

    /**
     * Both documented modes resolve to the class the architecture names for them, written or not.
     *
     * This is the promise the later tasks rely on: dropping `meta_cloud.php` or `gateway.php` into
     * `classes/transport/` is the whole of the wiring. If either is renamed, it fails here rather than as a site
     * that quietly stops sending.
     */
    public function test_every_documented_mode_resolves_to_its_class(): void {
        $this->assertSame(
            'message_whatsapp\\transport\\meta_cloud',
            factory::class_for_mode(message_output_whatsapp::MODE_DIRECT)
        );
        $this->assertSame(
            'message_whatsapp\\transport\\gateway',
            factory::class_for_mode(message_output_whatsapp::MODE_GATEWAY)
        );
    }

    /**
     * A mode nobody registered has no class, and that is said with a null instead of a guess.
     */
    public function test_an_unknown_mode_resolves_to_no_class(): void {
        $this->assertNull(factory::class_for_mode('carrier_pigeon'));
        $this->assertNull(factory::class_for_mode(''));
    }

    /**
     * A registered mode whose class is present is built and handed back.
     */
    public function test_a_registered_mode_is_instantiated(): void {
        set_config('mode', message_output_whatsapp::MODE_DIRECT, 'message_whatsapp');

        $stub = $this->factory_with([
            message_output_whatsapp::MODE_DIRECT => get_class($this->sending_double()),
            message_output_whatsapp::MODE_GATEWAY => self::MISSING,
        ]);

        $transport = $stub::instance();

        $this->assertInstanceOf(transport_interface::class, $transport);
        $this->assertSame(self::DOUBLE_NAME, $transport->name());
        $this->assertTrue($transport->check()->ok);
        $this->assertSame(
            'wamid.DOUBLE',
            $transport->send_template('+5491141234567', 'moodle_notification', 'es_AR', ['a'], null, '1')->providermsgid
        );
    }

    /**
     * A known mode whose class is not in this installation refuses instead of ending the request.
     *
     * A partial upgrade, or a file that never shipped, would otherwise be a fatal error inside a scheduled task,
     * where the only trace is a cron log nobody reads.
     */
    public function test_a_mode_whose_class_is_missing_refuses_instead_of_failing(): void {
        set_config('mode', message_output_whatsapp::MODE_GATEWAY, 'message_whatsapp');

        $stub = $this->factory_with([
            message_output_whatsapp::MODE_DIRECT => get_class($this->sending_double()),
            message_output_whatsapp::MODE_GATEWAY => self::MISSING,
        ]);

        $transport = $stub::instance();

        $this->assertInstanceOf(unconfigured::class, $transport);
        $this->assert_permanent_refusal($transport->check(), factory::CODE_NOT_AVAILABLE);
    }

    /**
     * An explicitly requested mode wins over the stored one.
     *
     * The settings page has to be able to test a mode the administrator picked but has not saved yet.
     */
    public function test_an_explicit_mode_wins_over_the_setting(): void {
        set_config('mode', message_output_whatsapp::MODE_DIRECT, 'message_whatsapp');

        $stub = $this->factory_with([
            message_output_whatsapp::MODE_DIRECT => get_class($this->sending_double()),
            message_output_whatsapp::MODE_GATEWAY => self::MISSING,
        ]);

        $this->assert_permanent_refusal(
            $stub::instance(message_output_whatsapp::MODE_GATEWAY)->check(),
            factory::CODE_NOT_AVAILABLE
        );
    }

    /**
     * The fake transport flag replaces whatever the site has configured.
     *
     * A site that sets `$CFG->message_whatsapp_fake_transport` is asking not to touch the network at all, which is
     * exactly what Behat and a manual try out need, so the flag is read before the mode and not after it.
     */
    public function test_the_fake_transport_flag_wins_over_the_mode(): void {
        global $CFG;

        set_config('mode', message_output_whatsapp::MODE_DIRECT, 'message_whatsapp');
        $stub = $this->factory_with(
            [message_output_whatsapp::MODE_DIRECT => get_class($this->sending_double())],
            get_class($this->fake_double())
        );

        $this->assertSame(self::DOUBLE_NAME, $stub::instance()->name());

        $CFG->message_whatsapp_fake_transport = true;

        $this->assertTrue(factory::fake_transport_requested());
        $this->assertSame(self::FAKE_DOUBLE_NAME, $stub::instance()->name());
    }

    /**
     * The fake transport flag builds the class it names, which T2.5 of the plan wrote.
     *
     * This test pinned the name while that class was missing, and asserted that the flag was ignored rather than
     * fatal until it arrived. It now pins the other half of the same contract: the name is still the one the
     * factory looks for, and the class behind it is there, so a config.php that sets the flag gets an in memory
     * transport instead of a refusal. What the transport itself does is tested in `fake_transport_test`.
     */
    public function test_the_fake_transport_flag_builds_the_class_it_names(): void {
        global $CFG;

        $method = new \ReflectionMethod(factory::class, 'fake_class');
        $method->setAccessible(true);

        $this->assertSame('message_whatsapp\\transport\\fake', $method->invoke(null));
        $this->assertTrue(class_exists('message_whatsapp\\transport\\fake'));

        $CFG->message_whatsapp_fake_transport = true;

        $this->assertInstanceOf('message_whatsapp\\transport\\fake', factory::instance());
    }

    /**
     * Without the flag the factory does not look for the fake transport at all.
     */
    public function test_the_fake_transport_flag_is_off_by_default(): void {
        $this->assertFalse(factory::fake_transport_requested());
    }

    /**
     * A success carries the provider message id and is never permanent and never retryable.
     */
    public function test_a_success_carries_the_message_id(): void {
        $result = result::success('wamid.HBgNNTQ5MTE0MTIzNDU2Nw');

        $this->assertTrue($result->ok);
        $this->assertFalse($result->permanent);
        $this->assertFalse($result->is_retryable());
        $this->assertSame('wamid.HBgNNTQ5MTE0MTIzNDU2Nw', $result->providermsgid);
        $this->assertSame('', $result->code);
        $this->assertSame('', $result->message);
    }

    /**
     * A check that passed is a success with no message id, because it sent nothing.
     */
    public function test_a_success_without_a_message_id(): void {
        foreach ([result::success(), result::success(''), result::success('   ')] as $result) {
            $this->assertTrue($result->ok);
            $this->assertNull($result->providermsgid);
        }
    }

    /**
     * A permanent failure is written off at once; a transient one goes back into the queue.
     *
     * This is the whole reason the result exists, so both halves of the decision are pinned here.
     */
    public function test_the_two_kinds_of_failure_differ_in_exactly_one_answer(): void {
        $permanent = result::permanent_failure('invalid_phone', 'The number is not a WhatsApp account.');
        $transient = result::transient_failure('meta_error', 'Graph API answered 503.');

        $this->assertFalse($permanent->ok);
        $this->assertTrue($permanent->permanent);
        $this->assertFalse($permanent->is_retryable());
        $this->assertSame('invalid_phone', $permanent->code);
        $this->assertSame('The number is not a WhatsApp account.', $permanent->message);
        $this->assertNull($permanent->providermsgid);

        $this->assertFalse($transient->ok);
        $this->assertFalse($transient->permanent);
        $this->assertTrue($transient->is_retryable());
        $this->assertSame('meta_error', $transient->code);
        $this->assertNull($transient->providermsgid);
    }

    /**
     * A failure always has a code, even when the transport did not supply one.
     *
     * A codeless failure cannot be branched on, translated or counted, and the moment one appears is a moment the
     * plugin is already dealing with a problem, so it is named rather than rejected.
     */
    public function test_a_failure_is_never_codeless(): void {
        $this->assertSame(result::CODE_UNKNOWN, result::permanent_failure('', 'No code.')->code);
        $this->assertSame(result::CODE_UNKNOWN, result::transient_failure('   ', 'No code.')->code);
    }

    /**
     * The result cannot be edited after it is built: the queue reads the decision the transport made.
     */
    public function test_a_result_is_immutable(): void {
        $result = result::success('wamid.TEST');

        $this->expectException(\Error::class);
        $result->ok = false;
    }

    /**
     * Only three ways of building a result exist, and none of them is a constructor call.
     */
    public function test_a_result_cannot_be_built_by_hand(): void {
        $constructor = (new \ReflectionClass(result::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    /**
     * The refusing transport answers the same thing to everything it is asked.
     */
    public function test_the_refusing_transport_answers_the_same_to_every_method(): void {
        $transport = unconfigured::because('some_code', 'Some reason.');

        $sent = $transport->send_template('+5491141234567', 'moodle_notification', 'es_AR', ['a', 'b', 'c'], 'x', '1');

        $this->assertSame('some_code', $sent->code);
        $this->assertSame('Some reason.', $sent->message);
        $this->assertFalse($sent->is_retryable());
        $this->assertSame($sent->code, $transport->check()->code);
        $this->assertSame(unconfigured::NAME, $transport->name());
    }
}
