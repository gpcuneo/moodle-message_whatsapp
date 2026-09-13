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
 * Tests for the notification preferences of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\form\preferences_form;
use message_whatsapp\local\recipient;
use message_output_whatsapp;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');

/**
 * Tests for the notification preferences of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_output_whatsapp
 * @covers     \message_whatsapp\form\preferences_form
 */
final class preferences_test extends \advanced_testcase {
    /**
     * Every test starts from a site that looks numbers up in phone2 and assumes Argentina.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('phonesource', 'phone2', 'message_whatsapp');
        set_config('defaultcountry', 'AR', 'message_whatsapp');
    }

    /**
     * Builds the dialogue of a user the way core does: load_data() first, config_form() with what it filled in.
     *
     * @param \stdClass $user The user whose dialogue is wanted.
     * @return string The HTML of the fields.
     */
    private function render_for(\stdClass $user): string {
        $processor = new message_output_whatsapp();
        $preferences = new \stdClass();
        $processor->load_data($preferences, $user->id);

        return $processor->config_form($preferences);
    }

    /**
     * The dialogue shows the number found in the profile, already normalised, with the consent still off.
     *
     * @return void
     */
    public function test_the_dialogue_shows_the_detected_number(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);

        $html = $this->render_for($user);

        $this->assertStringContainsString('value="+5491112345678"', $html);
        $this->assertStringContainsString('name="' . preferences_form::FIELD_OPTIN . '"', $html);
        $this->assertStringNotContainsString('checked="checked"', $html);
        $this->assertStringNotContainsString(get_string('prefphonelandline', 'message_whatsapp'), $html);
        $this->assertStringNotContainsString(get_string('prefnophone', 'message_whatsapp'), $html);
    }

    /**
     * A number that normalises to a landline is called out, because WhatsApp can never deliver to it.
     *
     * This is the visible half of the decision that mobility is only ever decided by an explicit 15 or 9: a user
     * who typed their mobile without the marker has to be told, and told how to fix it, not left thinking that the
     * channel works.
     *
     * @return void
     */
    public function test_a_landline_is_reported_with_a_way_out(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 4123-4567']);

        $html = $this->render_for($user);

        $this->assertStringContainsString('value="+541141234567"', $html);
        $this->assertStringContainsString(get_string('prefphonelandline', 'message_whatsapp'), $html);
        $this->assertStringContainsString('alert-warning', $html);
    }

    /**
     * A profile number that could not be read is reported as such, not as a user who never had one.
     *
     * @return void
     */
    public function test_an_unreadable_number_is_reported(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '0800 555 1234']);

        $html = $this->render_for($user);

        $this->assertStringContainsString(get_string('prefphoneinvalid', 'message_whatsapp'), $html);
        $this->assertStringNotContainsString(get_string('prefnophone', 'message_whatsapp'), $html);
    }

    /**
     * A number the provider refused gets a notice of its own, and keeps being shown so it can be corrected.
     *
     * The same invalid mark covers two different problems. Telling a user whose number is perfectly readable that
     * it "was not understood" would send them to retype the very number that is already there.
     *
     * @return void
     */
    public function test_a_number_the_provider_refused_is_reported_as_such(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234 5678']);
        recipient::resolve((int) $user->id)->mark_undeliverable();

        $html = $this->render_for($user);

        $this->assertStringContainsString(get_string('prefphoneundeliverable', 'message_whatsapp'), $html);
        $this->assertStringNotContainsString(get_string('prefphoneinvalid', 'message_whatsapp'), $html);
        // The number stays on the screen: it is what the user has to look at to see what is wrong with it.
        $this->assertStringContainsString('+5491112345678', $html);
    }

    /**
     * A user with nothing in the profile is invited to type a number.
     *
     * @return void
     */
    public function test_a_user_with_no_number_is_invited_to_type_one(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '']);

        $html = $this->render_for($user);

        $this->assertStringContainsString(get_string('prefnophone', 'message_whatsapp'), $html);
        $this->assertStringNotContainsString(get_string('prefphonelandline', 'message_whatsapp'), $html);
    }

    /**
     * Saving the dialogue stores the corrected number and the consent in the row of the user.
     *
     * @return void
     */
    public function test_saving_the_dialogue_stores_the_number_and_the_consent(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 4123-4567']);
        $this->setUser($user);

        $processor = new message_output_whatsapp();
        $preferences = [];
        $processor->process_form((object) [
            preferences_form::FIELD_USERID => $user->id,
            preferences_form::FIELD_PHONE => '011 15 1234-5678',
            preferences_form::FIELD_OPTIN => '1',
        ], $preferences);

        $recipient = recipient::find($user->id);

        $this->assertSame('+5491112345678', $recipient->phone);
        $this->assertTrue($recipient->optin);
        $this->assertTrue($recipient->is_sendable());
        $this->assertTrue($processor->is_user_configured($user));
    }

    /**
     * An unticked checkbox is not submitted at all, and that is what withdrawing the consent looks like.
     *
     * @return void
     */
    public function test_saving_the_dialogue_without_the_checkbox_withdraws_the_consent(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        $this->setUser($user);

        $processor = new message_output_whatsapp();
        $preferences = [];
        $submitted = (object) [
            preferences_form::FIELD_USERID => $user->id,
            preferences_form::FIELD_PHONE => '+5491112345678',
            preferences_form::FIELD_OPTIN => '1',
        ];
        $processor->process_form($submitted, $preferences);
        $this->assertTrue(recipient::find($user->id)->optin);

        unset($submitted->{preferences_form::FIELD_OPTIN});
        $processor->process_form($submitted, $preferences);

        $this->assertFalse(recipient::find($user->id)->optin);
        $this->assertFalse($processor->is_user_configured($user));
    }

    /**
     * The user id travels in a hidden field, so a user who rewrites it gets nowhere.
     *
     * @return void
     */
    public function test_the_hidden_user_id_cannot_be_pointed_at_somebody_else(): void {
        $attacker = $this->getDataGenerator()->create_user();
        $victim = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        $this->setUser($attacker);

        $processor = new message_output_whatsapp();
        $preferences = [];

        $this->expectException(\moodle_exception::class);
        $processor->process_form((object) [
            preferences_form::FIELD_USERID => $victim->id,
            preferences_form::FIELD_PHONE => '011 15 9999-9999',
            preferences_form::FIELD_OPTIN => '1',
        ], $preferences);
    }

    /**
     * is_user_configured() answers from the stored row only; it never creates one on the send path.
     *
     * @return void
     */
    public function test_is_user_configured_does_not_write(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        $processor = new message_output_whatsapp();

        $this->assertFalse($processor->is_user_configured($user));
        $this->assertSame(0, $DB->count_records('message_whatsapp_user'));
    }
}
