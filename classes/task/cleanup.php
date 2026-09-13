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
 * Scheduled task that deletes the old rows of the WhatsApp queue.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\task;

use core\task\scheduled_task;

/**
 * Deletes the queue rows that already reached a terminal status and are older than the retention period.
 *
 * The retention period is a plugin setting, ninety days by default.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends scheduled_task {
    /**
     * Returns the name of the task shown in the scheduled tasks administration page.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:cleanup', 'message_whatsapp');
    }

    /**
     * Runs the task.
     *
     * @return void
     */
    public function execute(): void {
        // Left empty on purpose. T3.3 of docs/plan-desarrollo.md fills it in: delete the terminal
        // queue rows, and their clicks, older than the retention setting.
    }
}
