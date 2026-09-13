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
use message_output_whatsapp;
use message_whatsapp\local\queue;
use message_whatsapp\transport\changes;
use message_whatsapp\transport\gateway;
use message_whatsapp\transport\result;

/**
 * Pulls the delivery statuses of the sent messages from the gateway.
 *
 * This is the other half of `webhook.php`. In direct mode Meta pushes a delivery report at the site and the
 * webhook applies it; in gateway mode nothing can be pushed, because the service has no way of reaching a Moodle
 * that sits behind a firewall or on a laptop. So the site pulls, every five minutes, and **the cursor is what
 * makes pulling cheap**: the gateway answers only what changed after the position of the last run, so a quiet
 * five minutes costs one request that comes back empty.
 *
 * The cursor lives in `config_plugins`, which is the right place for it and not merely a convenient one: it is a
 * single value that belongs to the site, it has to survive a cron restart, and it must not be in the queue table,
 * where it would be a row that is not a message.
 *
 * **It only does work in gateway mode.** In direct mode the task still runs -- one scheduled task, declared once,
 * whatever the site is configured for -- and returns immediately.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_status extends scheduled_task {
    /** Name of the setting the cursor is kept under. */
    public const CURSOR_SETTING = 'statuscursor';

    /**
     * Changes asked for per request.
     *
     * The gateway caps the page at 1000 and §4.3 uses 500 in its own example. It is a whole page of work for one
     * run of a task that has five minutes before the next one.
     */
    public const PAGE = 500;

    /**
     * Pages one run is willing to walk.
     *
     * A site that was down for a day comes back to a backlog, and the cursor means it catches up over several
     * runs instead of in one that never ends. Ten pages is five thousand changes, far more than five minutes of
     * a real site produces, and it is the ceiling that keeps this task from being the reason cron is late.
     */
    public const MAX_PAGES = 10;

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
        if (!$this->in_gateway_mode()) {
            mtrace('message_whatsapp: not in gateway mode, the delivery statuses arrive by webhook.');

            return;
        }

        $transport = $this->transport();
        $cursor = (string) get_config('message_whatsapp', self::CURSOR_SETTING);
        $applied = 0;
        $seen = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $answer = $transport->changes_since($cursor, self::PAGE);

            if ($answer instanceof result) {
                // The cursor is deliberately **not** advanced here. Whatever went wrong -- the service is down,
                // the key was revoked, the network is out -- the next run has to ask for the same position
                // again, and the statuses of the messages in between are not lost.
                mtrace('message_whatsapp: the gateway did not answer the status cursor: ' . $answer->message);

                return;
            }

            $seen += count($answer->items);
            $applied += $this->apply($answer);

            $cursor = $answer->cursor;
            $this->save_cursor($cursor);

            if (!$answer->hasmore) {
                break;
            }
        }

        mtrace('message_whatsapp: ' . $seen . ' status change(s) read from the gateway, ' . $applied . ' applied.');
    }

    /**
     * Applies one page of changes to the queue.
     *
     * A change that matches no row of this site is ordinary and not an error: the cursor of a tenant reports
     * every message of that tenant, and a site that was reinstalled, or whose queue was cleaned up, no longer
     * has the rows the older ones belong to.
     *
     * @param changes $page The page the gateway answered.
     * @return int How many queue rows moved forward.
     */
    protected function apply(changes $page): int {
        $applied = 0;

        foreach ($page->items as $change) {
            $moved = queue::update_by_providermsgid(
                $change->providermsgid,
                $change->status,
                $change->timestatus,
                $change->pricingcategory,
                $change->error
            );

            $applied += $moved ? 1 : 0;
        }

        return $applied;
    }

    /**
     * Stores the cursor the next run has to continue from.
     *
     * Written after each page and not once at the end, so a run that dies halfway -- cron killed, PHP out of
     * memory -- does not repeat the pages it already applied. Applying one twice would be harmless, because
     * {@see queue::update_by_providermsgid()} refuses a status that is not ahead of the one the row carries, but
     * repeating them is work nobody needs.
     *
     * @param string $cursor Cursor as the gateway returned it.
     * @return void
     */
    protected function save_cursor(string $cursor): void {
        set_config(self::CURSOR_SETTING, $cursor, 'message_whatsapp');
    }

    /**
     * Whether the site sends through the gateway.
     *
     * @return bool True when the `mode` setting is gateway mode.
     */
    protected function in_gateway_mode(): bool {
        global $CFG;

        if (!class_exists(message_output_whatsapp::class, false)) {
            require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');
        }

        return trim((string) get_config('message_whatsapp', 'mode')) === message_output_whatsapp::MODE_GATEWAY;
    }

    /**
     * Returns the transport this task reads through.
     *
     * Built directly and not through {@see \message_whatsapp\transport\factory}, because the cursor is not part
     * of {@see \message_whatsapp\transport\transport_interface}: it is something only the gateway has, and
     * putting it in the interface would give `meta_cloud` a method that can only answer "not me". The mode was
     * already checked above, so this is only ever reached when the gateway is the transport of the site.
     *
     * It is a method so that a test can hand back a transport built on a mock handler.
     *
     * @return gateway The transport that talks to the service.
     */
    protected function transport(): gateway {
        return new gateway();
    }
}
