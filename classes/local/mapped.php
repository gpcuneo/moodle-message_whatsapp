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
 * Result of mapping a Moodle message onto a WhatsApp template.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\local;

/**
 * Everything the queue has to store and the transport has to send for one notification.
 *
 * This is what {@see template_mapper::map()} returns. It is an immutable value object and not an array because it
 * travels between two pieces of code that are written apart from each other: the queue writes it into
 * `message_whatsapp_queue` and, minutes later, a transport reads it back and builds the payload for Meta. A typed
 * object states the contract once, so neither side has to guess a key name or the order of the parameters, and a
 * typo fails at the call instead of producing a message that Meta rejects.
 *
 * The values are already sanitised: `params` is a list of three strings that respect the length limits of the
 * template and contain no character that Meta rejects. Nothing here needs further processing before being sent.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mapped {
    /**
     * Builds the result of a mapping.
     *
     * @param string $templatekey Name of the approved template, as it is registered in Meta.
     * @param string $lang Language code of the template version to use, for instance `es_AR` or `en`.
     * @param string[] $params Body parameters in template order: short site name, subject and summary.
     * @param string $url Absolute URL the button of the template has to lead to.
     */
    public function __construct(
        /** @var string Name of the approved template, as it is registered in Meta. */
        public readonly string $templatekey,
        /** @var string Language code of the template version to use. */
        public readonly string $lang,
        /** @var string[] Body parameters in template order. */
        public readonly array $params,
        /** @var string Absolute URL the button of the template has to lead to. */
        public readonly string $url,
    ) {
    }

    /**
     * Returns the parameters encoded for the `params` column of the queue.
     *
     * The encoding lives here and not in the queue so that the value that is stored and the value that is sent to
     * Meta can never drift apart. Unicode and slashes are left alone: the column holds accented subjects and URLs,
     * and escaping them would only make the rows unreadable.
     *
     * @return string JSON array of the three parameters.
     */
    public function params_json(): string {
        return (string) json_encode(array_values($this->params), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
