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
 * Installation code for the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Register the processor in the message_processors table.
 *
 * Core calls message_update_processors() right after installing a message
 * plugin and throws invalid_parameter_exception if the row is missing, which
 * aborts the whole site installation. Every core processor registers itself
 * here; see message/output/email/db/install.php.
 *
 * @return bool
 */
function xmldb_message_whatsapp_install() {
    global $DB;

    $provider = new stdClass();
    $provider->name = 'whatsapp';
    $DB->insert_record('message_processors', $provider);

    return true;
}
