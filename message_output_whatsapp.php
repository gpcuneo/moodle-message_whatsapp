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
 * @copyright  2026 Gustavo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/message/output/lib.php');

/**
 * The WhatsApp message processor.
 *
 * This is the entry point Moodle calls for every message routed to the WhatsApp output. Delivery never happens
 * here: the processor only writes to a database queue that a scheduled task drains, because send_message() runs
 * inside the web request of the user that triggered the event and must not perform any network access.
 *
 * This release is a skeleton. Every method returns a neutral value and nothing is sent, stored or queued yet.
 * The queue, the recipient resolution and the transports are added by the later tasks of the plan.
 *
 * @package    message_whatsapp
 * @copyright  2026 Gustavo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_output_whatsapp extends message_output {

    /** Direct mode: the site owns its Meta Cloud API credentials and talks to Graph API. */
    const MODE_DIRECT = 'direct';

    /** Gateway mode: the site talks to the wa-gateway service with an API key. */
    const MODE_GATEWAY = 'gateway';

    /**
     * Processes a message sent to this output.
     *
     * Always returns true so that a failure of this processor can never block the delivery of the same message
     * through the other outputs the user has enabled.
     *
     * @param stdClass $message The event data submitted by the message provider plus $message->savedmessageid.
     * @return bool Always true.
     */
    public function send_message($message) {
        // Skeleton: the queue is not implemented yet, so the message is deliberately dropped here.
        return true;
    }

    /**
     * Loads the user preferences of this processor into the messaging preferences page.
     *
     * @param array $preferences Array of user preferences, passed by reference.
     * @param int $userid The id of the user whose preferences are being loaded.
     * @return bool Always true.
     */
    public function load_data(&$preferences, $userid) {
        // Skeleton: there is no message_whatsapp_user table yet, so there is nothing to load.
        return true;
    }

    /**
     * Builds the fields this processor adds to the messaging preferences page.
     *
     * @param array $preferences An array of user preferences.
     * @return string|null The HTML of the configuration fields, null when the processor has none.
     */
    public function config_form($preferences) {
        // Skeleton: the phone number and opt-in fields are not implemented yet.
        return null;
    }

    /**
     * Parses the submitted preferences form and stores the values in the preferences array.
     *
     * @param stdClass $form The submitted preferences form data.
     * @param array $preferences The preferences array, passed by reference.
     * @return bool Always true.
     */
    public function process_form($form, &$preferences) {
        // Skeleton: there is nothing to persist yet.
        return true;
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
     * A user is only reachable with a valid phone number and an explicit opt-in, neither of which is stored yet,
     * so nothing is ever sent in this release.
     *
     * @param stdClass|null $user The user object, defaults to $USER.
     * @return bool True when the user has opted in with a valid phone number.
     */
    public function is_user_configured($user = null) {
        // Skeleton: without the opt-in table no user can be considered reachable.
        return false;
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
