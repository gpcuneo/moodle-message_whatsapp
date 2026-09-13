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
 * Report builder entity for the outgoing WhatsApp queue.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use message_whatsapp\local\queue as store;
use stdClass;

/**
 * The rows of `message_whatsapp_queue` as report builder columns and filters.
 *
 * The entity exists so that the delivery report is a real system report and not a table printed by hand: the
 * sorting, the paging, the filter form and the row actions all come from core, and the plugin only says which
 * columns there are and what they mean.
 *
 * Two things this entity deliberately does **not** offer:
 *
 * - **The phone number.** It is in the row, and it is the one column of the queue that is personal data in its own
 *   right rather than by association. The question this report answers is "did this notification go out, and if
 *   not why", and the number does not help answer it: nobody reading this screen can correct somebody else's
 *   number from here, because the number is edited by its owner in their notification preferences. Printing it
 *   would turn a diagnosis screen into a filterable, sortable directory of the mobile numbers of the site, which
 *   is a much larger thing to hand to every role that happens to hold one capability.
 * - **The message text.** The subject and the summary live in `params`, and the same argument applies with the
 *   added problem that the text of a notification is often about a third person.
 *
 * Everything a callback returns is written into the table as HTML by
 * {@see \core_reportbuilder\table\system_report_table::format_row()}, which does not escape it. The `error` column
 * carries text that came from Meta, so every callback here escapes what it returns.
 */
class queue extends base {
    /**
     * Database tables this entity reads.
     *
     * @return string[] Table names.
     */
    protected function get_default_tables(): array {
        return [store::TABLE];
    }

    /**
     * Title of the entity, shown as the group the columns and filters belong to.
     *
     * @return lang_string The title.
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('logentity', 'message_whatsapp');
    }

    /**
     * Registers the columns and the filters of the entity.
     *
     * Implemented here rather than inherited because the two supported versions disagree about how an entity
     * declares itself: 4.5 has `initialise()` abstract and reads `get_all_columns()`, while 5.2 provides
     * `initialise()` and reads `get_available_columns()`. Doing the loop in the entity is what both accept.
     *
     * @return base This entity.
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }

        // Everything that can be filtered can also be used as a condition of the report.
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }

        return $this;
    }

    /**
     * The columns this entity offers.
     *
     * @return column[] The columns.
     */
    protected function get_all_columns(): array {
        $alias = $this->get_table_alias(store::TABLE);
        $columns = [];

        // Component. Shown as the frankenstyle name and not as the translated plugin name, because that is what
        // the message provider is called everywhere else an administrator meets it, and because half of the
        // components that send notifications have no `pluginname` string to translate to.
        $columns[] = (new column(
            'component',
            new lang_string('logcomponent', 'message_whatsapp'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$alias}.component, {$alias}.name")
            ->set_is_sortable(true)
            ->add_callback(static function ($component, stdClass $row): string {
                if ((string) $component === '') {
                    return '';
                }

                return s((string) $component) . ' / ' . s((string) $row->name);
            });

        // Status.
        $columns[] = (new column(
            'status',
            new lang_string('logstatus', 'message_whatsapp'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$alias}.status")
            ->set_is_sortable(true)
            ->add_callback(static function ($status): string {
                return self::status_name((string) $status);
            });

        // Attempts.
        $columns[] = (new column(
            'attempts',
            new lang_string('logattempts', 'message_whatsapp'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_fields("{$alias}.attempts")
            ->set_is_sortable(true);

        // Last error. Not sortable: it is a TEXT column, and sorting on one is not portable across the databases
        // Moodle supports. It is also the only column here whose content did not come from this site.
        $columns[] = (new column(
            'error',
            new lang_string('logerror', 'message_whatsapp'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_fields("{$alias}.error")
            ->set_is_sortable(false)
            ->add_callback(static function ($error): string {
                return self::error_text((string) $error);
            });

        // Time the row was queued.
        $columns[] = (new column(
            'timecreated',
            new lang_string('logqueued', 'message_whatsapp'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_fields("{$alias}.timecreated")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        return $columns;
    }

    /**
     * The filters this entity offers.
     *
     * @return filter[] The filters.
     */
    protected function get_all_filters(): array {
        $alias = $this->get_table_alias(store::TABLE);
        $filters = [];

        // Status. A select and not free text: the seven values are a closed set fixed by the queue, and typing
        // them is a way of filtering on a value that can never match.
        $filters[] = (new filter(
            select::class,
            'status',
            new lang_string('logstatus', 'message_whatsapp'),
            $this->get_entity_name(),
            "{$alias}.status"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                return self::status_menu();
            });

        // Component.
        $filters[] = (new filter(
            text::class,
            'component',
            new lang_string('logcomponent', 'message_whatsapp'),
            $this->get_entity_name(),
            "{$alias}.component"
        ))
            ->add_joins($this->get_joins());

        // Time queued.
        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('logqueued', 'message_whatsapp'),
            $this->get_entity_name(),
            "{$alias}.timecreated"
        ))
            ->add_joins($this->get_joins())
            ->set_limited_operators([
                date::DATE_ANY,
                date::DATE_RANGE,
                date::DATE_LAST,
                date::DATE_CURRENT,
            ]);

        return $filters;
    }

    /**
     * The statuses of the queue and the names they are shown under, in the order a message passes through them.
     *
     * @return string[] Status value as stored, mapped to its translated name.
     */
    public static function status_menu(): array {
        $statuses = [
            store::STATUS_PENDING,
            store::STATUS_SENDING,
            store::STATUS_SENT,
            store::STATUS_DELIVERED,
            store::STATUS_READ,
            store::STATUS_FAILED,
            store::STATUS_SKIPPED,
        ];

        $menu = [];
        foreach ($statuses as $status) {
            $menu[$status] = self::status_name($status);
        }

        return $menu;
    }

    /**
     * The name a status is shown under, or the stored value when it is not one this plugin writes.
     *
     * @param string $status Value of the status column.
     * @return string Escaped, ready to be written into the table.
     */
    public static function status_name(string $status): string {
        if ($status === '') {
            return '';
        }

        $key = 'logstatus' . $status;

        if (!get_string_manager()->string_exists($key, 'message_whatsapp')) {
            return s($status);
        }

        return get_string($key, 'message_whatsapp');
    }

    /**
     * What to print in the error column.
     *
     * Three of the values that reach this column are constants of the queue and not diagnostics at all: a row
     * skipped for want of consent, a row skipped by the daily limit, and a row whose send was interrupted after
     * the message had left the site. Those are this plugin talking to the administrator and are translated.
     * Anything else came from the provider, in the language the provider answered in, and is printed as it
     * stands. It is escaped either way: nothing in this column was written by this site.
     *
     * @param string $error Value of the error column.
     * @return string Escaped, ready to be written into the table.
     */
    public static function error_text(string $error): string {
        $reasons = [
            store::SKIP_NO_OPTIN => 'logreasonnooptin',
            store::SKIP_DAILY_CAP => 'logreasondailycap',
            store::SKIP_INVALID_PHONE => 'logreasoninvalidphone',
            store::ERROR_ORPHANED => 'logreasonorphaned',
        ];

        if ($error === '') {
            return '';
        }

        if (array_key_exists($error, $reasons)) {
            return get_string($reasons[$error], 'message_whatsapp');
        }

        return s($error);
    }
}
