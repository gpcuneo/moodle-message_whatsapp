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
 * Strings for component 'message_whatsapp', language 'en'.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['dailycap'] = 'Daily limit per user';
$string['dailycap_desc'] = 'Largest number of WhatsApp messages a single user may be sent in one day, counted in the time zone of the site. Anything over the limit is recorded as skipped and is never sent, so the limit trades notifications for cost: leave it at 0 for no limit.';
$string['defaultcountry'] = 'Default country';
$string['defaultcountry_desc'] = 'Country whose dialling rules apply to a number typed without an international prefix. A number that already starts with + is never touched by this setting.';
$string['deliverysettings'] = 'Delivery';
$string['deliverysettings_desc'] = 'When the queue is allowed to send, and how much it may send. Nothing here changes what is sent, only when.';
$string['generalsettings'] = 'WhatsApp notifications';
$string['generalsettings_desc'] = 'Sends Moodle notifications to WhatsApp as approved message templates, through a queue.';
$string['mode'] = 'Sending mode';
$string['mode_desc'] = 'How this site reaches WhatsApp. Nothing is sent until a mode is chosen and configured.';
$string['modedirect'] = 'Direct (own Meta Cloud API credentials)';
$string['modegateway'] = 'Gateway (WhatsApp gateway service)';
$string['phonesource'] = 'Phone number field';
$string['phonesource_desc'] = 'Profile field the phone number of a user is taken from the first time the site looks that user up. The user can always correct it in their notification preferences.';
$string['phonesourcenone'] = 'None, the user types the number';
$string['phonesourcephone1'] = 'Phone (phone1)';
$string['phonesourcephone2'] = 'Mobile phone (phone2)';
$string['pluginname'] = 'WhatsApp';
$string['prefnophone'] = 'No phone number was found in your profile. Type your mobile number here to receive notifications on WhatsApp.';
$string['prefoptin'] = 'Send my notifications to WhatsApp';
$string['prefoptin_desc'] = 'Nothing is sent to WhatsApp until you tick this box. Clear it at any time to stop receiving notifications there.';
$string['prefphone'] = 'WhatsApp phone number';
$string['prefphone_desc'] = 'Mobile number in international format, for example +54 9 11 1234-5678. Leave it empty to remove it.';
$string['prefphoneinvalid'] = 'The last number entered was not understood and has not been saved. Type it with the area code, for example 011 15 1234-5678.';
$string['prefphonelandline'] = 'This number looks like a landline, and WhatsApp cannot deliver to a landline. If it is a mobile, type it again with the 15 after the area code, as in 011 15 1234-5678, or in international format with the 9, as in +54 9 11 1234-5678.';
$string['privacy:metadata'] = 'The WhatsApp message processor does not store any personal data.';
$string['quietend'] = 'Quiet hours end';
$string['quietend_desc'] = 'Hour messages may be sent again from, in the time zone of the site.';
$string['quiethours'] = 'Respect quiet hours';
$string['quiethours_desc'] = 'Hold messages that come up during the night and send them when the quiet hours end. Nothing is discarded: a notification caught by the window leaves as soon as the window closes.';
$string['quietstart'] = 'Quiet hours start';
$string['quietstart_desc'] = 'Hour from which messages stop being sent, in the time zone of the site. A start and an end at the same hour mean no quiet hours at all.';
$string['sitenameshort'] = 'Short site name';
$string['sitenameshort_desc'] = 'Name of this site as it appears in the WhatsApp message. Leave it empty to use the short name of the site.';
$string['task:cleanup'] = 'Delete old WhatsApp queue entries';
$string['task:sendqueue'] = 'Send the pending WhatsApp messages';
$string['task:syncstatus'] = 'Fetch WhatsApp delivery statuses from the gateway';
$string['whatsapp:managesettings'] = 'Configure the WhatsApp notification channel';
$string['whatsapp:optinusers'] = 'Opt other users in to WhatsApp notifications';
$string['whatsapp:viewlog'] = 'View the WhatsApp delivery report';
