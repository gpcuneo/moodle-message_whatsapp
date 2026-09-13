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
 * Privacy provider of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider of the WhatsApp message processor.
 *
 * The plugin keeps three tables, and every row in all three belongs to one person: the phone number and the
 * consent in message_whatsapp_user, one row per notification in message_whatsapp_queue with the destination
 * number and the sanitised text that was sent, and one row per click on the button of a delivered message in
 * message_whatsapp_click. All of it is exported and deleted in the user context of its owner.
 *
 * The queue also names the course the notification came from, but that never turns a row into course data:
 * see the note on get_contexts_for_userid().
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string Table with the phone number and the opt-in of each user. */
    private const TABLE_USER = 'message_whatsapp_user';

    /** @var string Table with one row per outgoing notification. */
    private const TABLE_QUEUE = 'message_whatsapp_queue';

    /** @var string Table with one row per click on the button of a sent message. */
    private const TABLE_CLICK = 'message_whatsapp_click';

    /**
     * Describes every piece of personal data this plugin keeps or sends out of the site.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection The same collection, with the three tables and the two external locations added.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE_USER, [
            'userid' => 'privacy:metadata:message_whatsapp_user:userid',
            'phone' => 'privacy:metadata:message_whatsapp_user:phone',
            'source' => 'privacy:metadata:message_whatsapp_user:source',
            'optin' => 'privacy:metadata:message_whatsapp_user:optin',
            'optintime' => 'privacy:metadata:message_whatsapp_user:optintime',
            'verified' => 'privacy:metadata:message_whatsapp_user:verified',
            'verifiedtime' => 'privacy:metadata:message_whatsapp_user:verifiedtime',
            'status' => 'privacy:metadata:message_whatsapp_user:status',
            'timecreated' => 'privacy:metadata:message_whatsapp_user:timecreated',
            'timemodified' => 'privacy:metadata:message_whatsapp_user:timemodified',
        ], 'privacy:metadata:message_whatsapp_user');

        $collection->add_database_table(self::TABLE_QUEUE, [
            'userid' => 'privacy:metadata:message_whatsapp_queue:userid',
            'savedmessageid' => 'privacy:metadata:message_whatsapp_queue:savedmessageid',
            'component' => 'privacy:metadata:message_whatsapp_queue:component',
            'name' => 'privacy:metadata:message_whatsapp_queue:name',
            'courseid' => 'privacy:metadata:message_whatsapp_queue:courseid',
            'phone' => 'privacy:metadata:message_whatsapp_queue:phone',
            'templatekey' => 'privacy:metadata:message_whatsapp_queue:templatekey',
            'lang' => 'privacy:metadata:message_whatsapp_queue:lang',
            'params' => 'privacy:metadata:message_whatsapp_queue:params',
            'url' => 'privacy:metadata:message_whatsapp_queue:url',
            'status' => 'privacy:metadata:message_whatsapp_queue:status',
            'attempts' => 'privacy:metadata:message_whatsapp_queue:attempts',
            'nextattempt' => 'privacy:metadata:message_whatsapp_queue:nextattempt',
            'providermsgid' => 'privacy:metadata:message_whatsapp_queue:providermsgid',
            'error' => 'privacy:metadata:message_whatsapp_queue:error',
            'pricingcategory' => 'privacy:metadata:message_whatsapp_queue:pricingcategory',
            'timecreated' => 'privacy:metadata:message_whatsapp_queue:timecreated',
            'timesent' => 'privacy:metadata:message_whatsapp_queue:timesent',
            'timestatus' => 'privacy:metadata:message_whatsapp_queue:timestatus',
        ], 'privacy:metadata:message_whatsapp_queue');

        $collection->add_database_table(self::TABLE_CLICK, [
            'queueid' => 'privacy:metadata:message_whatsapp_click:queueid',
            'timeclicked' => 'privacy:metadata:message_whatsapp_click:timeclicked',
            'useragent' => 'privacy:metadata:message_whatsapp_click:useragent',
        ], 'privacy:metadata:message_whatsapp_click');

        // Direct mode: the site itself calls the Meta Cloud API with its own credentials.
        $collection->add_external_location_link('meta_cloud_api', [
            'phone' => 'privacy:metadata:meta_cloud_api:phone',
            'templatekey' => 'privacy:metadata:meta_cloud_api:templatekey',
            'lang' => 'privacy:metadata:meta_cloud_api:lang',
            'params' => 'privacy:metadata:meta_cloud_api:params',
            'url' => 'privacy:metadata:meta_cloud_api:url',
        ], 'privacy:metadata:meta_cloud_api');

        // Gateway mode: the same payload goes to the gateway service, which relays it to Meta on behalf of the site.
        $collection->add_external_location_link('wa_gateway', [
            'phone' => 'privacy:metadata:wa_gateway:phone',
            'templatekey' => 'privacy:metadata:wa_gateway:templatekey',
            'lang' => 'privacy:metadata:wa_gateway:lang',
            'params' => 'privacy:metadata:wa_gateway:params',
            'url' => 'privacy:metadata:wa_gateway:url',
            'queueid' => 'privacy:metadata:wa_gateway:queueid',
        ], 'privacy:metadata:wa_gateway');

        return $collection;
    }

    /**
     * Returns the contexts that hold data of the given user, which is at most their own user context.
     *
     * Everything this plugin writes is about a person: a phone number, a consent, the notifications delivered to
     * that number and the clicks on them. The queue does record the courseid of the notification, but that column
     * says where the message came from, not whose the row is. Reporting the course context instead would mean
     * that deleting a course, or answering a deletion request scoped to a course, wipes the delivery history of
     * every student who never asked for it, and that a site wide notification (courseid 0) would have no context
     * at all. So the courseid travels in the export as one more field of the message and adds no context.
     *
     * @param int $userid The user to look up.
     * @return contextlist The user context of that user, or an empty list when the plugin holds nothing of theirs.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        // A click cannot exist without its queue row, so these two checks already cover the three tables.
        $hasdata = $DB->record_exists(self::TABLE_USER, ['userid' => $userid])
            || $DB->record_exists(self::TABLE_QUEUE, ['userid' => $userid]);

        if ($hasdata) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * Returns the users that hold data in the given context.
     *
     * @param userlist $userlist The list to add the users to. Its context decides which users can be in it.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }

        $userid = $context->instanceid;
        $hasdata = $DB->record_exists(self::TABLE_USER, ['userid' => $userid])
            || $DB->record_exists(self::TABLE_QUEUE, ['userid' => $userid]);

        if ($hasdata) {
            $userlist->add_user($userid);
        }
    }

    /**
     * Exports everything the plugin holds about the user of the given approved context list.
     *
     * The export is for the owner of the data, so it goes out whole: the phone number, the consent and the text
     * of every notification that was sent to them. None of it is ever written to a log or to debugging output.
     *
     * @param approved_contextlist $contextlist The approved contexts to export data for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;
        $context = self::own_user_context($contextlist->get_contexts(), $userid);
        if ($context === null) {
            return;
        }

        $root = [get_string('privacy:path', 'message_whatsapp')];

        $record = $DB->get_record(self::TABLE_USER, ['userid' => $userid]);
        if ($record) {
            writer::with_context($context)->export_data($root, (object)[
                'phone' => $record->phone,
                'source' => $record->source,
                'optin' => transform::yesno($record->optin),
                'optintime' => self::datetime($record->optintime),
                'verified' => transform::yesno($record->verified),
                'verifiedtime' => self::datetime($record->verifiedtime),
                'status' => $record->status,
                'timecreated' => self::datetime($record->timecreated),
                'timemodified' => self::datetime($record->timemodified),
            ]);
        }

        $messages = self::export_messages_data($userid);
        if (!empty($messages)) {
            $path = array_merge($root, [get_string('privacy:path:messages', 'message_whatsapp')]);
            writer::with_context($context)->export_data($path, (object)['messages' => $messages]);
        }
    }

    /**
     * Deletes everything the plugin holds about every user of the given context.
     *
     * Only a user context can hold data of this plugin, and a user context holds the data of exactly one person,
     * so this never deletes more than that person's rows. Any other context level is left untouched: a course or
     * a system context would otherwise turn into a mass deletion of the delivery history of unrelated people.
     *
     * @param \context $context The context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if (!$context instanceof \context_user) {
            return;
        }

        self::delete_data_for_userids([(int)$context->instanceid]);
    }

    /**
     * Deletes everything the plugin holds about the user of the given approved context list.
     *
     * @param approved_contextlist $contextlist The approved contexts and user to delete data for.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        if (self::own_user_context($contextlist->get_contexts(), $userid) === null) {
            return;
        }

        self::delete_data_for_userids([$userid]);
    }

    /**
     * Deletes everything the plugin holds about the approved users of the given context.
     *
     * @param approved_userlist $userlist The approved context and users to delete data for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }

        // A user context can only ever hold the data of its own user, whatever else was approved for deletion.
        $userids = array_map('intval', $userlist->get_userids());
        if (!in_array((int)$context->instanceid, $userids, true)) {
            return;
        }

        self::delete_data_for_userids([(int)$context->instanceid]);
    }

    /**
     * Deletes the rows of the three tables that belong to the given users.
     *
     * The clicks go first and on purpose. message_whatsapp_click has no userid of its own: it hangs off the queue
     * by queueid, so the only way to reach the clicks of a person is through their queue rows. Deleting the queue
     * first would cut that link and leave the clicks behind as rows nobody can attribute and nobody can erase.
     *
     * @param int[] $userids Users whose data has to go.
     * @return void
     */
    private static function delete_data_for_userids(array $userids): void {
        global $DB;

        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');

        $clicksql = "queueid IN (SELECT q.id FROM {" . self::TABLE_QUEUE . "} q WHERE q.userid {$insql})";
        $DB->delete_records_select(self::TABLE_CLICK, $clicksql, $params);
        $DB->delete_records_select(self::TABLE_QUEUE, "userid {$insql}", $params);
        $DB->delete_records_select(self::TABLE_USER, "userid {$insql}", $params);
    }

    /**
     * Builds the exportable form of every queued notification of a user, with its clicks nested inside.
     *
     * @param int $userid The owner of the messages.
     * @return array One entry per queue row, oldest first.
     */
    private static function export_messages_data(int $userid): array {
        global $DB;

        $records = $DB->get_records(self::TABLE_QUEUE, ['userid' => $userid], 'timecreated ASC, id ASC');
        if (empty($records)) {
            return [];
        }

        $clicks = self::get_clicks_by_queueid(array_keys($records));

        $messages = [];
        foreach ($records as $record) {
            $messages[] = (object)[
                'component' => $record->component,
                'name' => $record->name,
                'courseid' => (int)$record->courseid,
                'savedmessageid' => $record->savedmessageid === null ? null : (int)$record->savedmessageid,
                'phone' => $record->phone,
                'templatekey' => $record->templatekey,
                'lang' => $record->lang,
                'params' => self::decode_params($record->params),
                'url' => $record->url,
                'status' => $record->status,
                'attempts' => (int)$record->attempts,
                'nextattempt' => self::datetime($record->nextattempt),
                'providermsgid' => $record->providermsgid,
                'error' => $record->error,
                'pricingcategory' => $record->pricingcategory,
                'timecreated' => self::datetime($record->timecreated),
                'timesent' => self::datetime($record->timesent),
                'timestatus' => self::datetime($record->timestatus),
                'clicks' => $clicks[$record->id] ?? [],
            ];
        }

        return $messages;
    }

    /**
     * Reads the clicks of the given queue rows and groups them by the row they belong to.
     *
     * @param int[] $queueids Ids of the queue rows to read the clicks of.
     * @return array Clicks indexed by queueid, each one a list of exportable objects.
     */
    private static function get_clicks_by_queueid(array $queueids): array {
        global $DB;

        if (empty($queueids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($queueids, SQL_PARAMS_NAMED, 'qid');
        $records = $DB->get_records_select(self::TABLE_CLICK, "queueid {$insql}", $params, 'timeclicked ASC, id ASC');

        $clicks = [];
        foreach ($records as $record) {
            $clicks[$record->queueid][] = (object)[
                'timeclicked' => self::datetime($record->timeclicked),
                'useragent' => $record->useragent,
            ];
        }

        return $clicks;
    }

    /**
     * Turns a stored timestamp into a readable date, keeping the "never happened" case readable too.
     *
     * The three tables use 0 for "this never happened" rather than null, and transform::datetime(0) would export
     * that as the first of January of 1970, which reads like a real date and is not one.
     *
     * @param int|string|null $time The stored timestamp.
     * @return string|null The formatted date, or null when there is no date to show.
     */
    private static function datetime($time): ?string {
        $time = (int)$time;

        return $time > 0 ? transform::datetime($time) : null;
    }

    /**
     * Turns the stored JSON list of template parameters into the values that were actually sent.
     *
     * @param string|null $params The params column of a queue row.
     * @return array|string|null The decoded parameters, or the raw column when it does not decode.
     */
    private static function decode_params($params) {
        if ($params === null || $params === '') {
            return null;
        }

        $decoded = json_decode($params, true);

        return is_array($decoded) ? $decoded : $params;
    }

    /**
     * Picks the user context of the given user out of a list of contexts, if it is there.
     *
     * Nothing is exported or deleted for a context that was not approved, and nothing is exported or deleted for
     * the user context of somebody else, however it got into the list.
     *
     * @param \context[] $contexts The approved contexts.
     * @param int $userid The user whose own context is being looked for.
     * @return \context_user|null The user context of that user, or null when the list does not contain it.
     */
    private static function own_user_context(array $contexts, int $userid): ?\context_user {
        foreach ($contexts as $context) {
            if ($context instanceof \context_user && (int)$context->instanceid === $userid) {
                return $context;
            }
        }

        return null;
    }
}
