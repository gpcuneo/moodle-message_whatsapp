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
 * One page of delivery status changes as the gateway reports them.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\transport;

/**
 * What `GET /v1/messages?since=` answers, read into something this plugin can use.
 *
 * The answer of the gateway is a page of changes plus the cursor to continue from, and the parsing of it lives
 * here rather than in {@see \message_whatsapp\task\sync_status} for one reason: the task decides **what to do**
 * with a status change, and this decides **what the wire said**. A task that did both would grow the shape of
 * somebody else's JSON into the middle of its own loop.
 *
 * Everything is read defensively and nothing is trusted. A field of the wrong type is dropped rather than cast,
 * because a `providermsgid` that arrived as a number and was silently turned into a string would match a row by
 * accident, and this page is the only thing that moves a queue row forward in gateway mode.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class changes {
    /**
     * Private on purpose: a page is built by reading an answer, and there is no other way to make one.
     *
     * @param change[] $items The changes of this page, in the order the cursor defines.
     * @param string $cursor Where to continue from on the next run.
     * @param bool $hasmore Whether the gateway has another page waiting right now.
     */
    private function __construct(
        /** @var change[] The changes of this page, in the order the cursor defines. */
        public readonly array $items,
        /** @var string Where to continue from on the next run. */
        public readonly string $cursor,
        /** @var bool Whether the gateway has another page waiting right now. */
        public readonly bool $hasmore,
    ) {
    }

    /**
     * Reads a page out of the decoded answer of the gateway.
     *
     * The cursor is what makes this worth refusing over. A page whose cursor could not be read cannot be stored,
     * and storing the old one would make the next run ask for the same page again, for ever. So an answer with no
     * usable cursor is not a page at all and the caller is told so; a single malformed **item** inside an
     * otherwise good page is only dropped, because the rest of the page is still true.
     *
     * @param array $data Decoded body of the answer.
     * @return self|null The page, or null when the answer is not one.
     */
    public static function from_answer(array $data): ?self {
        $cursor = $data['cursor'] ?? null;
        $messages = $data['messages'] ?? null;

        if (!is_string($cursor) || $cursor === '' || !is_array($messages)) {
            return null;
        }

        $items = [];
        foreach ($messages as $message) {
            $change = is_array($message) ? change::from_item($message) : null;

            if ($change !== null) {
                $items[] = $change;
            }
        }

        return new self($items, $cursor, !empty($data['has_more']));
    }
}
