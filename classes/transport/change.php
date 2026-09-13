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
 * One delivery status change as the gateway reports it.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\transport;

/**
 * What the gateway says about one message, which is exactly what the queue needs to move a row forward.
 *
 * It is immutable, like {@see result} and {@see \message_whatsapp\local\mapped}: it crosses from the transport to
 * the task that writes the row and nothing in between has any business editing it.
 *
 * There is no phone number here, no template and no parameters, because the gateway does not send them on this
 * endpoint: it is a query that repeats every five minutes for as long as a site is installed, and the personal
 * data of every recipient has no reason to travel in it.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class change {
    /**
     * Private on purpose: a change is read off an answer and never assembled by hand.
     *
     * @param string $providermsgid Id the gateway gave the message, which is how the queue row is found.
     * @param string $status Status the gateway reports now.
     * @param int $timestatus When it changed, as a Unix timestamp, or 0 when it could not be read.
     * @param string|null $pricingcategory Category the message was billed under, when there is one.
     * @param string|null $error Why it failed, when it failed.
     * @param string $cursor Position of this change, so a run that stops halfway resumes at the row it processed.
     */
    private function __construct(
        /** @var string Id the gateway gave the message, which is how the queue row is found. */
        public readonly string $providermsgid,
        /** @var string Status the gateway reports now. */
        public readonly string $status,
        /** @var int When it changed, as a Unix timestamp, or 0 when it could not be read. */
        public readonly int $timestatus,
        /** @var string|null Category the message was billed under, when there is one. */
        public readonly ?string $pricingcategory,
        /** @var string|null Why it failed, when it failed. */
        public readonly ?string $error,
        /** @var string Position of this change in the stream. */
        public readonly string $cursor,
    ) {
    }

    /**
     * Reads one change out of an item of the page, or returns null when the item is not one.
     *
     * **The id that matters is `id` and not `provider_msgid`.** They are two different things and confusing them
     * makes the whole cursor a no-op: `id` is what the gateway assigned when it accepted the message and what
     * `send_template()` handed back to be stored in `providermsgid` of the queue row, while `provider_msgid` is
     * what *Meta* assigned the gateway, which this site has never seen and cannot match anything to. In direct
     * mode the two happen to be the same value, because there is no gateway in between; in gateway mode they are
     * not, and matching on the wrong one leaves every row in `sent` for ever.
     *
     * @param array $item One entry of the `messages` list.
     * @return self|null The change, or null when this item cannot move any row.
     */
    public static function from_item(array $item): ?self {
        $id = $item['id'] ?? null;
        $status = $item['status'] ?? null;

        if (!is_string($id) || trim($id) === '' || !is_string($status) || $status === '') {
            return null;
        }

        return new self(
            trim($id),
            $status,
            self::timestamp($item['status_at'] ?? null),
            self::text($item['pricing_category'] ?? null),
            self::text($item['error'] ?? null),
            is_string($item['cursor'] ?? null) ? $item['cursor'] : ''
        );
    }

    /**
     * Reads the moment a status changed at.
     *
     * The gateway sends RFC 3339, which is what `strtotime()` reads. A value it cannot read gives 0, and the
     * caller takes that to mean "now": a status change with a broken timestamp is still a status change, and
     * refusing it would leave the row behind for ever over a formatting problem.
     *
     * @param mixed $value Value of `status_at`.
     * @return int Unix timestamp, or 0 when there was nothing usable to read.
     */
    private static function timestamp($value): int {
        if (!is_string($value) || $value === '') {
            return 0;
        }

        $parsed = strtotime($value);

        return $parsed === false ? 0 : max(0, $parsed);
    }

    /**
     * Reads an optional string, turning anything that is not a non empty one into null.
     *
     * @param mixed $value Value as it arrived.
     * @return string|null The string, or null.
     */
    private static function text($value): ?string {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
