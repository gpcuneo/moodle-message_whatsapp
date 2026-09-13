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
 * Administration settings of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_heading(
        'message_whatsapp/generalsettings',
        new lang_string('generalsettings', 'message_whatsapp'),
        new lang_string('generalsettings_desc', 'message_whatsapp')
    ));

    $modes = [
        '' => new lang_string('choosedots'),
        message_output_whatsapp::MODE_DIRECT => new lang_string('modedirect', 'message_whatsapp'),
        message_output_whatsapp::MODE_GATEWAY => new lang_string('modegateway', 'message_whatsapp'),
    ];

    $settings->add(new admin_setting_configselect(
        'message_whatsapp/mode',
        new lang_string('mode', 'message_whatsapp'),
        new lang_string('mode_desc', 'message_whatsapp'),
        '',
        $modes
    ));

    $settings->add(new admin_setting_configtext(
        'message_whatsapp/sitename_short',
        new lang_string('sitenameshort', 'message_whatsapp'),
        new lang_string('sitenameshort_desc', 'message_whatsapp'),
        '',
        PARAM_TEXT,
        30
    ));

    $phonesources = [
        '' => new lang_string('phonesourcenone', 'message_whatsapp'),
        'phone1' => new lang_string('phonesourcephone1', 'message_whatsapp'),
        'phone2' => new lang_string('phonesourcephone2', 'message_whatsapp'),
    ];

    $settings->add(new admin_setting_configselect(
        'message_whatsapp/phonesource',
        new lang_string('phonesource', 'message_whatsapp'),
        new lang_string('phonesource_desc', 'message_whatsapp'),
        \message_whatsapp\local\recipient::DEFAULT_PHONE_SOURCE,
        $phonesources
    ));

    $settings->add(new admin_setting_configselect(
        'message_whatsapp/defaultcountry',
        new lang_string('defaultcountry', 'message_whatsapp'),
        new lang_string('defaultcountry_desc', 'message_whatsapp'),
        \message_whatsapp\local\recipient::FALLBACK_COUNTRY,
        get_string_manager()->get_list_of_countries()
    ));
}
