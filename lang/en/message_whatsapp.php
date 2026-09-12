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

$string['generalsettings'] = 'WhatsApp notifications';
$string['generalsettings_desc'] = 'Sends Moodle notifications to WhatsApp as approved message templates, through a queue.';
$string['mode'] = 'Sending mode';
$string['mode_desc'] = 'How this site reaches WhatsApp. Nothing is sent until a mode is chosen and configured.';
$string['modedirect'] = 'Direct (own Meta Cloud API credentials)';
$string['modegateway'] = 'Gateway (WhatsApp gateway service)';
$string['pluginname'] = 'WhatsApp';
$string['privacy:metadata'] = 'The WhatsApp message processor does not store any personal data.';
$string['task:cleanup'] = 'Delete old WhatsApp queue entries';
$string['task:sendqueue'] = 'Send the pending WhatsApp messages';
$string['task:syncstatus'] = 'Fetch WhatsApp delivery statuses from the gateway';
$string['whatsapp:managesettings'] = 'Configure the WhatsApp notification channel';
$string['whatsapp:optinusers'] = 'Opt other users in to WhatsApp notifications';
$string['whatsapp:viewlog'] = 'View the WhatsApp delivery report';
