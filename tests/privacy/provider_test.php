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
 * Tests for the privacy provider of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\privacy;

use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for the privacy provider of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var string Table with the phone number and the opt-in of each user. */
    private const TABLE_USER = 'message_whatsapp_user';

    /** @var string Table with one row per outgoing notification. */
    private const TABLE_QUEUE = 'message_whatsapp_queue';

    /** @var string Table with one row per click on the button of a sent message. */
    private const TABLE_CLICK = 'message_whatsapp_click';

    /**
     * Every test starts from an empty site.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Writes a phone number and a consent for a user.
     *
     * @param int $userid The owner of the row.
     * @param string $phone The number in E.164.
     * @param int $optin 1 when the user consented.
     * @return int The id of the new row.
     */
    private function add_user_row(int $userid, string $phone = '+5491141234567', int $optin = 1): int {
        global $DB;

        return $DB->insert_record(self::TABLE_USER, (object)[
            'userid' => $userid,
            'phone' => $phone,
            'source' => 'phone2',
            'optin' => $optin,
            'optintime' => 1757700000,
            'verified' => 0,
            'verifiedtime' => 0,
            'status' => 'active',
            'timecreated' => 1757600000,
            'timemodified' => 1757700000,
        ]);
    }

    /**
     * Writes a queued notification for a user.
     *
     * @param int $userid The addressee.
     * @param int $courseid The course the notification came from, 0 for site wide.
     * @param string $status The delivery status of the row.
     * @return int The id of the new row.
     */
    private function add_queue_row(int $userid, int $courseid = 0, string $status = 'sent'): int {
        global $DB;

        return $DB->insert_record(self::TABLE_QUEUE, (object)[
            'userid' => $userid,
            'savedmessageid' => null,
            'component' => 'mod_assign',
            'name' => 'assign_notification',
            'courseid' => $courseid,
            'phone' => '+5491141234567',
            'templatekey' => 'moodle_notification',
            'lang' => 'es_AR',
            'params' => json_encode(['Demo', 'Assignment due', 'Hand in before Friday']),
            'url' => 'https://example.com/mod/assign/view.php?id=1',
            'status' => $status,
            'attempts' => 1,
            'nextattempt' => 0,
            'providermsgid' => 'wamid.TEST',
            'error' => null,
            'pricingcategory' => 'utility',
            'timecreated' => 1757600100,
            'timesent' => 1757600200,
            'timestatus' => 1757600300,
        ]);
    }

    /**
     * Writes a click on the button of a queued notification.
     *
     * @param int $queueid The message whose button was clicked.
     * @return int The id of the new row.
     */
    private function add_click_row(int $queueid): int {
        global $DB;

        return $DB->insert_record(self::TABLE_CLICK, (object)[
            'queueid' => $queueid,
            'timeclicked' => 1757600400,
            'useragent' => 'Mozilla/5.0 (Linux; Android 14)',
        ]);
    }

    /**
     * Creates a user with a row in each of the three tables.
     *
     * @param int $courseid The course of the queued notification.
     * @return \stdClass The new user.
     */
    private function user_with_full_data(int $courseid = 0): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->add_user_row((int)$user->id);
        $this->add_click_row($this->add_queue_row((int)$user->id, $courseid));

        return $user;
    }

    /**
     * The metadata names the three tables, field by field, and the two external locations.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('message_whatsapp'));
        $items = $collection->get_collection();

        $tables = [];
        $locations = [];
        foreach ($items as $item) {
            if ($item instanceof database_table) {
                $tables[$item->get_name()] = $item;
            } else if ($item instanceof external_location) {
                $locations[$item->get_name()] = $item;
            }
        }

        $this->assertEqualsCanonicalizing(
            [self::TABLE_USER, self::TABLE_QUEUE, self::TABLE_CLICK],
            array_keys($tables)
        );
        $this->assertEqualsCanonicalizing(['meta_cloud_api', 'wa_gateway'], array_keys($locations));

        // Every column of every table, except the surrogate id, has to be declared.
        foreach ([self::TABLE_USER, self::TABLE_QUEUE, self::TABLE_CLICK] as $table) {
            $columns = array_keys($this->columns_of($table));
            $declared = array_keys($tables[$table]->get_privacy_fields());
            $this->assertEqualsCanonicalizing(array_values(array_diff($columns, ['id'])), $declared, "Fields of {$table}");
        }

        // What leaves the site is the phone number and the parameters of the template, in both modes.
        foreach (['meta_cloud_api', 'wa_gateway'] as $name) {
            $fields = $locations[$name]->get_privacy_fields();
            $this->assertArrayHasKey('phone', $fields);
            $this->assertArrayHasKey('params', $fields);
            $this->assertArrayHasKey('templatekey', $fields);
            $this->assertArrayHasKey('url', $fields);
        }
        $this->assertArrayHasKey('queueid', $locations['wa_gateway']->get_privacy_fields());
    }

    /**
     * Every language string identifier named by the metadata exists in the language pack.
     *
     * @return void
     */
    public function test_metadata_strings_exist(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('message_whatsapp'));

        foreach ($collection->get_collection() as $item) {
            $this->assertTrue(
                get_string_manager()->string_exists($item->get_summary(), 'message_whatsapp'),
                "Missing summary string {$item->get_summary()}"
            );
            foreach ($item->get_privacy_fields() as $field => $identifier) {
                $this->assertTrue(
                    get_string_manager()->string_exists($identifier, 'message_whatsapp'),
                    "Missing string {$identifier} for field {$field}"
                );
            }
        }
    }

    /**
     * A user with data is reported in their own user context, and nowhere else.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $user = $this->user_with_full_data();

        $contextlist = provider::get_contexts_for_userid((int)$user->id);

        $this->assertCount(1, $contextlist);
        $context = $contextlist->current();
        $this->assertInstanceOf(\context_user::class, $context);
        $this->assertEquals($user->id, $context->instanceid);
    }

    /**
     * A queued notification of a course is still reported in the user context, never in the course one.
     *
     * @return void
     */
    public function test_get_contexts_for_userid_ignores_the_course_of_the_queue(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->user_with_full_data((int)$course->id);

        $contextlist = provider::get_contexts_for_userid((int)$user->id);

        $this->assertCount(1, $contextlist);
        $this->assertEquals(\context_user::instance($user->id)->id, $contextlist->current()->id);
    }

    /**
     * A user the plugin knows nothing about gets no context at all.
     *
     * @return void
     */
    public function test_get_contexts_for_userid_without_data(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertCount(0, provider::get_contexts_for_userid((int)$user->id));
    }

    /**
     * A user with only a queue row, and no phone row, is still found.
     *
     * @return void
     */
    public function test_get_contexts_for_userid_with_only_a_queue_row(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->add_queue_row((int)$user->id);

        $this->assertCount(1, provider::get_contexts_for_userid((int)$user->id));
    }

    /**
     * The user context of a user with data lists that user, and only that user.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $user = $this->user_with_full_data();
        $other = $this->user_with_full_data();

        $userlist = new userlist(\context_user::instance($user->id), 'message_whatsapp');
        provider::get_users_in_context($userlist);

        $this->assertEquals([(int)$user->id], array_map('intval', $userlist->get_userids()));
        $this->assertNotContains((int)$other->id, array_map('intval', $userlist->get_userids()));
    }

    /**
     * A context that is not a user context never lists anybody, whatever the queue says about its course.
     *
     * @return void
     */
    public function test_get_users_in_context_ignores_other_context_levels(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->user_with_full_data((int)$course->id);

        foreach ([\context_course::instance($course->id), \context_system::instance()] as $context) {
            $userlist = new userlist($context, 'message_whatsapp');
            provider::get_users_in_context($userlist);
            $this->assertCount(0, $userlist);
        }
    }

    /**
     * The export carries the phone number, the consent, the notification and the click on it.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->user_with_full_data((int)$course->id);
        $context = \context_user::instance($user->id);

        $this->export_context_data_for_user((int)$user->id, $context, 'message_whatsapp');
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $root = [get_string('privacy:path', 'message_whatsapp')];
        $data = $writer->get_data($root);
        $this->assertEquals('+5491141234567', $data->phone);
        $this->assertEquals(get_string('yes'), $data->optin);
        $this->assertEquals('phone2', $data->source);
        $this->assertEquals('active', $data->status);
        $this->assertNull($data->verifiedtime);

        $messages = $writer->get_data(array_merge($root, [get_string('privacy:path:messages', 'message_whatsapp')]));
        $this->assertCount(1, $messages->messages);
        $message = reset($messages->messages);
        $this->assertEquals('mod_assign', $message->component);
        $this->assertEquals((int)$course->id, $message->courseid);
        $this->assertEquals(['Demo', 'Assignment due', 'Hand in before Friday'], $message->params);
        $this->assertEquals('sent', $message->status);
        $this->assertCount(1, $message->clicks);
        $this->assertEquals('Mozilla/5.0 (Linux; Android 14)', $message->clicks[0]->useragent);
    }

    /**
     * The export of a user without data writes nothing at all.
     *
     * @return void
     */
    public function test_export_user_data_without_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);

        $this->export_context_data_for_user((int)$user->id, $context, 'message_whatsapp');

        $this->assertFalse(writer::with_context($context)->has_any_data());
    }

    /**
     * The user context of somebody else exports nothing, even if it is approved.
     *
     * @return void
     */
    public function test_export_user_data_from_the_context_of_another_user(): void {
        $user = $this->user_with_full_data();
        $other = $this->user_with_full_data();
        $othercontext = \context_user::instance($other->id);

        provider::export_user_data(new approved_contextlist($user, 'message_whatsapp', [$othercontext->id]));

        $this->assertFalse(writer::with_context($othercontext)->has_any_data());
    }

    /**
     * Deleting one user takes their three tables and leaves everybody else alone.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $user = $this->user_with_full_data();
        $other = $this->user_with_full_data();
        $context = \context_user::instance($user->id);

        provider::delete_data_for_user(new approved_contextlist($user, 'message_whatsapp', [$context->id]));

        $this->assert_no_data_left((int)$user->id);
        $this->assertEquals(1, $DB->count_records(self::TABLE_USER, ['userid' => $other->id]));
        $this->assertEquals(1, $DB->count_records(self::TABLE_QUEUE, ['userid' => $other->id]));
        $this->assertEquals(1, $DB->count_records(self::TABLE_CLICK));
        $this->assertEquals(0, $this->count_orphan_clicks());
    }

    /**
     * A pending notification of a user who asked for erasure is deleted too, so it is never sent afterwards.
     *
     * @return void
     */
    public function test_delete_data_for_user_takes_the_pending_messages(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->add_user_row((int)$user->id);
        $this->add_queue_row((int)$user->id, 0, 'pending');
        $context = \context_user::instance($user->id);

        provider::delete_data_for_user(new approved_contextlist($user, 'message_whatsapp', [$context->id]));

        $this->assertEquals(0, $DB->count_records(self::TABLE_QUEUE, ['userid' => $user->id]));
    }

    /**
     * Approving the context of somebody else deletes nothing.
     *
     * @return void
     */
    public function test_delete_data_for_user_from_the_context_of_another_user(): void {
        $user = $this->user_with_full_data();
        $other = $this->user_with_full_data();

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'message_whatsapp',
            [\context_user::instance($other->id)->id]
        ));

        $this->assert_data_intact((int)$user->id);
        $this->assert_data_intact((int)$other->id);
    }

    /**
     * A user context deletes exactly one person.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        $user = $this->user_with_full_data();
        $other = $this->user_with_full_data();

        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));

        $this->assert_no_data_left((int)$user->id);
        $this->assert_data_intact((int)$other->id);
        $this->assertEquals(0, $this->count_orphan_clicks());
    }

    /**
     * A course or system context deletes nothing, even though the queue records a course.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context_is_not_a_mass_deletion(): void {
        $course = $this->getDataGenerator()->create_course();
        $one = $this->user_with_full_data((int)$course->id);
        $two = $this->user_with_full_data((int)$course->id);

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));
        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assert_data_intact((int)$one->id);
        $this->assert_data_intact((int)$two->id);
    }

    /**
     * Deleting the approved users of a user context deletes that user.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        $user = $this->user_with_full_data();
        $other = $this->user_with_full_data();
        $context = \context_user::instance($user->id);

        provider::delete_data_for_users(new approved_userlist($context, 'message_whatsapp', [(int)$user->id]));

        $this->assert_no_data_left((int)$user->id);
        $this->assert_data_intact((int)$other->id);
        $this->assertEquals(0, $this->count_orphan_clicks());
    }

    /**
     * A user approved in the context of somebody else is not deleted.
     *
     * @return void
     */
    public function test_delete_data_for_users_only_deletes_the_owner_of_the_context(): void {
        $user = $this->user_with_full_data();
        $other = $this->user_with_full_data();

        provider::delete_data_for_users(new approved_userlist(
            \context_user::instance($user->id),
            'message_whatsapp',
            [(int)$other->id]
        ));

        $this->assert_data_intact((int)$user->id);
        $this->assert_data_intact((int)$other->id);
    }

    /**
     * An empty approved user list deletes nothing.
     *
     * @return void
     */
    public function test_delete_data_for_users_with_nobody_approved(): void {
        $user = $this->user_with_full_data();

        provider::delete_data_for_users(new approved_userlist(
            \context_user::instance($user->id),
            'message_whatsapp',
            []
        ));

        $this->assert_data_intact((int)$user->id);
    }

    /**
     * Deleting a user with several notifications takes every click of every one of them.
     *
     * @return void
     */
    public function test_delete_data_for_user_leaves_no_orphan_clicks(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->add_user_row((int)$user->id);
        foreach ([0, 0, 0] as $courseid) {
            $queueid = $this->add_queue_row((int)$user->id, $courseid);
            $this->add_click_row($queueid);
            $this->add_click_row($queueid);
        }
        $otherqueueid = $this->add_queue_row((int)$other->id);
        $this->add_click_row($otherqueueid);

        $this->assertEquals(7, $DB->count_records(self::TABLE_CLICK));

        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));

        $this->assertEquals(1, $DB->count_records(self::TABLE_CLICK));
        $this->assertEquals(1, $DB->count_records(self::TABLE_CLICK, ['queueid' => $otherqueueid]));
        $this->assertEquals(0, $this->count_orphan_clicks());
    }

    /**
     * Asserts that the plugin holds nothing at all about the given user.
     *
     * @param int $userid The user to check.
     * @return void
     */
    private function assert_no_data_left(int $userid): void {
        global $DB;

        $this->assertEquals(0, $DB->count_records(self::TABLE_USER, ['userid' => $userid]));
        $this->assertEquals(0, $DB->count_records(self::TABLE_QUEUE, ['userid' => $userid]));
        $this->assertEquals(0, $DB->count_records_select(
            self::TABLE_CLICK,
            'queueid IN (SELECT q.id FROM {message_whatsapp_queue} q WHERE q.userid = :userid)',
            ['userid' => $userid]
        ));
    }

    /**
     * Asserts that the three rows of a user created by user_with_full_data() are still there.
     *
     * @param int $userid The user to check.
     * @return void
     */
    private function assert_data_intact(int $userid): void {
        global $DB;

        $this->assertEquals(1, $DB->count_records(self::TABLE_USER, ['userid' => $userid]));
        $this->assertEquals(1, $DB->count_records(self::TABLE_QUEUE, ['userid' => $userid]));
        $this->assertEquals(1, $DB->count_records_select(
            self::TABLE_CLICK,
            'queueid IN (SELECT q.id FROM {message_whatsapp_queue} q WHERE q.userid = :userid)',
            ['userid' => $userid]
        ));
    }

    /**
     * Counts the clicks whose queue row is gone, which must always be zero.
     *
     * @return int The number of orphan clicks.
     */
    private function count_orphan_clicks(): int {
        global $DB;

        return $DB->count_records_select(
            self::TABLE_CLICK,
            'queueid NOT IN (SELECT q.id FROM {message_whatsapp_queue} q)'
        );
    }

    /**
     * Reads the real columns of a table of this plugin from the database.
     *
     * @param string $table The table name without the prefix.
     * @return array The columns, as returned by the database manager.
     */
    private function columns_of(string $table): array {
        global $DB;

        return $DB->get_columns($table);
    }
}
