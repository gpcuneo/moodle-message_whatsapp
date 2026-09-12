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
 * Phone number normalisation for the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\local;

/**
 * Turns the phone numbers people type into E.164, without any external dependency.
 *
 * The plugin carries no composer dependencies, so there is no libphonenumber here. Argentina, the only country the
 * plugin targets today, has hand written rules; every other country gets a generic treatment: keep the digits, drop
 * the trunk prefix and prepend the calling code when the number was not written in international format.
 *
 * Argentine rules (ENACOM numbering plan):
 *
 * - The national significant number is always ten digits: an area code of two, three or four digits plus the
 *   subscriber number. Only `11` is two digits long; {@see self::AR_AREA_CODES_THREE} holds every three digit one;
 *   anything else starting with 2 or 3 is four digits long. The plan is prefix free, so the longest match is safe.
 * - The trunk prefix `0` before the area code and the mobile prefix `15` after it are national dialling artefacts
 *   and never reach E.164.
 * - A mobile is `+54` `9` plus the ten digits; a landline is `+54` plus the same ten digits.
 *
 * Mobility is decided **only** by an explicit marker in what the user typed: the `15` after the area code, or the
 * `9` after the country code. There is no digit prefix that separates mobiles from landlines in Argentina, so a
 * number typed without either marker is taken as a landline, which is what `011 4123-4567` is. The consequence is
 * that a user who types their mobile without the `15` gets a landline number; the preferences form shows the
 * normalised number precisely so that they can correct it.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class phone {

    /** @var string Calling code of Argentina, the only country with hand written rules. */
    private const AR_CALLING_CODE = '54';

    /** @var int Length of an Argentine national significant number, area code included. */
    private const AR_NSN_LENGTH = 10;

    /** @var string[] Every Argentine area code that is three digits long; the rest are two (11) or four digits. */
    private const AR_AREA_CODES_THREE = [
        '220', '221', '223', '230', '236', '237', '249', '260', '261', '263', '264', '266', '280', '291', '297',
        '299', '341', '342', '343', '345', '348', '351', '353', '358', '362', '364', '370', '376', '379', '380',
        '381', '383', '385', '387', '388',
    ];

    /** @var string[] Calling codes of the countries the plugin can normalise without an explicit international prefix. */
    private const CALLING_CODES = [
        'AR' => '54', 'BO' => '591', 'BR' => '55', 'CL' => '56', 'CO' => '57', 'CR' => '506', 'DO' => '1',
        'EC' => '593', 'ES' => '34', 'GT' => '502', 'MX' => '52', 'PA' => '507', 'PE' => '51', 'PY' => '595',
        'PT' => '351', 'US' => '1', 'UY' => '598', 'VE' => '58',
    ];

    /**
     * Normalises a phone number to E.164.
     *
     * @param string $raw The number as the user typed it, with any punctuation.
     * @param string $country ISO 3166-1 alpha-2 code of the country to assume when the number is not international.
     * @return string|null The number in E.164 (`+` and up to fifteen digits), or null when it cannot be normalised.
     */
    public static function normalize(string $raw, string $country): ?string {
        $country = strtoupper(trim($country));
        $international = (bool) preg_match('/^\s*\+/', $raw);
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        // A leading 00 is the international prefix of most of the world, including Argentina.
        if (!$international && str_starts_with($digits, '00')) {
            $international = true;
            $digits = substr($digits, 2);
        }

        if ($international) {
            // Calling codes are prefix free, so anything starting with 54 is Argentine and gets the strict rules.
            if (str_starts_with($digits, self::AR_CALLING_CODE)) {
                return self::normalize_ar($digits, true);
            }
            return self::validate('+' . $digits);
        }

        if ($country === 'AR') {
            return self::normalize_ar($digits, false);
        }

        return self::normalize_generic($digits, $country);
    }

    /**
     * Tells whether an E.164 number is a mobile line, the only kind WhatsApp can deliver to.
     *
     * The answer is derived from the E.164 number itself because the Argentine rules already encode mobility in it:
     * `+549` is a mobile and a plain `+54` is a landline. Null means unknown, which is the honest answer for every
     * country without hand written rules; callers must treat only an explicit false as a landline.
     *
     * @param string|null $e164 A number as returned by {@see self::normalize()}, or null.
     * @return bool|null True for a mobile, false for a landline, null when the number is unknown or not Argentine.
     */
    public static function is_mobile(?string $e164): ?bool {
        if ($e164 === null || !str_starts_with($e164, '+' . self::AR_CALLING_CODE)) {
            return null;
        }

        $national = substr($e164, 1 + strlen(self::AR_CALLING_CODE));

        if (strlen($national) === self::AR_NSN_LENGTH + 1 && str_starts_with($national, '9')) {
            return true;
        }

        if (strlen($national) === self::AR_NSN_LENGTH) {
            return false;
        }

        return null;
    }

    /**
     * Normalises an Argentine number, taking apart the trunk prefix, the country code and the mobile markers.
     *
     * @param string $digits The number reduced to digits, with no international prefix left.
     * @param bool $international Whether the user wrote the number in international format (+ or 00).
     * @return string|null The number in E.164, or null when it is not a valid Argentine number.
     */
    private static function normalize_ar(string $digits, bool $international): ?string {
        $mobile = false;

        if (str_starts_with($digits, self::AR_CALLING_CODE) && strlen($digits) >= self::AR_NSN_LENGTH + 2) {
            // The country code, written either after the + or typed by hand as in 54 11 1234 5678.
            $digits = substr($digits, strlen(self::AR_CALLING_CODE));
        } else if ($international) {
            return null;
        } else if (str_starts_with($digits, '0')) {
            // The trunk prefix used for national dialling.
            $digits = substr($digits, 1);
        }

        // The mobile marker of the international format: no area code starts with 9, so this is never ambiguous.
        if (strlen($digits) === self::AR_NSN_LENGTH + 1 && str_starts_with($digits, '9')) {
            $mobile = true;
            $digits = substr($digits, 1);
        }

        $arealength = self::ar_area_length($digits);
        if ($arealength === null) {
            return null;
        }

        // The mobile marker of the national format sits right after the area code.
        if (strlen($digits) === self::AR_NSN_LENGTH + 2 && substr($digits, $arealength, 2) === '15') {
            $mobile = true;
            $digits = substr($digits, 0, $arealength) . substr($digits, $arealength + 2);
        }

        if (strlen($digits) !== self::AR_NSN_LENGTH || $digits[$arealength] === '0') {
            return null;
        }

        return self::validate('+' . self::AR_CALLING_CODE . ($mobile ? '9' : '') . $digits);
    }

    /**
     * Returns how many digits the area code of an Argentine national number takes.
     *
     * @param string $digits A national number with no trunk prefix and no country code, the 15 possibly still there.
     * @return int|null Two, three or four, or null when the number cannot start with a valid area code.
     */
    private static function ar_area_length(string $digits): ?int {
        if (str_starts_with($digits, '11')) {
            return 2;
        }

        if (in_array(substr($digits, 0, 3), self::AR_AREA_CODES_THREE, true)) {
            return 3;
        }

        if (strlen($digits) >= 4 && ($digits[0] === '2' || $digits[0] === '3')) {
            return 4;
        }

        return null;
    }

    /**
     * Normalises a number of any country other than Argentina: keep the digits and prepend the calling code.
     *
     * @param string $digits The number reduced to digits, written in national format.
     * @param string $country ISO 3166-1 alpha-2 code of the country to assume.
     * @return string|null The number in E.164, or null when the country is unknown or the result is not valid.
     */
    private static function normalize_generic(string $digits, string $country): ?string {
        $code = self::CALLING_CODES[$country] ?? null;
        if ($code === null) {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            // A trunk prefix; whatever is left is a national number.
            $digits = substr($digits, 1);
        } else if (str_starts_with($digits, $code) && strlen($digits) - strlen($code) >= 6) {
            // The user typed the calling code without the +.
            return self::validate('+' . $digits);
        }

        return self::validate('+' . $code . $digits);
    }

    /**
     * Checks that a candidate is a syntactically valid E.164 number.
     *
     * @param string $candidate The number, already built as + followed by digits.
     * @return string|null The same number when it is valid, null otherwise.
     */
    private static function validate(string $candidate): ?string {
        return preg_match('/^\+[1-9]\d{7,14}$/', $candidate) ? $candidate : null;
    }
}
