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
 * Tests of the phone number normaliser.
 *
 * @package    message_whatsapp
 * @category   test
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\phone;

/**
 * Tests of the phone number normaliser.
 *
 * The class is a pure function: it reads no settings, touches no database and has no global state, so these tests
 * need no reset. Every case is data driven so that the numbers of the pilot can be added as they show up.
 *
 * @package    message_whatsapp
 * @category   test
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phone_test extends \advanced_testcase {
    /**
     * Argentine numbers, the ones the plugin has hand written rules for.
     *
     * @return array[] Sets of raw number, expected E.164 number and expected answer of is_mobile().
     */
    public static function ar_numbers_provider(): array {
        return [
            // The cases of the development plan, first the ones written as a mobile.
            'mobile with the 15 and the trunk prefix' => ['011 15 1234 5678', '+5491112345678', true],
            'mobile in international format' => ['+54 9 11 1234 5678', '+5491112345678', true],
            'mobile with a three digit area code' => ['0291 15 412 3456', '+5492914123456', true],
            // And now the ones written without any mobile marker, which are landlines.
            'landline of the plan' => ['011 4123-4567', '+541141234567', false],
            'landline without the trunk prefix' => ['11 1234-5678', '+541112345678', false],
            'landline with the country code typed by hand' => ['54 11 1234 5678', '+541112345678', false],
            'landline with a three digit area code' => ['2914123456', '+542914123456', false],

            // Area codes of two, three and four digits, with and without the mobile markers.
            'four digit area code, mobile' => ['03547 15 41-2345', '+5493547412345', true],
            'four digit area code, landline' => ['3547412345', '+543547412345', false],
            'four digit area code of Patagonia, mobile' => ['02984 15 12 3456', '+5492984123456', true],
            'three digit area code of La Plata, mobile' => ['0221 15 456 7890', '+5492214567890', true],
            'two digit area code with punctuation' => ['(011) 15-6123-4567', '+5491161234567', true],

            // Formats that arrive from a profile field filled in by hand.
            'already normalised, left untouched' => ['+5491141234567', '+5491141234567', true],
            'spaces and a leading plus with a space' => [' + 54 9 11 6123 4567 ', '+5491161234567', true],
            'international prefix 00 instead of the plus' => ['0054 9 341 512 3456', '+5493415123456', true],
            'mobile marker 9 without any country code' => ['9 11 1234 5678', '+5491112345678', true],
            'text around the number' => ['WhatsApp: 011 15-1234-5678', '+5491112345678', true],

            // Numbers that cannot be normalised.
            'local mobile without the area code' => ['15 1234-5678', null, null],
            'local number without the area code' => ['1234-5678', null, null],
            'non geographic 0800 number' => ['0800 555 1234', null, null],
            'area code that does not exist' => ['0491 15 412 3456', null, null],
            'too short for Argentina' => ['+54 11 1234', null, null],
            'too long for Argentina' => ['+549 11 1234 5678 999', null, null],
            'subscriber number starting with zero' => ['11 0123-4567', null, null],
            'empty string' => ['', null, null],
            'no digits at all' => ['no tengo telefono', null, null],
        ];
    }

    /**
     * Tests that Argentine numbers reach E.164 and that mobiles are told apart from landlines.
     *
     * @dataProvider ar_numbers_provider
     * @param string $raw The number as the user typed it.
     * @param string|null $expected The expected E.164 number, or null when it cannot be normalised.
     * @param bool|null $mobile The expected answer of is_mobile() for the normalised number.
     */
    public function test_normalize_ar(string $raw, ?string $expected, ?bool $mobile): void {
        $normalised = phone::normalize($raw, 'AR');

        $this->assertSame($expected, $normalised);
        $this->assertSame($mobile, phone::is_mobile($normalised));
    }

    /**
     * Numbers of countries without hand written rules, plus the default country that decides the calling code.
     *
     * @return array[] Sets of raw number, country and expected E.164 number.
     */
    public static function generic_numbers_provider(): array {
        return [
            'Uruguayan mobile with its trunk prefix' => ['099 123 456', 'UY', '+59899123456'],
            'Spanish mobile without any prefix' => ['612345678', 'ES', '+34612345678'],
            'number of the United States with punctuation' => ['(555) 010-1234', 'US', '+15550101234'],
            'Brazilian number with the calling code typed by hand' => ['5511987654321', 'BR', '+5511987654321'],
            'lowercase country code' => ['099 123 456', 'uy', '+59899123456'],
            'international number wins over the default country' => ['+34 612 345 678', 'AR', '+34612345678'],
            'international Uruguayan number with a default country of AR' => ['+598 99 123 456', 'AR', '+59899123456'],
            'country that the plugin does not know' => ['11 1234 5678', 'ZZ', null],
            'empty country and no international prefix' => ['612345678', '', null],
        ];
    }

    /**
     * Tests the generic treatment given to every country other than Argentina.
     *
     * @dataProvider generic_numbers_provider
     * @param string $raw The number as the user typed it.
     * @param string $country The ISO 3166-1 alpha-2 code of the default country.
     * @param string|null $expected The expected E.164 number, or null when it cannot be normalised.
     */
    public function test_normalize_generic(string $raw, string $country, ?string $expected): void {
        $this->assertSame($expected, phone::normalize($raw, $country));
    }

    /**
     * Tests that is_mobile() answers only for the countries the plugin has rules for.
     */
    public function test_is_mobile_only_answers_for_argentina(): void {
        $this->assertTrue(phone::is_mobile('+5491112345678'));
        $this->assertFalse(phone::is_mobile('+541112345678'));
        $this->assertNull(phone::is_mobile(null));
        $this->assertNull(phone::is_mobile('+59899123456'));
        $this->assertNull(phone::is_mobile('+5411'));
    }

    /**
     * Tests that normalising an already normalised number changes nothing, which the queue relies on.
     */
    public function test_normalize_is_idempotent(): void {
        foreach (self::ar_numbers_provider() as $case) {
            [$raw, $expected] = $case;

            if ($expected === null) {
                continue;
            }

            $this->assertSame($expected, phone::normalize($expected, 'AR'), "Not idempotent for '{$raw}'.");
        }
    }
}
