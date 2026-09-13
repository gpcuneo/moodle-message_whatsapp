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
 * The transport that sends nothing and remembers everything, for Behat and for trying the plugin out.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\transport;

/**
 * An in memory transport: it answers like a provider that is working, and it never opens a socket.
 *
 * This is what `$CFG->message_whatsapp_fake_transport = true` in `config.php` puts in place of the real one.
 * {@see factory::instance()} honours that flag before it looks at the mode, so a site that sets it cannot reach
 * the network through this plugin whatever else it has configured. Two situations need exactly that:
 *
 * - **Behat.** The acceptance test of the administration screen is that a site configured in direct mode reports a
 *   working connection. Doing that against Meta would need credentials in the repository, an internet connection
 *   on the machine running the suite, and a test that goes red when a third party has a bad afternoon.
 * - **Trying the plugin out.** An administrator evaluating the plugin can turn the whole channel on, watch rows
 *   move through the queue and see the administration screens behave, before asking anybody for a Meta account.
 *
 * **It is not a mock and the unit tests do not use it.** The tests of the sending task and of the factory build
 * their own doubles inside their own files, which is what keeps a double shaped to one test out of production
 * code. What this class is for is the two cases above, where the code under test is the *site*: the settings page,
 * the queue and the scheduled task, all of them real, with only the last inch to the provider replaced.
 *
 * The flag can also be set to the string `fail`, and then every answer is a permanent failure. The successful path
 * is not the only one an administration screen has to render, and the error path is the one that is easy to get
 * wrong and hard to see: it is worth being able to look at it without breaking a real account on purpose.
 *
 * What was sent is kept in a static for the length of the request, so that a test can assert on it without a
 * database table. Statics outlive a test method, which is why {@see self::reset()} exists and why every test that
 * reads {@see self::sent()} starts by calling it.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake implements transport_interface {
    /** Machine name reported by {@see self::name()}. Stable: log lines, screens and tests compare it. */
    public const NAME = 'fake';

    /** Value of the flag that turns every answer of this transport into a failure. */
    public const FAIL = 'fail';

    /** Code of the failure this transport reports when it was asked to fail. */
    public const CODE_ASKED_TO_FAIL = 'fake_failure';

    /** Prefix of the provider message ids handed out by this transport, so they are recognisable in a queue row. */
    public const MSGID_PREFIX = 'fake.wamid.';

    /** @var array<int, array<string, mixed>> Everything sent through this transport in this request, oldest first. */
    private static array $sent = [];

    /**
     * Built with no arguments, because that is how {@see factory::instance()} builds a transport.
     */
    public function __construct() {
    }

    /**
     * Pretends to send one template, records what it was asked to send, and answers like a provider would.
     *
     * The parameters are stored exactly as they arrived, including the phone number: this object lives in memory
     * for the length of one request and is never written anywhere, which is the difference between a test double
     * holding personal data and a log file holding it.
     *
     * @param string $phone Destination in E.164.
     * @param string $template Name of the approved template.
     * @param string $lang Language of the approved version of the template.
     * @param string[] $params Body parameters in template order.
     * @param string|null $urlsuffix Dynamic suffix of the URL button, or null when there is none.
     * @param string $idempotencykey Stable identifier of this attempt.
     * @return result A success carrying a made up provider message id, or the refusal the flag asked for.
     */
    public function send_template(
        string $phone,
        string $template,
        string $lang,
        array $params,
        ?string $urlsuffix,
        string $idempotencykey
    ): result {
        if (self::failure_requested()) {
            return result::permanent_failure(
                self::CODE_ASKED_TO_FAIL,
                'The in memory transport was configured to refuse everything.'
            );
        }

        self::$sent[] = [
            'phone' => $phone,
            'template' => $template,
            'lang' => $lang,
            'params' => $params,
            'urlsuffix' => $urlsuffix,
            'idempotencykey' => $idempotencykey,
        ];

        return result::success(self::MSGID_PREFIX . count(self::$sent));
    }

    /**
     * Answers the test connection button without touching the network.
     *
     * @return result A success, or the refusal the flag asked for.
     */
    public function check(): result {
        if (self::failure_requested()) {
            return result::permanent_failure(
                self::CODE_ASKED_TO_FAIL,
                'The in memory transport was configured to refuse everything.'
            );
        }

        return result::success();
    }

    /**
     * Returns the machine name of this transport.
     *
     * It is deliberately not disguised as `meta_cloud`. The administration screen prints it and warns when it is
     * this one, because a connection that reports itself as working has to be distinguishable from one that is.
     *
     * @return string Always `fake`.
     */
    public function name(): string {
        return self::NAME;
    }

    /**
     * Returns everything sent through this transport in this request.
     *
     * @return array<int, array<string, mixed>> One entry per send, oldest first, with the arguments it was given.
     */
    public static function sent(): array {
        return self::$sent;
    }

    /**
     * Forgets everything sent through this transport.
     *
     * A static survives the end of a test method and the reset of the database that PHPUnit does between them, so
     * a test that reads {@see self::sent()} has to start from a known state explicitly.
     *
     * @return void
     */
    public static function reset(): void {
        self::$sent = [];
    }

    /**
     * Whether the site asked this transport to refuse everything instead of accepting it.
     *
     * @return bool True when `$CFG->message_whatsapp_fake_transport` is the string `fail`.
     */
    private static function failure_requested(): bool {
        global $CFG;

        return (string) ($CFG->message_whatsapp_fake_transport ?? '') === self::FAIL;
    }
}
