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
 * Definition of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/message/output/lib.php');

use message_whatsapp\form\preferences_form;
use message_whatsapp\local\queue;
use message_whatsapp\local\recipient;

/**
 * The WhatsApp message processor.
 *
 * This is the entry point Moodle calls for every message routed to the WhatsApp output. Delivery never happens
 * here: the processor only writes to a database queue that a scheduled task drains, because send_message() runs
 * inside the web request of the user that triggered the event and must not perform any network access.
 *
 * The phone number and the opt-in of each user live in message_whatsapp_user and are edited from the processor
 * settings dialogue of the notification preferences page. The queue and the transports are added by the later
 * tasks of the plan, so send_message() still drops what it receives.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_output_whatsapp extends message_output {
    /** Direct mode: the site owns its Meta Cloud API credentials and talks to Graph API. */
    const MODE_DIRECT = 'direct';

    /** Gateway mode: the site talks to the wa-gateway service with an API key. */
    const MODE_GATEWAY = 'gateway';

    /**
     * Processes a message sent to this output by writing it to the queue.
     *
     * This runs inside the web request of whoever triggered the event, so it does three things and no more: it
     * decides whether the message belongs on this channel at all, it writes one row, and it gets out of the way.
     * There is no network access here, by design and by the architecture: talking to Meta from a page request would
     * make an unrelated page as slow and as fragile as the Graph API is that minute.
     *
     * It returns true whatever happens, and it lets nothing escape. A false return would make core treat the
     * message as undelivered, and an exception would break the page of the user that triggered the event, who has
     * nothing to do with WhatsApp. The failure is written to the debug log instead, and only the message of the
     * exception is written there, never its debug info: for a database error that would carry the SQL and its
     * parameters, and one of those parameters is a phone number.
     *
     * Only notifications are queued. Personal messages between users are out of scope of this release: a template
     * is a poor fit for a conversation, and everything sent has to be an approved template.
     *
     * @param stdClass $message The event data submitted by the message provider plus $message->savedmessageid.
     * @return bool Always true.
     */
    public function send_message($message) {
        try {
            if (!is_object($message) || (int) ($message->notification ?? 0) !== 1) {
                return true;
            }

            // A site with no sending mode chosen cannot deliver anything, now or later, so queueing would only pile
            // up rows nobody will ever drain. Core filters unconfigured processors out too; this is the second
            // barrier, for the same reason the opt-in has one.
            if (!$this->is_system_configured()) {
                return true;
            }

            // Core builds this with get_eventobject_for_processor() and it is always a plain stdClass, but the
            // signature of the parent declares the union, so anything else is flattened instead of trusted.
            queue::enqueue($message instanceof stdClass ? $message : (object) get_object_vars($message));
        } catch (Throwable $e) {
            debugging('message_whatsapp: the notification could not be queued: ' . $e->getMessage(), DEBUG_NORMAL);
        }

        return true;
    }

    /**
     * Loads the user preferences of this processor into the messaging preferences page.
     *
     * Resolving the recipient here is what creates the row of a user that has never opened these preferences, and
     * it is the only place that does: the phone number of the profile is read once, when the user is about to be
     * shown what the site found out about them. The row is created without consent.
     *
     * Core declares this parameter as an array but always hands over the stdClass built by
     * \core_message\api::get_all_message_preferences(), so both shapes are filled in.
     *
     * @param array|stdClass $preferences Array of user preferences, passed by reference.
     * @param int $userid The id of the user whose preferences are being loaded.
     * @return bool Always true.
     */
    public function load_data(&$preferences, $userid) {
        $recipient = recipient::resolve((int) $userid);

        $values = [
            preferences_form::FIELD_USERID => (int) $userid,
            preferences_form::FIELD_PHONE => $recipient ? $recipient->phone : '',
            preferences_form::FIELD_OPTIN => ($recipient && $recipient->optin) ? 1 : 0,
            'whatsapp_invalid' => ($recipient && $recipient->is_invalid()) ? 1 : 0,
            'whatsapp_landline' => ($recipient && $recipient->looks_like_landline()) ? 1 : 0,
        ];

        foreach ($values as $key => $value) {
            if (is_array($preferences)) {
                $preferences[$key] = $value;
            } else {
                $preferences->{$key} = $value;
            }
        }

        return true;
    }

    /**
     * Builds the fields this processor adds to the messaging preferences page.
     *
     * @param array|stdClass $preferences An array of user preferences.
     * @return string|null The HTML of the configuration fields, null when the processor has none.
     */
    public function config_form($preferences) {
        return preferences_form::render($preferences);
    }

    /**
     * Parses the submitted preferences form and stores the phone number and the consent.
     *
     * The values are written to message_whatsapp_user instead of to the preferences array: the phone number and the
     * consent are personal data that the privacy provider and the admin report have to reach by user, and the queue
     * reads them on every send. Keeping a second copy in user_preferences would only add a place to forget.
     *
     * @param stdClass $form The submitted preferences form data.
     * @param array $preferences The preferences array, passed by reference.
     * @return bool Always true.
     */
    public function process_form($form, &$preferences) {
        $userid = (int) ($form->{preferences_form::FIELD_USERID} ?? 0);
        if ($userid <= 0) {
            return true;
        }

        if (!$this->can_edit_preferences_of($userid)) {
            throw new moodle_exception('nopermissions', 'error', '', 'message_whatsapp: edit message profile');
        }

        $recipient = recipient::resolve($userid);
        if ($recipient === null) {
            return true;
        }

        $recipient->set_phone((string) ($form->{preferences_form::FIELD_PHONE} ?? $recipient->phone));
        $recipient->set_optin(!empty($form->{preferences_form::FIELD_OPTIN}));

        return true;
    }

    /**
     * Checks that the current user may write the messaging preferences of the given user.
     *
     * The user id travels in a hidden field of a form that the browser can rewrite, so it is checked again here.
     * Core validates the id it received itself, but never passes it to this method, and the two can differ. The
     * question is answered by the same core function that decides who may open this dialogue in the first place.
     *
     * @param int $userid The id of the user whose preferences are about to be written.
     * @return bool True when the current user may write them.
     */
    private function can_edit_preferences_of(int $userid): bool {
        global $CFG;

        // Loaded here and not at the top of the file: this is the only path that needs it.
        require_once($CFG->dirroot . '/message/lib.php');

        $user = core_user::get_user($userid);

        return $user && core_message_can_edit_message_profile($user);
    }

    /**
     * Checks whether the site administrator has configured this processor.
     *
     * A sending mode must be chosen before the processor can do anything at all. The check of the credentials
     * of each mode is added together with the settings that hold them.
     *
     * @return bool True when the processor has a usable site configuration.
     */
    public function is_system_configured() {
        $mode = get_config('message_whatsapp', 'mode');
        return in_array($mode, [self::MODE_DIRECT, self::MODE_GATEWAY], true);
    }

    /**
     * Checks whether a given user can receive messages through this processor.
     *
     * A user is only reachable with a usable phone number and an explicit opt-in. This never creates the row of a
     * user that has none: core asks this question while a message is being delivered, and no row means no consent,
     * so there is nothing to find out and nothing to write.
     *
     * @param stdClass|null $user The user object, defaults to $USER.
     * @return bool True when the user has opted in with a valid phone number.
     */
    public function is_user_configured($user = null) {
        global $USER;

        $user = $user ?? $USER;
        if (empty($user->id)) {
            return false;
        }

        $recipient = recipient::find((int) $user->id);

        return $recipient !== null && $recipient->is_sendable();
    }

    /**
     * Returns the default messaging settings of this processor as a bit mask.
     *
     * The processor is permitted but never forced on: sending a WhatsApp message has a cost and requires the
     * explicit consent of the user, so it is the user who turns it on.
     *
     * @return int MESSAGE_PERMITTED.
     */
    public function get_default_messaging_settings() {
        return MESSAGE_PERMITTED;
    }

    /**
     * Returns whether this processor exposes configurable preferences to the user.
     *
     * @return bool Always true.
     */
    public function has_message_preferences() {
        return true;
    }

    /**
     * Returns whether messages can be sent to fake or internal users.
     *
     * WhatsApp needs a real phone number that belongs to a real user, so this is never allowed.
     *
     * @return bool Always false.
     */
    public function can_send_to_any_users() {
        return false;
    }
}
