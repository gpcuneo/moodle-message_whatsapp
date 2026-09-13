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

    $settings->add(new admin_setting_heading(
        'message_whatsapp/deliverysettings',
        new lang_string('deliverysettings', 'message_whatsapp'),
        new lang_string('deliverysettings_desc', 'message_whatsapp')
    ));

    // A WhatsApp notification rings a phone that is often on a bedside table, so the quiet hours are on from the
    // start. Nothing is discarded by them: a message caught by the window leaves as soon as the window closes.
    $settings->add(new admin_setting_configcheckbox(
        'message_whatsapp/quiethours',
        new lang_string('quiethours', 'message_whatsapp'),
        new lang_string('quiethours_desc', 'message_whatsapp'),
        1
    ));

    $hours = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $hours[$hour] = sprintf('%02d:00', $hour);
    }

    $settings->add(new admin_setting_configselect(
        'message_whatsapp/quietstart',
        new lang_string('quietstart', 'message_whatsapp'),
        new lang_string('quietstart_desc', 'message_whatsapp'),
        \message_whatsapp\local\queue::DEFAULT_QUIET_START,
        $hours
    ));

    $settings->add(new admin_setting_configselect(
        'message_whatsapp/quietend',
        new lang_string('quietend', 'message_whatsapp'),
        new lang_string('quietend_desc', 'message_whatsapp'),
        \message_whatsapp\local\queue::DEFAULT_QUIET_END,
        $hours
    ));

    $settings->add(new admin_setting_configtext(
        'message_whatsapp/dailycap',
        new lang_string('dailycap', 'message_whatsapp'),
        new lang_string('dailycap_desc', 'message_whatsapp'),
        0,
        PARAM_INT,
        5
    ));
}
