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
$string['privacy:metadata:message_whatsapp_click'] = 'One row for every time a user followed the link on the button of a WhatsApp message that had been sent to them.';
$string['privacy:metadata:message_whatsapp_click:queueid'] = 'The queued message whose button was clicked. A click carries no user of its own: it belongs to the person that message was sent to.';
$string['privacy:metadata:message_whatsapp_click:timeclicked'] = 'The time the button was clicked.';
$string['privacy:metadata:message_whatsapp_click:useragent'] = 'The browser and device that followed the link, as they announced themselves.';
$string['privacy:metadata:message_whatsapp_queue'] = 'One row for every notification queued for WhatsApp, including the ones that were never sent and the reason why.';
$string['privacy:metadata:message_whatsapp_queue:attempts'] = 'How many delivery attempts have been made.';
$string['privacy:metadata:message_whatsapp_queue:component'] = 'The part of Moodle the notification came from, for example mod_assign.';
$string['privacy:metadata:message_whatsapp_queue:courseid'] = 'The course the notification came from, or 0 when it was sent site wide. It records where the message originated; the message itself belongs to the person it was addressed to.';
$string['privacy:metadata:message_whatsapp_queue:error'] = 'The last delivery error, when there was one.';
$string['privacy:metadata:message_whatsapp_queue:lang'] = 'The language version of the template that was used.';
$string['privacy:metadata:message_whatsapp_queue:name'] = 'The kind of notification within that part of Moodle, for example assign_notification.';
$string['privacy:metadata:message_whatsapp_queue:nextattempt'] = 'The time the next delivery attempt may happen, used by the retries and by the quiet hours.';
$string['privacy:metadata:message_whatsapp_queue:params'] = 'The text put into the template: the short name of the site, the subject of the notification and its summary.';
$string['privacy:metadata:message_whatsapp_queue:phone'] = 'The number the message was addressed to, copied when the message was queued so that a later change of number does not rewrite what was already sent.';
$string['privacy:metadata:message_whatsapp_queue:pricingcategory'] = 'The billing category WhatsApp reported for the message.';
$string['privacy:metadata:message_whatsapp_queue:providermsgid'] = 'The identifier WhatsApp gave the message, used to match the delivery reports that come back.';
$string['privacy:metadata:message_whatsapp_queue:savedmessageid'] = 'The identifier of the notification as Moodle saved it, when Moodle saved one.';
$string['privacy:metadata:message_whatsapp_queue:status'] = 'Whether the message is waiting, was sent, was delivered, was read, failed or was skipped.';
$string['privacy:metadata:message_whatsapp_queue:templatekey'] = 'The name of the approved WhatsApp template the message was sent as.';
$string['privacy:metadata:message_whatsapp_queue:timecreated'] = 'The time the message was put in the queue.';
$string['privacy:metadata:message_whatsapp_queue:timesent'] = 'The time WhatsApp accepted the message.';
$string['privacy:metadata:message_whatsapp_queue:timestatus'] = 'The time of the last change of delivery status.';
$string['privacy:metadata:message_whatsapp_queue:url'] = 'The address the button of the message points at, normally the page of the site the notification is about.';
$string['privacy:metadata:message_whatsapp_queue:userid'] = 'The user the notification was addressed to.';
$string['privacy:metadata:message_whatsapp_user'] = 'The WhatsApp number of each user and their consent to be notified there. Without a row giving that consent, nothing is ever sent to that user.';
$string['privacy:metadata:message_whatsapp_user:optin'] = 'Whether the user consented to receive their notifications on WhatsApp.';
$string['privacy:metadata:message_whatsapp_user:optintime'] = 'The time the consent was last given or withdrawn, kept as the evidence of that consent.';
$string['privacy:metadata:message_whatsapp_user:phone'] = 'The phone number in international format, empty when none could be worked out.';
$string['privacy:metadata:message_whatsapp_user:source'] = 'Where the number came from: a profile field, a custom profile field, or typed by the user.';
$string['privacy:metadata:message_whatsapp_user:status'] = 'Whether the number is usable, was rejected as invalid, or was blocked by the user.';
$string['privacy:metadata:message_whatsapp_user:timecreated'] = 'The time the number was first recorded.';
$string['privacy:metadata:message_whatsapp_user:timemodified'] = 'The time the number or the consent last changed.';
$string['privacy:metadata:message_whatsapp_user:userid'] = 'The user the number and the consent belong to.';
$string['privacy:metadata:message_whatsapp_user:verified'] = 'Whether the number passed the verification by code.';
$string['privacy:metadata:message_whatsapp_user:verifiedtime'] = 'The time the number was verified.';
$string['privacy:metadata:meta_cloud_api'] = 'In direct mode this site sends each notification to the WhatsApp Cloud API of Meta, which delivers it to the phone of the user. Only the fields listed below leave the site, and Meta keeps the message and its delivery status under its own terms.';
$string['privacy:metadata:meta_cloud_api:lang'] = 'The language version of that template.';
$string['privacy:metadata:meta_cloud_api:params'] = 'The text put into the template: the short name of the site, the subject of the notification and its summary.';
$string['privacy:metadata:meta_cloud_api:phone'] = 'The phone number the message is delivered to.';
$string['privacy:metadata:meta_cloud_api:templatekey'] = 'The name of the approved template the message is sent as.';
$string['privacy:metadata:meta_cloud_api:url'] = 'The address the button of the message points at.';
$string['privacy:metadata:wa_gateway'] = 'In gateway mode this site sends each notification to the WhatsApp gateway service instead, which passes it on to Meta on behalf of the site and reports the delivery status back. The same fields leave the site, plus the queue number of the message.';
$string['privacy:metadata:wa_gateway:lang'] = 'The language version of that template.';
$string['privacy:metadata:wa_gateway:params'] = 'The text put into the template: the short name of the site, the subject of the notification and its summary.';
$string['privacy:metadata:wa_gateway:phone'] = 'The phone number the message is delivered to.';
$string['privacy:metadata:wa_gateway:queueid'] = 'The number of the queued message, sent as the idempotency key so that a request repeated after a network failure is not delivered twice.';
$string['privacy:metadata:wa_gateway:templatekey'] = 'The name of the approved template the message is sent as.';
$string['privacy:metadata:wa_gateway:url'] = 'The address the button of the message points at.';
$string['privacy:path'] = 'WhatsApp notifications';
$string['privacy:path:messages'] = 'Messages';
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
