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

$string['batchsize'] = 'Messages per run';
$string['batchsize_desc'] = 'How many queued messages the sending task takes in one run. The task runs every minute, so 100 is 6000 messages an hour. Raise it only if the queue falls behind: a bigger batch makes a run longer, and a run that does not finish inside its minute is worse than a queue that is a minute behind.';
$string['dailycap'] = 'Daily limit per user';
$string['dailycap_desc'] = 'Largest number of WhatsApp messages a single user may be sent in one day, counted in the time zone of the site. Anything over the limit is recorded as skipped and is never sent, so the limit trades notifications for cost: leave it at 0 for no limit.';
$string['datasettings'] = 'Data retention';
$string['datasettings_desc'] = 'How long the record of what was sent is kept on this site. It has no effect on what WhatsApp keeps.';
$string['defaultcountry'] = 'Default country';
$string['defaultcountry_desc'] = 'Country whose dialling rules apply to a number typed without an international prefix. A number that already starts with + is never touched by this setting.';
$string['deliverysettings'] = 'Delivery';
$string['deliverysettings_desc'] = 'When the queue is allowed to send, and how much it may send. Nothing here changes what is sent, only when.';
$string['generalsettings'] = 'WhatsApp notifications';
$string['generalsettings_desc'] = 'Sends Moodle notifications to WhatsApp as approved message templates, through a queue.';
$string['graphversion'] = 'Graph API version';
$string['graphversion_desc'] = 'Version of the Graph API of Meta the requests are made against, written as v25.0. Leave it empty to use the version this plugin was written for. Raise it only after reading the changelog of Meta: an old version keeps working until Meta retires it.';
$string['logattempts'] = 'Attempts';
$string['logcomponent'] = 'Component';
$string['logentity'] = 'WhatsApp message';
$string['logerror'] = 'Last error';
$string['logintro'] = 'Every notification this site put in the WhatsApp queue, and what became of it. A message that failed can be queued again from the menu at the end of its row; nothing else on this page changes anything.';
$string['lognoresults'] = 'Nothing has been queued for WhatsApp on this site yet.';
$string['logpage'] = 'WhatsApp delivery report';
$string['logpage_desc'] = 'See what was queued for WhatsApp and what became of it, and queue a failed message again, on the <a href="{$a}">WhatsApp delivery report</a>.';
$string['logqueued'] = 'Queued';
$string['logreasondailycap'] = 'Not sent: the daily limit for this user had already been reached when the notification came up.';
$string['logreasoninvalidphone'] = 'Not sent: the provider will not deliver to the number recorded for this user, so nothing more is sent to it until the user saves another one.';
$string['logreasonnooptin'] = 'Not sent: this user has not consented to receiving notifications on WhatsApp.';
$string['logreasonorphaned'] = 'The sending run stopped after the message had left this site, so what WhatsApp did with it is not known.';
$string['logrecipient'] = 'Recipient';
$string['logstatus'] = 'Status';
$string['logstatusdelivered'] = 'Delivered';
$string['logstatusfailed'] = 'Failed';
$string['logstatuspending'] = 'Waiting';
$string['logstatusread'] = 'Read';
$string['logstatussending'] = 'Sending';
$string['logstatussent'] = 'Sent';
$string['logstatusskipped'] = 'Skipped';
$string['metaappsecret'] = 'App secret';
$string['metaappsecret_desc'] = 'App secret of the Meta app. It is not used to send anything: it is what the delivery reports arriving at the webhook are verified with, and a report without a valid signature is refused.';
$string['metaphoneid'] = 'Phone number ID';
$string['metaphoneid_desc'] = 'Identifier of the WhatsApp business phone number messages are sent from, as WhatsApp Manager shows it. It is an identifier made of digits, not the phone number itself.';
$string['gatewayapikey'] = 'API key';
$string['gatewayapikey_desc'] = 'Key the gateway issued for this site. It is shown once when the subscription is created and cannot be read back: a lost key is a new key.';
$string['gatewaysettings'] = 'Gateway service';
$string['gatewaysettings_desc'] = 'Address and key of the WhatsApp gateway service, used in gateway mode. In direct mode they are not used. In this mode the WhatsApp account, the approved templates and the delivery reports belong to the service, so this site needs no Meta credentials of its own.';
$string['gatewayurl'] = 'Service address';
$string['gatewayurl_desc'] = 'Base address of the gateway, for example https://wa.example.com. The API key travels on every request made to it, so only an address you trust belongs here.';
$string['metasettings'] = 'Meta Cloud API credentials';
$string['metasettings_desc'] = 'Credentials of the Meta app, used in direct mode. In gateway mode they are not used and the gateway holds its own. Once saved, the secrets are not shown again.';
$string['metatoken'] = 'Access token';
$string['metatoken_desc'] = 'Permanent access token of the system user of the Meta app, with the whatsapp_business_messaging permission. Anyone who holds it can send messages billed to your account, so treat it as a password.';
$string['metaverifytoken'] = 'Webhook verify token';
$string['metaverifytoken_desc'] = 'A string you make up. Meta sends it back once, when the webhook subscription is created, and this site answers the challenge only if it matches. Any long random string will do.';
$string['metawabaid'] = 'WhatsApp Business Account ID';
$string['metawabaid_desc'] = 'Identifier of the WhatsApp Business Account the phone number belongs to. It is not used to send messages; it names the account that owns the approved templates.';
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
$string['prefphoneundeliverable'] = 'WhatsApp could not deliver to this number, so nothing else is being sent to it. Check that it is a mobile with WhatsApp installed and that it is typed with the 15 after the area code, as in 011 15 1234-5678. Saving it again puts the channel back on.';
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
$string['recipientsettings'] = 'Recipients';
$string['recipientsettings_desc'] = 'Where the phone number of a user comes from, and how a number typed without an international prefix is read.';
$string['retention'] = 'Keep finished messages for (days)';
$string['retention_desc'] = 'Days a finished queue entry (sent, delivered, read, failed or skipped) is kept before the cleanup task deletes it, together with the record of its clicks. Entries still waiting to be sent are never deleted by it. Set it to 0 to keep everything for good. The delivery report can only show what has not been deleted yet.';
$string['retry'] = 'Retry';
$string['retryconfirm'] = 'Queue entry {$a->id} ({$a->component}) again? It goes out on the next run of the sending task, as a fresh attempt. If the earlier attempt reached WhatsApp after all, the recipient gets the notification twice and the site is billed for it twice.';
$string['retrydeferred'] = 'Entry {$a->id} is queued again. The quiet hours are on right now, so it will not be sent before {$a->time}.';
$string['retrynotfailed'] = 'Entry {$a} was not queued again: only a failed message can be, and this one is not failed any more. Reload the report to see what it says now.';
$string['retrynotfound'] = 'That entry no longer exists. The clean-up task may have removed it.';
$string['retryqueued'] = 'Entry {$a} is queued again. The scheduled task that sends the pending WhatsApp messages runs every minute.';
$string['sitenameshort'] = 'Short site name';
$string['sitenameshort_desc'] = 'Name of this site as it appears in the WhatsApp message. Leave it empty to use the short name of the site.';
$string['statushowmany'] = 'How many';
$string['statusinflight'] = 'Being sent right now';
$string['statusintro'] = 'What the WhatsApp channel did today, counted in the time zone of the site, and what is still waiting to go out. Nothing on this page changes anything.';
$string['statuslastrun'] = 'The sending task last ran';
$string['statusneverrun'] = 'Never';
$string['statusnothingwaiting'] = 'Nothing is waiting';
$string['statusoldest'] = 'The oldest entry has been waiting';
$string['statuspage'] = 'WhatsApp channel status';
$string['statusqueue'] = 'Waiting to go out';
$string['statusstalled'] = 'There are entries waiting and the sending task has not run in the last ten minutes. It is scheduled to run every minute, so either cron is not running on this site or it is not reaching this task. Nothing is being sent until that is fixed.';
$string['statustoday'] = 'Today';
$string['statustoreport'] = 'Open the delivery report';
$string['statuswaiting'] = 'Waiting to be sent';
$string['statuswhat'] = 'What';
$string['task:cleanup'] = 'Delete old WhatsApp queue entries';
$string['task:sendqueue'] = 'Send the pending WhatsApp messages';
$string['task:syncstatus'] = 'Fetch WhatsApp delivery statuses from the gateway';
$string['templatename'] = 'Template';
$string['templatename_desc'] = 'This site sends the template <code>{$a->template}</code>, in the languages <code>{$a->spanish}</code> and <code>{$a->english}</code>. Create it in WhatsApp Manager with exactly that name and in both languages before turning the channel on: a template that does not exist is rejected once per notification.';
$string['templatesettings'] = 'Message template';
$string['templatesettings_desc'] = 'What is sent. Version 1 of this plugin sends every notification as the same approved template, so there is nothing to choose here yet.';
$string['testcode_fake_failure'] = 'The in memory transport is set to refuse everything, with message_whatsapp_fake_transport set to "fail" in config.php. Nothing was asked of any provider.';
$string['testcode_http_error'] = 'The provider answered with an HTTP error and no diagnostic of its own.';
$string['testcode_internal_error'] = 'The plugin failed before the request could be made. That is a fault of the plugin and not of the configuration; the detail below is what it raised.';
$string['testcode_invalid_response'] = 'Something answered, but not the way the API of the provider answers. A proxy, a captive portal or a firewall that intercepts HTTPS looks exactly like this.';
$string['testcode_network_error'] = 'The request never reached the provider: the connection was refused, timed out, or the name could not be resolved. Check that this server may make outgoing HTTPS requests, and that Moodle knows about the proxy if there is one.';
$string['testcode_not_available'] = 'The selected sending mode is one this plugin knows, but the code that implements it is not installed on this site. Install the plugin again, complete.';
$string['testcode_not_configured'] = 'There is nothing to test yet: either no sending mode is selected, or the credentials of the selected mode are still empty.';
$string['testcode_unknown'] = 'The transport reported a failure without saying which one, which it is not supposed to do.';
$string['testcode_unknown_mode'] = 'The sending mode stored in the configuration is not one this plugin knows. Select one in the settings and save.';
$string['testcodeother'] = 'The provider refused the request with the code {$a}. That code, and not the text around it, is what to look up in the error documentation of the provider.';
$string['testconnection'] = 'Test connection';
$string['testconnection_desc'] = 'Asks the provider whether these credentials could send right now. Nothing is sent and nothing is charged. In direct mode this makes a real request to Meta, so it may take a few seconds.';
$string['testconnectionfailed'] = 'Connection failed';
$string['testconnectionok'] = 'Connection OK';
$string['testconnectionokdetail'] = 'The {$a} transport answered and the credentials it uses are valid. This says nothing about the template, which is only checked when a message is actually sent.';
$string['testcurrentconfig'] = 'Current configuration';
$string['testerrorcode'] = 'Error code: {$a}';
$string['testfakewarning'] = 'This site sets message_whatsapp_fake_transport in config.php, so the transport is an in memory one: nothing reaches WhatsApp and nobody is ever delivered anything. A connection reported as working here says nothing about the real credentials.';
$string['testmessagebody'] = 'Test notification from {$a}. If this reached you, the WhatsApp channel works. There is nothing you need to do about it.';
$string['testmessagesubject'] = 'WhatsApp test';
$string['testmodenone'] = 'Not configured';
$string['testpage'] = 'Test WhatsApp';
$string['testpage_desc'] = 'Check the credentials and send yourself a test notification on the <a href="{$a}">WhatsApp test page</a>.';
$string['testproviderdetail'] = 'The provider answered: {$a}';
$string['testsend'] = 'Send a test to my number';
$string['testsend_desc'] = 'Queues a test notification addressed to you. It is not sent from here: the scheduled task that drains the queue sends it, within a minute of its next run, exactly as it sends a real notification. It is a real WhatsApp message and it is billed as one.';
$string['testsendcapped'] = 'The test was queued as entry {$a} and the daily limit per user skipped it: as many messages as the limit allows have already been queued for you today. Nothing will be sent.';
$string['testsenddeferred'] = 'The test was queued as entry {$a->id}. The quiet hours are on right now, so it will not be sent before {$a->time}.';
$string['testsendnooptin'] = 'You have not consented to receiving notifications on WhatsApp, and nothing is ever sent without that consent. Tick the box in your <a href="{$a}">notification preferences</a> and try again.';
$string['testsendnophone'] = 'There is no WhatsApp number recorded for you. Type yours in your <a href="{$a}">notification preferences</a> and try again.';
$string['testsendnorecipient'] = 'You have never set WhatsApp notifications up for yourself. Open your <a href="{$a}">notification preferences</a>, check the number and tick the consent box, then try again.';
$string['testsendnotactive'] = 'The number recorded for you is marked as unusable: either WhatsApp rejected it or messages from this site were blocked. Correct it in your <a href="{$a}">notification preferences</a>.';
$string['testsendnotqueued'] = 'The test notification could not be queued.';
$string['testsendqueued'] = 'The test was queued as entry {$a}. The scheduled task that sends the pending WhatsApp messages will send it the next time cron runs, normally within a minute.';
$string['testsendskipped'] = 'The test was queued as entry {$a} and the queue skipped it straight away. Nothing will be sent.';
$string['testtransport'] = 'Transport in use';
$string['webhookurl'] = 'Webhook URL';
$string['webhookurl_desc'] = 'Address to paste into the webhook configuration of the Meta app, subscribed to the "messages" field. This is where the delivery reports come back to: <code>{$a}</code>';
$string['whatsapp:managesettings'] = 'Configure the WhatsApp notification channel';
$string['whatsapp:optinusers'] = 'Opt other users in to WhatsApp notifications';
$string['whatsapp:viewlog'] = 'View the WhatsApp delivery report';
