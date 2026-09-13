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
 * Scheduled task that pulls delivery statuses from the gateway.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\task;

use core\task\scheduled_task;

/**
 * Pulls the delivery statuses of the sent messages from the gateway.
 *
 * Only does work in gateway mode. In direct mode Meta pushes the statuses to webhook.php instead, so the task
 * finishes without doing anything.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_status extends scheduled_task {
    /**
     * Returns the name of the task shown in the scheduled tasks administration page.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:syncstatus', 'message_whatsapp');
    }

    /**
     * Runs the task.
     *
     * @return void
     */
    public function execute(): void {
        // Left empty on purpose. T4.4 of docs/plan-desarrollo.md fills it in: in gateway mode, read
        // the cursor from the plugin configuration, ask the gateway for the status changes since that
        // cursor, update the queue by providermsgid and save the cursor.
    }
}
