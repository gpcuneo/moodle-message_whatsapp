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
 * The order of the page follows the order in which an administrator has to make the decisions: how the site
 * reaches WhatsApp, the credentials of that way, where the phone numbers come from, what is sent, when the queue
 * may send it, and how long the record of it is kept.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');

// The page of the two test buttons is registered whatever depth the administration tree is being built to, and
// not only inside the `fulltree` branch below. `admin_externalpage_setup()` refuses to set a page up that is not
// in the tree, so a registration that only happened while the full tree was loaded would make the page reachable
// from the settings screen and unreachable from anywhere else, including a bookmark.
$ADMIN->add('messaging', new admin_externalpage(
    'message_whatsapp_test',
    new lang_string('testpage', 'message_whatsapp'),
    new moodle_url('/message/output/whatsapp/test.php'),
    'message/whatsapp:managesettings'
));

// The delivery report is registered for the same reason and at the same depth, but behind a different capability:
// reading what was sent and re-queueing what failed is operations work, and it should not need the capability
// that hands out the credentials of the site. Note that today this registration only ever runs for somebody who
// already holds `moodle/site:config`, because `admin/settings/messaging.php` and
// `\core\plugininfo\message::load_settings()` both refuse to include the settings of a message processor for
// anybody else, so `message/whatsapp:viewlog` on its own does not yet get a person to this page. That is written
// up in `docs/decisiones-pendientes.md` and is not something this file can fix.
$ADMIN->add('messaging', new admin_externalpage(
    'message_whatsapp_log',
    new lang_string('logpage', 'message_whatsapp'),
    new moodle_url('/message/output/whatsapp/report.php'),
    'message/whatsapp:viewlog'
));

$ADMIN->add('messaging', new admin_externalpage(
    'message_whatsapp_status',
    new lang_string('statuspage', 'message_whatsapp'),
    new moodle_url('/message/output/whatsapp/status.php'),
    'message/whatsapp:viewlog'
));

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

    $settings->add(new admin_setting_description(
        'message_whatsapp/testpagelink',
        new lang_string('testpage', 'message_whatsapp'),
        new lang_string(
            'testpage_desc',
            'message_whatsapp',
            (new moodle_url('/message/output/whatsapp/test.php'))->out()
        )
    ));

    $settings->add(new admin_setting_description(
        'message_whatsapp/logpagelink',
        new lang_string('logpage', 'message_whatsapp'),
        new lang_string(
            'logpage_desc',
            'message_whatsapp',
            (new moodle_url('/message/output/whatsapp/report.php'))->out()
        )
    ));

    $settings->add(new admin_setting_configtext(
        'message_whatsapp/sitename_short',
        new lang_string('sitenameshort', 'message_whatsapp'),
        new lang_string('sitenameshort_desc', 'message_whatsapp'),
        '',
        PARAM_TEXT,
        30
    ));

    // Credentials of direct mode. They are shown whichever mode is selected, because an administrator fills them
    // in before the mode works and because hiding them behind the selected mode would hide, from the one screen
    // that is supposed to explain the channel, the reason why the other mode is not usable yet.
    $settings->add(new admin_setting_heading(
        'message_whatsapp/metasettings',
        new lang_string('metasettings', 'message_whatsapp'),
        new lang_string('metasettings_desc', 'message_whatsapp')
    ));

    // The three secrets of this section use the unmasking password field. The value is stored in the clear either
    // way -- the site has to be able to send the token to Meta -- so what the widget buys is that a token is not
    // left on screen behind an administrator, not on a projector and not in a screenshot of a support ticket.
    $settings->add(new admin_setting_configpasswordunmask(
        'message_whatsapp/metatoken',
        new lang_string('metatoken', 'message_whatsapp'),
        new lang_string('metatoken_desc', 'message_whatsapp'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'message_whatsapp/metaphoneid',
        new lang_string('metaphoneid', 'message_whatsapp'),
        new lang_string('metaphoneid_desc', 'message_whatsapp'),
        '',
        PARAM_ALPHANUM,
        24
    ));

    $settings->add(new admin_setting_configtext(
        'message_whatsapp/metawabaid',
        new lang_string('metawabaid', 'message_whatsapp'),
        new lang_string('metawabaid_desc', 'message_whatsapp'),
        '',
        PARAM_ALPHANUM,
        24
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'message_whatsapp/metaappsecret',
        new lang_string('metaappsecret', 'message_whatsapp'),
        new lang_string('metaappsecret_desc', 'message_whatsapp'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'message_whatsapp/metaverifytoken',
        new lang_string('metaverifytoken', 'message_whatsapp'),
        new lang_string('metaverifytoken_desc', 'message_whatsapp'),
        ''
    ));

    // The URL Meta has to be given for the delivery reports to come back. It is built from `wwwroot`, which maps
    // to the document root in both 4.5 and 5.2, so the string is the same in both and can be copied as it stands.
    $settings->add(new admin_setting_description(
        'message_whatsapp/webhookurl',
        new lang_string('webhookurl', 'message_whatsapp'),
        new lang_string(
            'webhookurl_desc',
            'message_whatsapp',
            $CFG->wwwroot . '/message/output/whatsapp/webhook.php'
        )
    ));

    // The default is the constant of the transport that pastes it into the URL, and not a second copy of the
    // number: two spellings of the same default is a bug waiting for the day one of them is bumped.
    $settings->add(new admin_setting_configtext(
        'message_whatsapp/graphversion',
        new lang_string('graphversion', 'message_whatsapp'),
        new lang_string('graphversion_desc', 'message_whatsapp'),
        \message_whatsapp\transport\meta_cloud::DEFAULT_GRAPH_VERSION,
        '/^(v\d+\.\d+)?$/',
        8
    ));

    // Credentials of gateway mode. Shown whichever mode is selected, for the same reason the Meta ones are:
    // an administrator fills them in before the mode works, and the one screen that explains the channel should
    // not hide why the other mode is not usable yet.
    $settings->add(new admin_setting_heading(
        'message_whatsapp/gatewaysettings',
        new lang_string('gatewaysettings', 'message_whatsapp'),
        new lang_string('gatewaysettings_desc', 'message_whatsapp')
    ));

    // PARAM_URL and not PARAM_TEXT: the API key travels on every request made to this address, so a value that
    // is not a URL must not be stored. The transport checks the scheme and the host again before calling it,
    // because a setting can also be written by a CLI or by a restore.
    $settings->add(new admin_setting_configtext(
        'message_whatsapp/gatewayurl',
        new lang_string('gatewayurl', 'message_whatsapp'),
        new lang_string('gatewayurl_desc', 'message_whatsapp'),
        '',
        PARAM_URL,
        40
    ));

    // The unmasking password field, like the secrets of direct mode: the value is stored in the clear either way
    // -- the site has to send it to the gateway -- so what the widget buys is that a key is not left on screen
    // behind an administrator, on a projector or in a screenshot of a support ticket.
    $settings->add(new admin_setting_configpasswordunmask(
        'message_whatsapp/gatewayapikey',
        new lang_string('gatewayapikey', 'message_whatsapp'),
        new lang_string('gatewayapikey_desc', 'message_whatsapp'),
        ''
    ));

    $settings->add(new admin_setting_heading(
        'message_whatsapp/recipientsettings',
        new lang_string('recipientsettings', 'message_whatsapp'),
        new lang_string('recipientsettings_desc', 'message_whatsapp')
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

    // The template and its languages are shown and not offered as a choice. Version 1 of the plugin sends one
    // approved template, whose name and whose two language versions are fixed in the code that builds the message
    // and are what has to be created in Meta letter by letter. A text field here would look like a way of
    // pointing the site at another template, and it would be a way of pointing it at a template that does not
    // exist, which fails once per notification with an error from Meta instead of once here.
    $settings->add(new admin_setting_heading(
        'message_whatsapp/templatesettings',
        new lang_string('templatesettings', 'message_whatsapp'),
        new lang_string('templatesettings_desc', 'message_whatsapp')
    ));

    $settings->add(new admin_setting_description(
        'message_whatsapp/templatename',
        new lang_string('templatename', 'message_whatsapp'),
        new lang_string('templatename_desc', 'message_whatsapp', (object) [
            'template' => \message_whatsapp\local\template_mapper::TEMPLATE,
            'spanish' => \message_whatsapp\local\template_mapper::LANG_ES,
            'english' => \message_whatsapp\local\template_mapper::LANG_EN,
        ])
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

    $settings->add(new admin_setting_configtext(
        'message_whatsapp/batchsize',
        new lang_string('batchsize', 'message_whatsapp'),
        new lang_string('batchsize_desc', 'message_whatsapp'),
        \message_whatsapp\task\send_queue::DEFAULT_BATCH_SIZE,
        PARAM_INT,
        5
    ));

    $settings->add(new admin_setting_heading(
        'message_whatsapp/datasettings',
        new lang_string('datasettings', 'message_whatsapp'),
        new lang_string('datasettings_desc', 'message_whatsapp')
    ));

    $settings->add(new admin_setting_configtext(
        'message_whatsapp/retention',
        new lang_string('retention', 'message_whatsapp'),
        new lang_string('retention_desc', 'message_whatsapp'),
        90,
        PARAM_INT,
        5
    ));
}
