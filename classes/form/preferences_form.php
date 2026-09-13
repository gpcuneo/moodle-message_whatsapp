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
 * Fields the WhatsApp message processor adds to the notification preferences.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\form;

use html_writer;

/**
 * Builds the fields of the processor settings dialogue of the notification preferences page.
 *
 * This is deliberately not a moodleform. Core renders whatever config_form() returns inside a form element of its
 * own and submits it by serialising the fields with JavaScript, so a nested moodleform would produce invalid HTML
 * and its hidden fields would never reach process_form().
 *
 * The same constraint removes the usual validation channel: process_form() has no way to send a message back to
 * the dialogue. Anything the user has to be told is therefore stored in the row and rendered the next time the
 * dialogue is opened, which is why this class reads a status as well as a number.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preferences_form {
    /** Name of the hidden field that carries the user being edited. */
    public const FIELD_USERID = 'whatsapp_userid';

    /** Name of the text field that holds the phone number. */
    public const FIELD_PHONE = 'whatsapp_phone';

    /** Name of the checkbox that holds the consent. */
    public const FIELD_OPTIN = 'whatsapp_optin';

    /**
     * Returns the HTML of the fields.
     *
     * @param array|\stdClass $preferences The messaging preferences, as filled in by load_data().
     * @return string The HTML of the fields.
     */
    public static function render($preferences): string {
        $userid = (int) self::value($preferences, self::FIELD_USERID, 0);
        $phone = (string) self::value($preferences, self::FIELD_PHONE, '');
        $optin = (int) self::value($preferences, self::FIELD_OPTIN, 0);
        $invalid = (int) self::value($preferences, 'whatsapp_invalid', 0);
        $landline = (int) self::value($preferences, 'whatsapp_landline', 0);

        $html = html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => self::FIELD_USERID,
            'value' => $userid,
        ]);

        $html .= self::phone_field($phone);
        $html .= self::warnings($phone, $invalid, $landline);
        $html .= self::optin_field($optin);

        return $html;
    }

    /**
     * Returns the phone number field, prefilled with the number already known for the user.
     *
     * @param string $phone The stored number in E.164, or the empty string.
     * @return string The HTML of the field.
     */
    private static function phone_field(string $phone): string {
        $html = html_writer::div(
            html_writer::label(get_string('prefphone', 'message_whatsapp'), self::FIELD_PHONE),
            'form-group'
        );

        $html .= html_writer::div(
            html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => self::FIELD_PHONE,
                'id' => self::FIELD_PHONE,
                'value' => $phone,
                'size' => 30,
                'class' => 'form-control',
                'inputmode' => 'tel',
                'autocomplete' => 'tel',
            ])
        );

        $html .= html_writer::div(get_string('prefphone_desc', 'message_whatsapp'), 'text-muted small');

        return $html;
    }

    /**
     * Returns the notices the user has to act on before the channel can work.
     *
     * The landline notice is the important one. Argentina has no digit that separates mobiles from landlines, so a
     * mobile typed without the 15 after the area code normalises to a perfectly valid landline that WhatsApp can
     * never deliver to. Storing that number and saying nothing would leave the user convinced that the channel is
     * on while every notification quietly fails, so the notice names the problem and shows the two ways to fix it.
     *
     * @param string $phone The stored number in E.164, or the empty string.
     * @param int $invalid 1 when the last number seen could not be normalised.
     * @param int $landline 1 when the stored number is known to be a landline.
     * @return string The HTML of the notices, empty when there is nothing to say.
     */
    private static function warnings(string $phone, int $invalid, int $landline): string {
        $html = '';

        if ($invalid) {
            $html .= self::notice(get_string('prefphoneinvalid', 'message_whatsapp'), 'danger');
        } else if ($phone === '') {
            $html .= self::notice(get_string('prefnophone', 'message_whatsapp'), 'info');
        } else if ($landline) {
            $html .= self::notice(get_string('prefphonelandline', 'message_whatsapp'), 'warning');
        }

        return $html;
    }

    /**
     * Returns the consent checkbox.
     *
     * @param int $optin 1 when the user has already consented.
     * @return string The HTML of the field.
     */
    private static function optin_field(int $optin): string {
        $attributes = [
            'type' => 'checkbox',
            'name' => self::FIELD_OPTIN,
            'id' => self::FIELD_OPTIN,
            'value' => 1,
            'class' => 'form-check-input',
        ];

        if ($optin) {
            $attributes['checked'] = 'checked';
        }

        $html = html_writer::div(
            html_writer::empty_tag('input', $attributes) . ' ' .
            html_writer::label(get_string('prefoptin', 'message_whatsapp'), self::FIELD_OPTIN),
            'form-check mt-2'
        );

        $html .= html_writer::div(get_string('prefoptin_desc', 'message_whatsapp'), 'text-muted small');

        return $html;
    }

    /**
     * Returns one notice box.
     *
     * @param string $text The already translated text of the notice.
     * @param string $level Bootstrap contextual class: info, warning or danger.
     * @return string The HTML of the notice.
     */
    private static function notice(string $text, string $level): string {
        return html_writer::div($text, 'alert alert-' . $level . ' mt-2', ['role' => 'alert']);
    }

    /**
     * Reads one value out of the preferences, which core hands over as an object but documents as an array.
     *
     * @param array|\stdClass $preferences The messaging preferences.
     * @param string $key The name of the value.
     * @param mixed $default What to return when the value is not there.
     * @return mixed The value, or the default.
     */
    private static function value($preferences, string $key, $default) {
        if (is_array($preferences)) {
            return $preferences[$key] ?? $default;
        }

        if (is_object($preferences)) {
            return $preferences->{$key} ?? $default;
        }

        return $default;
    }
}
