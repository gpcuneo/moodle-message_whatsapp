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
 * Text sanitiser for WhatsApp template parameters.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\local;

/**
 * Turns Moodle message content into a string that Meta accepts as a template parameter.
 *
 * The WhatsApp Cloud API rejects a template parameter that contains a newline, a tab or more than four consecutive
 * spaces, and it also rejects parameters longer than the limit of the template. Every string that reaches a template
 * has to go through {@see self::for_param()} first.
 *
 * The HTML to plain text conversion is delegated to core (`content_to_text()`, which calls `html_to_text()`): it keeps
 * list bullets, drops the markup and decodes the entities, and it behaves the same in Moodle 4.5 and 5.2. That
 * conversion adds newlines, tabs and, in the case of lists, leading indentation, so the collapsing pass afterwards is
 * not optional. Two side effects of core's converter are inherited on purpose, because reimplementing the conversion
 * to avoid them would be worse than living with them: headings and bold runs come back upper cased, and the target of
 * a link is dropped and only its anchor text survives (that is what we want here anyway, since the destination of the
 * notification travels in the URL button of the template, never in the body).
 *
 * Lengths are measured in characters, not bytes, so an accented or emoji heavy subject is not cut short by accident.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sanitizer {

    /** @var string Marker appended to a value that had to be cut. Counts as one character towards the limit. */
    protected const ELLIPSIS = '…';

    /**
     * Every character that has to be treated as a space, collapsed into a single plain space.
     *
     * Covers the ASCII whitespace and separators, the no break space (U+00A0) that `&nbsp;` decodes into, and the
     * rest of the Unicode space separators. `\s` is not enough: without PCRE_UCP it only matches ASCII, so U+00A0
     * would survive and Meta would count it as a space anyway.
     *
     * @var string
     */
    protected const RE_SPACES = '/[\x{0009}-\x{000D}\x{001C}-\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}' .
        '\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';

    /** @var string Control characters that are not whitespace and are simply removed. */
    protected const RE_CONTROLS = '/[\x{0000}-\x{0008}\x{000E}-\x{001B}\x{007F}]/u';

    /**
     * Sanitises a piece of message content so it can be sent as a template parameter.
     *
     * The caller passes `fullmessagehtml` when the message carries HTML and `fullmessage` when it does not, together
     * with the matching format: with FORMAT_PLAIN the text is not run through the HTML converter, which would eat a
     * literal `<` and decode entities that the user actually typed.
     *
     * The result never contains a newline, a tab or two consecutive spaces (so the "no more than four" rule of Meta
     * holds by construction), never starts or ends with a space, and is never longer than $max characters.
     *
     * The text is cut at a word boundary and the ellipsis is counted inside $max, so the returned string is at most
     * $max characters even after the marker is appended. A single word longer than the budget is cut in the middle:
     * losing the beginning of the subject would be worse than an ugly cut, and the length limit is not negotiable
     * because Meta rejects the whole message when a parameter exceeds it.
     *
     * @param string $html Raw content of the message, usually `fullmessagehtml` or `fullmessage`.
     * @param int $max Maximum length of the result, in characters. Zero or less returns an empty string.
     * @param int $format Format of $html, as in `$eventdata->fullmessageformat`. Defaults to FORMAT_HTML.
     * @return string Text apt for a template parameter, possibly empty.
     */
    public static function for_param(string $html, int $max, int $format = FORMAT_HTML): string {
        if ($max < 1) {
            return '';
        }

        // Broken bytes would make the /u patterns below fail and return null, so normalise the input first.
        $text = content_to_text((string) fix_utf8($html), $format);

        $text = preg_replace(self::RE_CONTROLS, '', $text);
        $text = preg_replace(self::RE_SPACES, ' ', (string) $text);
        $text = trim((string) $text);

        if ($text === '') {
            return '';
        }

        if (\core_text::strlen($text) <= $max) {
            return $text;
        }

        return self::cut($text, $max);
    }

    /**
     * Cuts an already collapsed text to $max characters, on a word boundary and with the ellipsis inside the budget.
     *
     * @param string $text Collapsed and trimmed text, longer than $max characters.
     * @param int $max Maximum length of the result, in characters. At least one.
     * @return string Text of at most $max characters ending with the ellipsis.
     */
    protected static function cut(string $text, int $max): string {
        $budget = $max - \core_text::strlen(self::ELLIPSIS);
        if ($budget < 1) {
            // There is no room for any text: return just as much of the marker as fits.
            return \core_text::substr(self::ELLIPSIS, 0, $max);
        }

        // One character beyond the budget tells us whether the cut already lands on a word boundary.
        $slice = \core_text::substr($text, 0, $budget + 1);
        $head = \core_text::substr($slice, 0, $budget);

        if (\core_text::substr($slice, $budget, 1) !== ' ') {
            $space = \core_text::strrpos($head, ' ');
            if ($space !== false) {
                $head = \core_text::substr($head, 0, $space);
            }
            // No space at all means a single word longer than the budget: it stays cut in the middle, on purpose.
        }

        return rtrim($head) . self::ELLIPSIS;
    }
}
