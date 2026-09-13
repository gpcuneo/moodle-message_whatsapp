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
 * Chooses the transport the site is configured to use.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\transport;

use message_output_whatsapp;

/**
 * The one place that turns the `mode` setting into an object that can send.
 *
 * The plugin has exactly two sending modes and they are chosen once, by the administrator, in a select. Everything
 * that sends -- the scheduled task today, the test connection button and the test message of T2.5 tomorrow -- asks
 * here instead of reading the setting and branching on it, so that the mapping from mode to class is written down
 * once and the callers never grow a copy of it.
 *
 * **It always returns a transport.** It never returns null and it never throws, whatever the configuration says.
 * The two callers cannot afford either: `send_template()` is called from a scheduled task that is draining a queue
 * and must survive one bad row, and `check()` is called from an administration page whose whole purpose is to
 * print what is wrong with the configuration. When there is nothing to build, what comes back is
 * {@see unconfigured}, a transport that sends nothing and answers the reason. See that class for why a null object
 * beats both a null return and an exception here.
 *
 * **Adding a transport does not change this file beyond one line of {@see self::registry()}.** The registry maps a
 * mode to a class name and the class is instantiated only if it exists, so the two classes that the plan has not
 * written yet -- `meta_cloud` in T2.2 and `gateway` in T4.4 -- are already named here: dropping either file into
 * `classes/transport/` is the whole of the wiring. Until then the mode resolves to
 * {@see self::CODE_NOT_AVAILABLE}, which is the truth about an installation that has the mode but not the code.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class factory {
    /** No sending mode is stored in the configuration: the administrator has not finished setting the plugin up. */
    public const CODE_NOT_CONFIGURED = 'not_configured';

    /** The stored mode is not one this plugin knows. A hand edited config, or a downgrade of the plugin. */
    public const CODE_UNKNOWN_MODE = 'unknown_mode';

    /** The mode is known but its class is not in this installation. A partial upgrade, or a file that never shipped. */
    public const CODE_NOT_AVAILABLE = 'not_available';

    /**
     * Never instantiated: the factory is a function with a name, not an object with a life cycle.
     */
    private function __construct() {
    }

    /**
     * Returns the transport the site is configured to use.
     *
     * @param string|null $mode Mode to build, or null to read the `mode` setting. The explicit argument is for the
     *      settings page, which has to be able to test a mode the administrator has typed but not saved yet.
     * @return transport_interface Always a transport. When none can be built, one that refuses and says why.
     */
    public static function instance(?string $mode = null): transport_interface {
        $fake = static::fake_class();
        if (static::fake_transport_requested() && class_exists($fake)) {
            return new $fake();
        }

        $mode = trim($mode ?? (string) get_config('message_whatsapp', 'mode'));

        if ($mode === '') {
            return unconfigured::because(
                self::CODE_NOT_CONFIGURED,
                'The WhatsApp message processor has no sending mode configured.'
            );
        }

        $class = static::class_for_mode($mode);

        if ($class === null) {
            return unconfigured::because(
                self::CODE_UNKNOWN_MODE,
                'The configured sending mode "' . self::describe($mode) . '" is not one this plugin knows.'
            );
        }

        if (!class_exists($class)) {
            return unconfigured::because(
                self::CODE_NOT_AVAILABLE,
                'The transport of the "' . self::describe($mode) . '" sending mode is not installed on this site.'
            );
        }

        return new $class();
    }

    /**
     * Returns the class that implements a sending mode, without building it.
     *
     * Separate from {@see self::instance()} so that the mapping can be asked about without the side effect of
     * loading and instantiating a transport: the settings page lists the modes, and the tests pin the mapping.
     *
     * @param string $mode One of the mode constants of `message_output_whatsapp`.
     * @return string|null Fully qualified class name, or null when the mode is not one this plugin knows.
     */
    public static function class_for_mode(string $mode): ?string {
        return static::registry()[trim($mode)] ?? null;
    }

    /**
     * Returns every sending mode this plugin can build, mapped to the class that implements it.
     *
     * This is the seam of the factory. A third mode is one line here; a test that needs a transport it can inspect
     * overrides this method instead of adding a class to production code. The classes are named whether or not
     * they exist yet, because naming them is what makes the later task a file drop.
     *
     * @return array<string, class-string> Mode as stored in the setting, mapped to the class that implements it.
     */
    protected static function registry(): array {
        self::load_processor();

        return [
            message_output_whatsapp::MODE_DIRECT => meta_cloud::class,
            message_output_whatsapp::MODE_GATEWAY => gateway::class,
        ];
    }

    /**
     * Returns the class the fake transport flag asks for.
     *
     * `$CFG->message_whatsapp_fake_transport = true` in `config.php` replaces the transport with an in memory one,
     * for Behat and for trying the plugin out without credentials. The flag is honoured here, which is the only
     * place where it can be honoured once for every caller, and it wins over the mode: a site that asks for the
     * fake transport is asking for it whatever it has configured.
     *
     * The class itself belongs to T2.5 of the plan and does not exist yet, so the flag is ignored while it is
     * missing rather than leaving a site that sets it unable to send anything at all. This is a method and not a
     * constant for the same reason {@see self::registry()} is: it is a seam, and a test can point it elsewhere
     * without a class of production code having to exist for the test to run.
     *
     * @return string Fully qualified name of the in memory transport.
     */
    protected static function fake_class(): string {
        return fake::class;
    }

    /**
     * Whether the site asked for the in memory transport instead of a real one.
     *
     * @return bool True when `$CFG->message_whatsapp_fake_transport` is set in config.php.
     */
    public static function fake_transport_requested(): bool {
        global $CFG;

        return !empty($CFG->message_whatsapp_fake_transport);
    }

    /**
     * Makes sure the class that owns the mode constants is loaded.
     *
     * `message_output_whatsapp` lives at the root of the plugin and not under `classes/`, because that is where
     * core looks for a message processor, so the Moodle autoloader does not know about it. The scheduled task and
     * the tests can reach this factory without core having loaded any message processor, and reading a constant of
     * a class that is not loaded is a fatal error. The path is built from `$CFG->dirroot`, which is the servable
     * tree in both 4.5 and 5.2 and therefore the same string in both.
     *
     * @return void
     */
    protected static function load_processor(): void {
        global $CFG;

        if (!class_exists(message_output_whatsapp::class, false)) {
            require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');
        }
    }

    /**
     * Renders a configured mode so that it can be shown in a diagnostic message.
     *
     * The value is written by an administrator through a select, so it is not dangerous, but it ends up in the
     * error column of the queue and in an administration screen. Anything that is not a plain identifier is
     * dropped rather than carried around, and the length is capped so that a pasted blob cannot fill the column.
     *
     * @param string $mode Mode as read from the configuration.
     * @return string A short, printable version of it.
     */
    private static function describe(string $mode): string {
        return substr((string) preg_replace('/[^a-zA-Z0-9_.\-]/', '', $mode), 0, 40);
    }
}
