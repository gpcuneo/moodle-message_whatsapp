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
 * Upgrade steps of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Upgrade the database of the plugin.
 *
 * @param int $oldversion The version of the plugin that is installed right now.
 * @return bool
 */
function xmldb_message_whatsapp_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091201) {
        // Define table message_whatsapp_user to be created.
        $table = new xmldb_table('message_whatsapp_user');

        // Adding fields to table message_whatsapp_user.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('phone', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'manual');
        $table->add_field('optin', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('optintime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('verified', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('verifiedtime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table message_whatsapp_user.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN_UNIQUE, ['userid'], 'user', ['id']);

        // Adding indexes to table message_whatsapp_user.
        $table->add_index('phone', XMLDB_INDEX_NOTUNIQUE, ['phone']);

        // Conditionally launch create table for message_whatsapp_user.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table message_whatsapp_queue to be created.
        $table = new xmldb_table('message_whatsapp_queue');

        // Adding fields to table message_whatsapp_queue.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('savedmessageid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('phone', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('templatekey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('params', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('url', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('nextattempt', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('providermsgid', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('error', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('pricingcategory', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timesent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timestatus', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table message_whatsapp_queue.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        // Adding indexes to table message_whatsapp_queue.
        $table->add_index('status-nextattempt', XMLDB_INDEX_NOTUNIQUE, ['status', 'nextattempt']);
        $table->add_index('providermsgid', XMLDB_INDEX_NOTUNIQUE, ['providermsgid']);
        $table->add_index('userid-timecreated', XMLDB_INDEX_NOTUNIQUE, ['userid', 'timecreated']);
        $table->add_index('status-timecreated', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);

        // Conditionally launch create table for message_whatsapp_queue.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table message_whatsapp_click to be created.
        $table = new xmldb_table('message_whatsapp_click');

        // Adding fields to table message_whatsapp_click.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('queueid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timeclicked', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('useragent', XMLDB_TYPE_CHAR, '255', null, null, null, null);

        // Adding keys to table message_whatsapp_click.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('queueid', XMLDB_KEY_FOREIGN, ['queueid'], 'message_whatsapp_queue', ['id']);

        // Conditionally launch create table for message_whatsapp_click.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Whatsapp savepoint reached.
        upgrade_plugin_savepoint(true, 2026091201, 'message', 'whatsapp');
    }

    if ($oldversion < 2026091302) {
        // Define field lang to be added to message_whatsapp_queue.
        $table = new xmldb_table('message_whatsapp_queue');
        $field = new xmldb_field('lang', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null, 'templatekey');

        // Conditionally launch add field lang.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Whatsapp savepoint reached.
        upgrade_plugin_savepoint(true, 2026091302, 'message', 'whatsapp');
    }

    return true;
}
