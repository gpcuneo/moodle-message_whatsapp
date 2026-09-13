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
 * The answer of the factory when there is no transport to build.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\transport;

/**
 * A transport that sends nothing and says why, so that the factory never has to return null or throw.
 *
 * This is not a way of sending WhatsApp messages. It is what {@see factory::instance()} hands back when the site
 * has not chosen a sending mode, when the mode stored in the configuration is not one this plugin knows, or when
 * the class of a known mode is not present in this installation. It makes no network call of any kind.
 *
 * The alternatives were worse in both of the places the factory is called from. Returning null puts a check at
 * every call site, and the call site that forgets it dies with a call to a method on null -- inside a scheduled
 * task, where nobody is watching, or inside an administration page, where the screen goes blank. Throwing turns a
 * configuration mistake into a fatal error in the same two places. A null object keeps the two callers on one code
 * path: the sending task treats the row like any other failure and writes the reason into the report, and the test
 * connection button of the settings page prints the reason instead of a stack trace, which is exactly the answer
 * the administrator came for.
 *
 * The refusal is permanent. A missing or unknown mode is not a condition that resolves itself while a backoff
 * ticks: it resolves when a person opens the settings page. Retrying it five times over an hour would reach the
 * same end, an hour later and with the cause buried under four identical attempts. The retry action of the admin
 * report is what puts a row back in the queue once the mode is set.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class unconfigured implements transport_interface {
    /** Machine name reported by {@see self::name()}. Stable: log lines and tests compare it. */
    public const NAME = 'unconfigured';

    /**
     * Private on purpose: built through {@see self::because()}, which reads as the sentence it is.
     *
     * @param result $answer The refusal every method of this transport returns.
     */
    private function __construct(
        /** @var result The refusal every method of this transport returns. */
        private readonly result $answer,
    ) {
    }

    /**
     * Builds the transport that refuses everything for the given reason.
     *
     * @param string $code Stable machine identifier of the reason, one of the `CODE_*` constants of {@see factory}.
     * @param string $message Diagnostic for the administrator.
     * @return self A transport that answers that reason to anything it is asked.
     */
    public static function because(string $code, string $message): self {
        return new self(result::permanent_failure($code, $message));
    }

    /**
     * Sends nothing and reports why nothing could be sent.
     *
     * @param string $phone Ignored: there is nothing to send it with.
     * @param string $template Ignored.
     * @param string $lang Ignored.
     * @param string[] $params Ignored.
     * @param string|null $urlsuffix Ignored.
     * @return result The permanent failure this transport was built with.
     */
    public function send_template(string $phone, string $template, string $lang, array $params, ?string $urlsuffix): result {
        return $this->answer;
    }

    /**
     * Reports why there is no connection to test.
     *
     * @return result The permanent failure this transport was built with.
     */
    public function check(): result {
        return $this->answer;
    }

    /**
     * Returns the machine name of this transport.
     *
     * @return string Always `unconfigured`.
     */
    public function name(): string {
        return self::NAME;
    }
}
