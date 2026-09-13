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
 * Tests for the recipient resolution of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\recipient;

/**
 * Tests for the recipient resolution of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\local\recipient
 */
final class recipient_test extends \advanced_testcase {

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
     * With no row yet, the number of the configured profile field is normalised into a brand new row.
     *
     * @return void
     */
    public function test_resolve_creates_the_row_from_the_profile(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);

        $recipient = recipient::resolve($user->id);

        $this->assertInstanceOf(recipient::class, $recipient);
        $this->assertSame('+5491112345678', $recipient->phone);
        $this->assertSame(recipient::SOURCE_PROFILE2, $recipient->source);
        $this->assertSame(recipient::STATUS_ACTIVE, $recipient->status);
        $this->assertFalse($recipient->optin);
        $this->assertSame(1, $DB->count_records('message_whatsapp_user', ['userid' => $user->id]));
    }

    /**
     * Detecting a number is not consent: the new row is always created opted out.
     *
     * @return void
     */
    public function test_resolve_never_opts_the_user_in(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);

        $recipient = recipient::resolve($user->id);

        $this->assertFalse($recipient->optin);
        $this->assertFalse($recipient->is_sendable());
        $this->assertSame(0, (int) $DB->get_field('message_whatsapp_user', 'optin', ['userid' => $user->id]));
        $this->assertSame(0, (int) $DB->get_field('message_whatsapp_user', 'optintime', ['userid' => $user->id]));
    }

    /**
     * An existing row wins over the profile: the number the user corrected is never overwritten from the profile.
     *
     * @return void
     */
    public function test_resolve_returns_the_existing_row_untouched(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        $now = time();

        $DB->insert_record('message_whatsapp_user', (object) [
            'userid' => $user->id,
            'phone' => '+5493514111111',
            'source' => recipient::SOURCE_MANUAL,
            'optin' => 1,
            'optintime' => $now,
            'verified' => 0,
            'verifiedtime' => 0,
            'status' => recipient::STATUS_ACTIVE,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $recipient = recipient::resolve($user->id);

        $this->assertSame('+5493514111111', $recipient->phone);
        $this->assertSame(recipient::SOURCE_MANUAL, $recipient->source);
        $this->assertTrue($recipient->optin);
        $this->assertTrue($recipient->is_sendable());
        $this->assertSame(1, $DB->count_records('message_whatsapp_user', ['userid' => $user->id]));
    }

    /**
     * Two calls in a row leave exactly one row behind.
     *
     * @return void
     */
    public function test_resolve_is_idempotent(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);

        $first = recipient::resolve($user->id);
        $second = recipient::resolve($user->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $DB->count_records('message_whatsapp_user', ['userid' => $user->id]));
    }

    /**
     * A user with nothing in the profile field still gets a row, so that the preferences form has one to write to.
     *
     * @return void
     */
    public function test_resolve_with_an_empty_profile_field(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '']);

        $recipient = recipient::resolve($user->id);

        $this->assertSame('', $recipient->phone);
        $this->assertSame(recipient::SOURCE_MANUAL, $recipient->source);
        $this->assertSame(recipient::STATUS_ACTIVE, $recipient->status);
        $this->assertFalse($recipient->is_invalid());
        $this->assertFalse($recipient->is_sendable());
    }

    /**
     * The setting decides which field is read, and phone1 is the other supported one.
     *
     * @return void
     */
    public function test_resolve_reads_phone1_when_the_setting_says_so(): void {
        set_config('phonesource', 'phone1', 'message_whatsapp');
        $user = $this->getDataGenerator()->create_user([
            'phone1' => '011 15 1234-5678',
            'phone2' => '011 15 9999-9999',
        ]);

        $recipient = recipient::resolve($user->id);

        $this->assertSame('+5491112345678', $recipient->phone);
        $this->assertSame(recipient::SOURCE_PROFILE1, $recipient->source);
    }

    /**
     * An administrator can turn the lookup off, and then no profile field is read at all.
     *
     * @return void
     */
    public function test_resolve_with_the_lookup_turned_off(): void {
        set_config('phonesource', '', 'message_whatsapp');
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);

        $recipient = recipient::resolve($user->id);

        $this->assertSame('', $recipient->phone);
        $this->assertSame(recipient::SOURCE_MANUAL, $recipient->source);
    }

    /**
     * An unset setting falls back to the documented default instead of behaving like the lookup were off.
     *
     * @return void
     */
    public function test_resolve_falls_back_to_the_default_source(): void {
        unset_config('phonesource', 'message_whatsapp');
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);

        $recipient = recipient::resolve($user->id);

        $this->assertSame('+5491112345678', $recipient->phone);
        $this->assertSame(recipient::SOURCE_PROFILE2, $recipient->source);
    }

    /**
     * A profile field that holds something unusable is reported as invalid, not as an empty profile.
     *
     * @return void
     */
    public function test_resolve_marks_an_unreadable_profile_number_invalid(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '0800 555 1234']);

        $recipient = recipient::resolve($user->id);

        $this->assertSame('', $recipient->phone);
        $this->assertSame(recipient::STATUS_INVALID, $recipient->status);
        $this->assertTrue($recipient->is_invalid());
        $this->assertSame(recipient::SOURCE_PROFILE2, $recipient->source);
    }

    /**
     * A mobile typed without the 15 normalises to a landline, which is what the preferences form has to warn about.
     *
     * @return void
     */
    public function test_resolve_flags_a_landline(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 4123-4567']);

        $recipient = recipient::resolve($user->id);

        $this->assertSame('+541141234567', $recipient->phone);
        $this->assertTrue($recipient->looks_like_landline());
    }

    /**
     * A number that is known to be a mobile is not flagged, and neither is an unknown one.
     *
     * @return void
     */
    public function test_a_mobile_and_an_unknown_number_are_not_flagged(): void {
        $mobile = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        $foreign = $this->getDataGenerator()->create_user(['phone2' => '+34 600 123 456']);

        $this->assertFalse(recipient::resolve($mobile->id)->looks_like_landline());
        $this->assertFalse(recipient::resolve($foreign->id)->looks_like_landline());
    }

    /**
     * find() is the read only door: it never creates a row, because the send path must not write one.
     *
     * @return void
     */
    public function test_find_never_creates_a_row(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);

        $this->assertNull(recipient::find($user->id));
        $this->assertSame(0, $DB->count_records('message_whatsapp_user', ['userid' => $user->id]));

        recipient::resolve($user->id);

        $this->assertInstanceOf(recipient::class, recipient::find($user->id));
    }

    /**
     * Users that cannot hold a phone number get no row and no exception.
     *
     * @return void
     */
    public function test_resolve_refuses_users_that_are_not_real(): void {
        global $DB;

        $deleted = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        delete_user($deleted);

        $this->assertNull(recipient::resolve($deleted->id));
        $this->assertNull(recipient::resolve(-1));
        $this->assertNull(recipient::resolve(0));
        $this->assertNull(recipient::resolve(99999999));
        $this->assertNull(recipient::resolve((int) $DB->get_field('user', 'id', ['username' => 'guest'])));
        $this->assertSame(0, $DB->count_records('message_whatsapp_user'));
    }

    /**
     * A number typed by the user is normalised, stored and marked as coming from the user.
     *
     * @return void
     */
    public function test_set_phone_stores_a_normalised_number(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 4123-4567']);
        $recipient = recipient::resolve($user->id);

        $this->assertTrue($recipient->set_phone('011 15 1234-5678'));

        $this->assertSame('+5491112345678', $recipient->phone);
        $this->assertSame(recipient::SOURCE_MANUAL, $recipient->source);
        $this->assertFalse($recipient->looks_like_landline());
        $this->assertSame('+5491112345678', $DB->get_field('message_whatsapp_user', 'phone', ['userid' => $user->id]));
    }

    /**
     * Submitting the dialogue without touching the number keeps the row exactly as it was, provenance included.
     *
     * @return void
     */
    public function test_set_phone_with_the_same_number_keeps_the_source(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        $recipient = recipient::resolve($user->id);

        $this->assertTrue($recipient->set_phone('+5491112345678'));

        $this->assertSame('+5491112345678', $recipient->phone);
        $this->assertSame(recipient::SOURCE_PROFILE2, $recipient->source);
    }

    /**
     * A number that cannot be normalised is rejected and leaves a trace, so the form can say what happened.
     *
     * @return void
     */
    public function test_set_phone_rejects_a_number_it_cannot_read(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        $recipient = recipient::resolve($user->id);

        $this->assertFalse($recipient->set_phone('no es un telefono'));

        $this->assertSame('', $recipient->phone);
        $this->assertTrue($recipient->is_invalid());
        $this->assertFalse($recipient->is_sendable());
    }

    /**
     * Clearing the field is a deliberate act, not an error: the number goes and the row goes back to active.
     *
     * @return void
     */
    public function test_set_phone_with_an_empty_value_clears_the_number(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        $recipient = recipient::resolve($user->id);
        $recipient->set_phone('no es un telefono');

        $this->assertTrue($recipient->set_phone('   '));

        $this->assertSame('', $recipient->phone);
        $this->assertSame(recipient::STATUS_ACTIVE, $recipient->status);
        $this->assertFalse($recipient->is_invalid());
    }

    /**
     * The consent is stored with the time of the change, and only when it actually changes.
     *
     * @return void
     */
    public function test_set_optin_records_when_the_consent_changed(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['phone2' => '011 15 1234-5678']);
        $recipient = recipient::resolve($user->id);

        $recipient->set_optin(true);

        $this->assertTrue($recipient->optin);
        $this->assertTrue($recipient->is_sendable());
        $this->assertGreaterThan(0, $recipient->optintime);
        $this->assertSame(1, (int) $DB->get_field('message_whatsapp_user', 'optin', ['userid' => $user->id]));

        $stamp = $recipient->optintime;
        $recipient->set_optin(true);
        $this->assertSame($stamp, $recipient->optintime);

        $recipient->set_optin(false);
        $this->assertFalse($recipient->optin);
        $this->assertFalse($recipient->is_sendable());
        $this->assertSame(0, (int) $DB->get_field('message_whatsapp_user', 'optin', ['userid' => $user->id]));
    }

    /**
     * Consent without a usable number is still not sendable.
     *
     * @return void
     */
    public function test_optin_without_a_number_is_not_sendable(): void {
        $user = $this->getDataGenerator()->create_user(['phone2' => '']);
        $recipient = recipient::resolve($user->id);

        $recipient->set_optin(true);

        $this->assertTrue($recipient->optin);
        $this->assertFalse($recipient->is_sendable());
    }

    /**
     * The country used for a number typed without a prefix comes from the plugin, then the site, then Argentina.
     *
     * @return void
     */
    public function test_default_country(): void {
        global $CFG;

        $this->assertSame('AR', recipient::default_country());

        set_config('defaultcountry', 'uy', 'message_whatsapp');
        $this->assertSame('UY', recipient::default_country());

        unset_config('defaultcountry', 'message_whatsapp');
        $CFG->country = 'ES';
        $this->assertSame('ES', recipient::default_country());

        $CFG->country = '';
        $this->assertSame(recipient::FALLBACK_COUNTRY, recipient::default_country());
    }
}
