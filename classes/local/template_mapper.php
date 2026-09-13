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
 * Mapping of a Moodle message onto the WhatsApp template that carries it.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\local;

/**
 * Turns the event data of a Moodle notification into the template, language, parameters and URL to send.
 *
 * Meta only accepts free text inside the 24 hour customer service window, which a Moodle notification practically
 * never falls into, so every notification leaves as an approved UTILITY template. This class decides which template
 * and with which values, and it is the last place where the content of a Moodle message is still Moodle's.
 *
 * The template of this release is the base one of the architecture:
 *
 * ```
 * Body:   {{1}}: {{2}}
 *         {{3}}
 * Button: URL, fixed base plus the suffix the transport adds.
 * ```
 *
 * so `params` is always a list of exactly three strings, in that order: short site name, subject and summary. A
 * later release maps `component`/`name` pairs onto different templates through an admin editable table; until then
 * every notification of every component ends up on {@see self::TEMPLATE}.
 *
 * Two rules shape the implementation and are worth stating before reading it:
 *
 * - The event data is a plain `stdClass` that core builds with `get_eventobject_for_processor()`, and other plugins
 *   can mutate it through `pre_processor_message_send` before it reaches us. Every field is read defensively, and an
 *   incomplete or downright empty object has to produce a usable mapping instead of an exception: this code runs
 *   inside the web request of whoever triggered the event, and blowing up there would break an unrelated page.
 * - Meta rejects a template whose parameter is empty, so none of the three values can ever come back empty. When a
 *   message carries no usable text at all the value falls back to {@see self::FALLBACK}.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_mapper {
    /** @var string The only template of this release, registered in Meta as a UTILITY template. */
    public const TEMPLATE = 'moodle_notification';

    /** @var string Language code of the Spanish version of the template. */
    public const LANG_ES = 'es_AR';

    /** @var string Language code of the English version of the template, and the default of the mapper. */
    public const LANG_EN = 'en';

    /** @var int Maximum length of the short site name, parameter {{1}}. */
    public const MAX_SITE = 60;

    /** @var int Maximum length of the subject, parameter {{2}}. */
    public const MAX_SUBJECT = 60;

    /** @var int Maximum length of the summary, parameter {{3}}. */
    public const MAX_SUMMARY = 160;

    /** @var string Stand in for a parameter that has no content, because Meta rejects an empty one. */
    public const FALLBACK = '-';

    /**
     * Maps the event data of a notification onto the template that will carry it.
     *
     * @param \stdClass $eventdata Event data as core hands it to the processor, possibly incomplete.
     * @return mapped Template, language, the three body parameters and the URL of the button.
     */
    public static function map(\stdClass $eventdata): mapped {
        $subject = self::first_usable(self::subject_candidates($eventdata), self::MAX_SUBJECT);

        // The subject is measured again against the longer budget of the summary so that both can be compared: a
        // subject cut at 60 characters and the same text cut at 160 are different strings but the same content.
        $alreadysaid = $subject === null ? '' : self::sanitise($subject['source'], self::MAX_SUMMARY);
        $summary = self::first_usable(self::summary_candidates($eventdata), self::MAX_SUMMARY, $alreadysaid);

        $params = [
            self::site_name(),
            $subject['value'] ?? self::FALLBACK,
            $summary['value'] ?? self::FALLBACK,
        ];

        return new mapped(self::TEMPLATE, self::language($eventdata), $params, self::url($eventdata));
    }

    /**
     * Returns the short name of the site for parameter {{1}}.
     *
     * The admin setting wins because the name that reaches a phone has to be short and recognisable, and a site
     * short name is often neither. When the setting is empty the site short name is used, and only if that is empty
     * too does the full name step in, which is the one field of a Moodle site that is never blank.
     *
     * The value is plain text, so it is sanitised as such: running an administrator typed name through the HTML
     * converter would eat a literal `<` and decode entities that were typed by hand.
     *
     * @return string Non empty text of at most self::MAX_SITE characters.
     */
    protected static function site_name(): string {
        global $SITE;

        $name = trim((string) get_config('message_whatsapp', 'sitename_short'));

        if ($name === '') {
            $name = trim((string) ($SITE->shortname ?? ''));
        }

        if ($name === '') {
            $name = trim((string) ($SITE->fullname ?? ''));
        }

        $name = sanitizer::for_param($name, self::MAX_SITE, FORMAT_PLAIN);

        return $name === '' ? self::FALLBACK : $name;
    }

    /**
     * Returns the sources to try for the subject, parameter {{2}}, best first.
     *
     * `subject` is the field every notification provider fills with the one line that names the event, which is
     * exactly what this parameter is for. `contexturlname` follows because it names the thing the notification is
     * about (the assignment, the discussion, the badge) and is the best remaining title. Only after those does the
     * body of the message get used, so that a subject is never invented out of a paragraph when a real one exists.
     *
     * Subjects are built with `format_string()`, which HTML escapes its output, so they are sanitised as HTML: a
     * course called `Física & Química` arrives as `Física &amp; Química` and has to reach the phone decoded.
     *
     * @param \stdClass $eventdata Event data as core hands it to the processor.
     * @return array[] List of [text, format] pairs.
     */
    protected static function subject_candidates(\stdClass $eventdata): array {
        return [
            [self::field($eventdata, 'subject'), FORMAT_HTML],
            [self::field($eventdata, 'contexturlname'), FORMAT_HTML],
            [self::field($eventdata, 'smallmessage'), FORMAT_HTML],
            self::body($eventdata),
        ];
    }

    /**
     * Returns the sources to try for the summary, parameter {{3}}, best first.
     *
     * `smallmessage` comes first because it is what a provider writes when it has to say the whole thing in one
     * line, which is the same problem this parameter has; it is the field Moodle itself uses for the popup and the
     * mobile notification. The full body follows, cut to the limit, and `contexturlname` is a last resort that at
     * least names the destination.
     *
     * `smallmessage` has no declared format in core and several providers build it with a language string that
     * interpolates content which may carry markup, `mod_forum` among them, so it is sanitised as HTML.
     *
     * @param \stdClass $eventdata Event data as core hands it to the processor.
     * @return array[] List of [text, format] pairs.
     */
    protected static function summary_candidates(\stdClass $eventdata): array {
        return [
            [self::field($eventdata, 'smallmessage'), FORMAT_HTML],
            self::body($eventdata),
            [self::field($eventdata, 'contexturlname'), FORMAT_HTML],
        ];
    }

    /**
     * Returns the body of the message and the format it has to be read with.
     *
     * The HTML version is preferred when it exists because it is the complete one; providers that build both leave
     * `fullmessagehtml` empty when the recipient asked for plain text only. A missing or unusable
     * `fullmessageformat` is treated as HTML, which is the conservative choice: markup in a parameter would reach
     * the phone as markup, while plain text run through the converter only loses a literal `<`.
     *
     * @param \stdClass $eventdata Event data as core hands it to the processor.
     * @return array Pair of [text, format].
     */
    protected static function body(\stdClass $eventdata): array {
        $html = self::field($eventdata, 'fullmessagehtml');
        if (trim($html) !== '') {
            return [$html, FORMAT_HTML];
        }

        $format = $eventdata->fullmessageformat ?? null;
        $format = is_numeric($format) ? (int) $format : FORMAT_HTML;

        return [self::field($eventdata, 'fullmessage'), $format];
    }

    /**
     * Returns the first source that yields usable text, together with the source itself.
     *
     * @param array[] $candidates List of [text, format] pairs, best first.
     * @param int $max Maximum length of the result, in characters.
     * @param string $avoid Text to skip, already sanitised with the same $max. Empty means skip nothing.
     * @return array|null Pair of ['value' => string, 'source' => array], or null when no candidate has content.
     */
    protected static function first_usable(array $candidates, int $max, string $avoid = ''): ?array {
        foreach ($candidates as $source) {
            $value = self::sanitise($source, $max);

            if ($value === '') {
                continue;
            }

            // The same text in two fields is common: mod_assign copies the subject into smallmessage, and repeating
            // it in the body of the template would waste the only two lines the message has.
            if ($avoid !== '' && \core_text::strtolower($value) === \core_text::strtolower($avoid)) {
                continue;
            }

            return ['value' => $value, 'source' => $source];
        }

        return null;
    }

    /**
     * Sanitises one [text, format] pair into a value apt for a template parameter.
     *
     * @param array $source Pair of [text, format].
     * @param int $max Maximum length of the result, in characters.
     * @return string Sanitised text, possibly empty.
     */
    protected static function sanitise(array $source, int $max): string {
        return sanitizer::for_param((string) $source[0], $max, (int) $source[1]);
    }

    /**
     * Returns the URL the button of the template has to lead to.
     *
     * A notification without a context (a site announcement, for instance) still gets a button, pointing at the
     * front page: a template button cannot be made optional per message, so the alternative to a useful URL is a
     * broken one.
     *
     * @param \stdClass $eventdata Event data as core hands it to the processor.
     * @return string Absolute URL, never empty.
     */
    protected static function url(\stdClass $eventdata): string {
        global $CFG;

        $url = $eventdata->contexturl ?? null;

        // Core documents this field as `string|url`, and providers do use both: mod_forum stores the result of
        // out(), lib/badgeslib.php stores the object itself.
        if (is_object($url) && method_exists($url, 'out')) {
            $url = $url->out(false);
        }

        $url = is_string($url) ? trim($url) : '';

        return $url === '' ? $CFG->wwwroot : $url;
    }

    /**
     * Returns the language of the template version to use.
     *
     * The recipient decides: a notification is read by them and by nobody else. Only when the recipient has no
     * language of their own does the site default apply, and only two versions of the template exist, so anything
     * Spanish maps to the Spanish one and everything else to English.
     *
     * @param \stdClass $eventdata Event data as core hands it to the processor.
     * @return string Language code of a template version, self::LANG_ES or self::LANG_EN.
     */
    protected static function language(\stdClass $eventdata): string {
        global $CFG;

        $userto = $eventdata->userto ?? null;
        $lang = is_object($userto) ? (string) ($userto->lang ?? '') : '';

        if (trim($lang) === '') {
            $lang = (string) ($CFG->lang ?? '');
        }

        $lang = strtolower(trim($lang));

        return str_starts_with($lang, 'es') ? self::LANG_ES : self::LANG_EN;
    }

    /**
     * Reads one field of the event data as a string, whatever core or another plugin left in it.
     *
     * @param \stdClass $eventdata Event data as core hands it to the processor.
     * @param string $field Name of the field to read.
     * @return string Value of the field, or an empty string when it is missing or is not text.
     */
    protected static function field(\stdClass $eventdata, string $field): string {
        $value = $eventdata->$field ?? null;

        if (is_string($value)) {
            return $value;
        }

        // A number is text as far as a template parameter is concerned; an object or an array is not.
        return is_scalar($value) ? (string) $value : '';
    }
}
