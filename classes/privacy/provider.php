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
 * Definition of the privacy provider of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Gustavo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\privacy;

/**
 * Privacy provider of the WhatsApp message processor.
 *
 * This release stores no personal data at all: there are no tables, no user preferences and no delivery, so the
 * plugin declares itself as a null provider. The full provider, with the phone numbers, the opt-in, the queue and
 * the external locations of Meta and of the gateway, arrives with the tables that hold that data.
 *
 * @package    message_whatsapp
 * @copyright  2026 Gustavo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * Returns the language string that explains why this plugin stores no personal data.
     *
     * @return string The identifier of the explanation string in the component language file.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
