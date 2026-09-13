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
 * The delivery report of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\reportbuilder\local\systemreports;

use context_system;
use core\lang_string;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\report\action;
use core_reportbuilder\system_report;
use message_whatsapp\local\queue as store;
use message_whatsapp\reportbuilder\local\entities\queue;
use moodle_url;
use pix_icon;
use stdClass;

/**
 * What was queued for WhatsApp, what became of it, and the one thing an administrator can do about it.
 *
 * The report lists notifications addressed to other people, which is why it is behind
 * `message/whatsapp:viewlog` and why {@see self::can_view()} is not the only place that is checked: the page that
 * renders it checks the same capability, because a system report is also reachable through the web service that
 * pages and sorts it, and a report whose only guard lived in the page would be readable through that.
 *
 * ## The retry action
 *
 * It is offered on `failed` rows and on nothing else.
 *
 * - `sent`, `delivered` and `read` mean the provider took the message. Re-queueing one of those sends a person a
 *   second copy of a notification they already have, and bills the site for it. There is no reading of "retry"
 *   that makes that the intended outcome.
 * - `pending` is already going to be tried again; the action would only throw away the backoff the queue has
 *   accumulated and bring the next attempt forward, which is the opposite of what a backoff is for.
 * - `sending` is a row a task run is holding right now. Handing it back to the queue while the run is still in
 *   flight is the one way to get the same message sent twice from a single row; the queue reclaims genuinely
 *   abandoned rows by itself after {@see \message_whatsapp\local\queue::STALE_SENDING_SECONDS}.
 * - `skipped` is a message the site decided not to send: no consent, or the daily limit. The first of those is
 *   the rule the whole plugin exists to enforce, and the two cases are one column apart, so a single button that
 *   retried skipped rows would be a button that sometimes messages people who never agreed to be messaged.
 *
 * The callback below only hides the menu item. What actually protects the row is that the page performs the
 * change with `AND status = 'failed'` in the statement, so a row that a webhook moved to `delivered` in the
 * seconds between the page rendering and the button being pressed is not re-queued.
 */
class log extends system_report {
    /**
     * Builds the report: the queue as the main table, the user joined onto it, then columns, filters and actions.
     *
     * @return void
     */
    protected function initialise(): void {
        $entity = new queue();
        $alias = $entity->get_table_alias(store::TABLE);

        $this->set_main_table(store::TABLE, $alias);
        $this->add_entity($entity);

        // `id` addresses the row from the action link; `status` is what the action callback decides on. Both have
        // to be selected explicitly, because neither is a column of the report on its own.
        $this->add_base_fields("{$alias}.id, {$alias}.status");

        // The recipient. Joined and not stored again: the queue keeps the number it sent to, on purpose, but the
        // name of the person is whatever their profile says now.
        $userentity = new user();
        $useralias = $userentity->get_table_alias('user');
        $this->add_entity($userentity->add_join(
            "LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$alias}.userid"
        ));

        $this->add_columns();
        $this->add_filters();
        $this->add_actions();

        $this->set_default_no_results_notice(new lang_string('lognoresults', 'message_whatsapp'));

        // Left at the default, which is off. A download of this report is a spreadsheet of who on this site was
        // notified of what and when, leaving the site in a file that nothing here can take back.
        $this->set_downloadable(false);
    }

    /**
     * Whether the current user may see the report at all.
     *
     * @return bool True when they hold the capability to read the delivery report.
     */
    protected function can_view(): bool {
        return has_capability('message/whatsapp:viewlog', context_system::instance());
    }

    /**
     * Adds the columns, newest message first.
     *
     * @return void
     */
    protected function add_columns(): void {
        $this->add_columns_from_entities([
            'user:fullnamewithlink',
            'queue:component',
            'queue:status',
            'queue:attempts',
            'queue:error',
            'queue:timecreated',
        ]);

        // "Full name with link" names the shape of the core column and not what it holds here. On this screen the
        // person in that cell is the one the notification was addressed to, and that is what decides whether a
        // row is the one being looked for.
        $recipient = $this->get_column('user:fullnamewithlink');
        if ($recipient !== null) {
            $recipient->set_title(new lang_string('logrecipient', 'message_whatsapp'));
        }

        $this->set_initial_sort_column('queue:timecreated', SORT_DESC);
    }

    /**
     * Adds the filters.
     *
     * @return void
     */
    protected function add_filters(): void {
        $this->add_filters_from_entities([
            'queue:status',
            'user:fullname',
            'queue:component',
            'queue:timecreated',
        ]);
    }

    /**
     * Adds the retry action to the rows it makes sense on.
     *
     * The link carries a session key and leads to a confirmation, not to the change. The change itself happens on
     * the POST of that confirmation, so nothing here is a request that a third party site could make on behalf of
     * a logged in administrator, and nothing here is a request a link prefetcher can make by accident.
     *
     * @return void
     */
    protected function add_actions(): void {
        $this->add_action((new action(
            new moodle_url('/message/output/whatsapp/report.php', [
                'action' => 'retry',
                'id' => ':id',
                'sesskey' => sesskey(),
            ]),
            new pix_icon('t/reload', ''),
            [],
            false,
            new lang_string('retry', 'message_whatsapp')
        ))->add_callback(static function (stdClass $row): bool {
            return (string) $row->status === store::STATUS_FAILED;
        }));
    }
}
