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
 * Recipient resolution and opt-in for the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\local;

use core_user;
use dml_exception;
use stdClass;

/**
 * The WhatsApp side of a Moodle user: the phone number, where it came from and the consent to use it.
 *
 * One row of `message_whatsapp_user` per user, created the first time the user is resolved. The row is what holds
 * the opt-in, so it is also the answer to "may this site send anything to this person at all": no row means no
 * consent, and {@see self::find()} therefore never writes, so the send path stays read only.
 *
 * The phone number is personal data under Ley 25.326. It is never written to a log, never put into an exception
 * message and never shown to anybody other than its owner or a user who may already edit their message profile.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recipient {

    /** Number taken from the phone1 field of the user profile. */
    public const SOURCE_PROFILE1 = 'profile1';

    /** Number taken from the phone2 field of the user profile. */
    public const SOURCE_PROFILE2 = 'profile2';

    /** Number taken from a custom profile field. Reserved for the phase that adds that setting. */
    public const SOURCE_CUSTOM = 'custom';

    /** Number typed by the user in the notification preferences, or no number at all. */
    public const SOURCE_MANUAL = 'manual';

    /** The number can be used. */
    public const STATUS_ACTIVE = 'active';

    /** The number is unusable: it could not be normalised, or the provider rejected it. */
    public const STATUS_INVALID = 'invalid';

    /** The user blocked the sender on WhatsApp. */
    public const STATUS_BLOCKED = 'blocked';

    /** Country assumed when neither the plugin nor the site says which one to use. */
    public const FALLBACK_COUNTRY = 'AR';

    /** Profile field the phone number comes from until an administrator says otherwise. */
    public const DEFAULT_PHONE_SOURCE = 'phone2';

    /** @var int Id of the message_whatsapp_user row. */
    public int $id = 0;

    /** @var int Id of the user this row belongs to. */
    public int $userid = 0;

    /** @var string Phone number in E.164, or the empty string when no usable number is known. */
    public string $phone = '';

    /** @var string Where the number came from: one of the SOURCE_* constants. */
    public string $source = self::SOURCE_MANUAL;

    /** @var bool Whether the user consented to receive notifications on WhatsApp. */
    public bool $optin = false;

    /** @var int Time of the last change of the consent, kept as evidence of it. */
    public int $optintime = 0;

    /** @var bool Whether the number passed the OTP verification. Informative until that phase ships. */
    public bool $verified = false;

    /** @var string Row status: one of the STATUS_* constants. */
    public string $status = self::STATUS_ACTIVE;

    /**
     * Private on purpose: a recipient only ever comes from the database, through find() or resolve().
     */
    private function __construct() {
    }

    /**
     * Returns the stored recipient of a user without creating anything.
     *
     * This is the read only entry point, used on the send path and by is_user_configured(): a user with no row has
     * given no consent, so there is never a reason to write one while a message is being delivered.
     *
     * @param int $userid The id of the user.
     * @return self|null The stored recipient, or null when the user has no row yet.
     */
    public static function find(int $userid): ?self {
        global $DB;

        $record = $DB->get_record('message_whatsapp_user', ['userid' => $userid]);

        return $record ? self::from_record($record) : null;
    }

    /**
     * Returns the recipient of a user, creating the row from the user profile the first time.
     *
     * When there is no row yet the phone number is looked up in the profile field named by the `phonesource`
     * setting and normalised to E.164. Whatever the outcome, the row is created with `optin = 0`: detecting a
     * number is not consent, and nothing is ever sent until the user ticks the box in their preferences.
     *
     * @param int $userid The id of the user.
     * @return self|null The recipient, or null when the user cannot hold one (missing, deleted, guest or not real).
     */
    public static function resolve(int $userid): ?self {
        global $DB;

        $existing = self::find($userid);
        if ($existing !== null) {
            return $existing;
        }

        $user = core_user::get_user($userid);
        if (!$user || !empty($user->deleted) || !core_user::is_real_user($userid) || isguestuser($user)) {
            return null;
        }

        $detected = self::detect_from_profile($user);
        $now = time();

        $record = (object) [
            // The five CHAR NOT NULL columns of this schema carry no default, so every one of them is set here.
            'userid' => $userid,
            'phone' => $detected->phone,
            'source' => $detected->source,
            'optin' => 0,
            'optintime' => 0,
            'verified' => 0,
            'verifiedtime' => 0,
            'status' => $detected->status,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        try {
            $record->id = $DB->insert_record('message_whatsapp_user', $record);
        } catch (dml_exception $e) {
            // Two concurrent requests can both find no row; userid is unique, so the loser just reads the winner.
            return self::find($userid);
        }

        return self::from_record($record);
    }

    /**
     * Builds a recipient from a database record.
     *
     * @param stdClass $record A row of message_whatsapp_user.
     * @return self The recipient.
     */
    private static function from_record(stdClass $record): self {
        $recipient = new self();
        $recipient->id = (int) $record->id;
        $recipient->userid = (int) $record->userid;
        $recipient->phone = (string) $record->phone;
        $recipient->source = (string) $record->source;
        $recipient->optin = !empty($record->optin);
        $recipient->optintime = (int) $record->optintime;
        $recipient->verified = !empty($record->verified);
        $recipient->status = (string) $record->status;

        return $recipient;
    }

    /**
     * Reads the phone number of a user profile according to the `phonesource` setting and normalises it.
     *
     * A profile field that holds something unusable is reported as invalid rather than as empty, so that the
     * preferences form can tell the user that their number was not understood instead of staying silent.
     *
     * @param stdClass $user The user record, with the profile fields loaded.
     * @return stdClass Object with the `phone`, `source` and `status` the new row has to be created with.
     */
    private static function detect_from_profile(stdClass $user): stdClass {
        $sources = [
            'phone1' => self::SOURCE_PROFILE1,
            'phone2' => self::SOURCE_PROFILE2,
        ];

        $empty = (object) [
            'phone' => '',
            'source' => self::SOURCE_MANUAL,
            'status' => self::STATUS_ACTIVE,
        ];

        // An unset setting is false, which is not the same as the empty string an administrator stores to turn the
        // lookup off, so the documented default is applied here and not by treating both as one.
        $field = get_config('message_whatsapp', 'phonesource');
        $field = ($field === false || $field === null) ? self::DEFAULT_PHONE_SOURCE : (string) $field;

        if (!isset($sources[$field])) {
            return $empty;
        }

        $raw = trim((string) ($user->{$field} ?? ''));
        if ($raw === '') {
            return $empty;
        }

        $e164 = phone::normalize($raw, self::default_country());
        if ($e164 === null) {
            return (object) [
                'phone' => '',
                'source' => $sources[$field],
                'status' => self::STATUS_INVALID,
            ];
        }

        return (object) [
            'phone' => $e164,
            'source' => $sources[$field],
            'status' => self::STATUS_ACTIVE,
        ];
    }

    /**
     * Returns the country whose dialling rules apply to a number typed without an international prefix.
     *
     * @return string ISO 3166-1 alpha-2 country code, always non empty.
     */
    public static function default_country(): string {
        global $CFG;

        $country = trim((string) get_config('message_whatsapp', 'defaultcountry'));
        if ($country === '') {
            $country = trim((string) ($CFG->country ?? ''));
        }

        return $country === '' ? self::FALLBACK_COUNTRY : strtoupper($country);
    }

    /**
     * Stores a phone number typed by the user, normalising it first.
     *
     * An empty value clears the number, which is how a user stops using the channel without touching the consent.
     * A value that cannot be normalised clears it as well but leaves the row marked invalid, so that the form can
     * say that the number was not understood instead of pretending that the user never typed one.
     *
     * @param string $raw The number as the user typed it.
     * @return bool True when the number was stored or deliberately cleared, false when it could not be normalised.
     */
    public function set_phone(string $raw): bool {
        $raw = trim($raw);

        if ($raw === '') {
            $this->apply_phone('', self::SOURCE_MANUAL, self::STATUS_ACTIVE);
            return true;
        }

        $e164 = phone::normalize($raw, self::default_country());

        if ($e164 === null) {
            $this->apply_phone('', self::SOURCE_MANUAL, self::STATUS_INVALID);
            return false;
        }

        if ($e164 === $this->phone && $this->status !== self::STATUS_INVALID) {
            // The user submitted the form without touching the number, so the provenance of the row is kept.
            return true;
        }

        $this->apply_phone($e164, self::SOURCE_MANUAL, self::STATUS_ACTIVE);

        return true;
    }

    /**
     * Writes a resolved number into the row.
     *
     * @param string $phone The number in E.164, or the empty string.
     * @param string $source One of the SOURCE_* constants.
     * @param string $status One of the STATUS_* constants.
     * @return void
     */
    private function apply_phone(string $phone, string $source, string $status): void {
        if ($this->phone === $phone && $this->status === $status) {
            return;
        }

        $this->phone = $phone;
        $this->source = $source;
        $this->status = $status;
        $this->save();
    }

    /**
     * Stores the consent of the user, keeping the time of the change as evidence of it.
     *
     * @param bool $optin True when the user consents to receive notifications on WhatsApp.
     * @return void
     */
    public function set_optin(bool $optin): void {
        if ($this->optin === $optin) {
            return;
        }

        $this->optin = $optin;
        $this->optintime = time();
        $this->save();
    }

    /**
     * Tells whether this user may be sent a notification right now.
     *
     * Deliberately does not look at whether the number is a mobile: mobility is decided by a marker the user may
     * have left out, so refusing to queue would drop notifications without a trace. A number that WhatsApp cannot
     * reach produces a failed queue row with the error of the provider, which the admin report shows.
     *
     * @return bool True when there is consent and a usable number.
     */
    public function is_sendable(): bool {
        return $this->optin && $this->phone !== '' && $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Tells whether the stored number is a landline, which WhatsApp cannot deliver to.
     *
     * Only an explicit false from the normaliser counts: null there means "unknown", not "landline".
     *
     * @return bool True when the number is known to be a landline.
     */
    public function looks_like_landline(): bool {
        return phone::is_mobile($this->phone === '' ? null : $this->phone) === false;
    }

    /**
     * Tells whether the last number seen for this user could not be understood.
     *
     * @return bool True when the row is marked invalid.
     */
    public function is_invalid(): bool {
        return $this->status === self::STATUS_INVALID;
    }

    /**
     * Persists the mutable part of the row.
     *
     * @return void
     */
    private function save(): void {
        global $DB;

        $DB->update_record('message_whatsapp_user', (object) [
            'id' => $this->id,
            'phone' => $this->phone,
            'source' => $this->source,
            'optin' => $this->optin ? 1 : 0,
            'optintime' => $this->optintime,
            'status' => $this->status,
            'timemodified' => time(),
        ]);
    }
}
