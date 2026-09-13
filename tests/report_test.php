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
 * Tests for the delivery report of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use context_system;
use core_reportbuilder\external\systemreports\retrieve;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\system_report_factory;
use message_whatsapp\local\queue;
use message_whatsapp\reportbuilder\local\entities\queue as queueentity;
use message_whatsapp\reportbuilder\local\systemreports\log;

/**
 * Tests for the delivery report of the WhatsApp message processor.
 *
 * The report is read through the same external method the table itself uses to page and sort, which is what makes
 * these tests worth writing: that path builds the report from scratch, runs its SQL, applies the stored filter
 * values and puts every column callback through its paces, and it is also the door a request can come in through
 * without going anywhere near `report.php`.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\reportbuilder\local\systemreports\log
 * @covers     \message_whatsapp\reportbuilder\local\entities\queue
 */
final class report_test extends \advanced_testcase {
    /**
     * Every test starts from an empty queue and an administrator looking at it.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The report lists what is in the queue, newest first, under the six headings of the plan.
     *
     * @return void
     */
    public function test_report_lists_the_queue_newest_first(): void {
        $ana = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Alumna']);
        $beto = $this->getDataGenerator()->create_user(['firstname' => 'Beto', 'lastname' => 'Alumno']);

        $this->add_row($beto->id, queue::STATUS_SENT, 1, null, 'mod_forum', 'posts', 100);
        $this->add_row($ana->id, queue::STATUS_FAILED, 5, 'Cloud API error 131026', 'mod_assign', 'assign', 200);

        $result = $this->retrieve();

        $this->assertSame([
            'Recipient',
            'Component',
            'Status',
            'Attempts',
            'Last error',
            'Queued',
        ], $result['data']['headers']);

        $this->assertEquals(2, $result['data']['totalrowcount']);

        [$recipient, $component, $status, $attempts, $error] = $result['data']['rows'][0]['columns'];

        $this->assertStringContainsString('Ana Alumna', $recipient);
        $this->assertSame('mod_assign / assign', $component);
        $this->assertSame('Failed', $status);
        $this->assertSame('5', (string) $attempts);
        $this->assertSame('Cloud API error 131026', $error);

        $this->assertStringContainsString('Beto Alumno', $result['data']['rows'][1]['columns'][0]);
    }

    /**
     * Filtering by a status leaves only the rows in it.
     *
     * @return void
     */
    public function test_the_status_filter_narrows_the_report(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Alumna']);

        $this->add_row($user->id, queue::STATUS_FAILED, 5, 'Cloud API error 131026', 'mod_assign', 'assign', 200);
        $this->add_row($user->id, queue::STATUS_SENT, 1, null, 'mod_forum', 'posts', 100);
        $this->add_row($user->id, queue::STATUS_SKIPPED, 0, queue::SKIP_NO_OPTIN, 'moodle', 'instantmessage', 50);

        $this->assertEquals(3, $this->retrieve()['data']['totalrowcount']);

        $report = system_report_factory::create(log::class, context_system::instance());
        $report->set_filter_values([
            'queue:status_operator' => select::EQUAL_TO,
            'queue:status_value' => queue::STATUS_FAILED,
        ]);

        $result = $this->retrieve();

        $this->assertEquals(1, $result['data']['totalrowcount']);
        $this->assertSame('Failed', $result['data']['rows'][0]['columns'][2]);
    }

    /**
     * The reasons the plugin writes into the error column are shown as sentences, not as the stored value.
     *
     * A skipped row says `nooptin`, which means nothing to the person reading the report and, worse, looks like
     * something the provider said.
     *
     * @return void
     */
    public function test_the_reasons_the_plugin_writes_are_translated(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->add_row($user->id, queue::STATUS_SKIPPED, 0, queue::SKIP_NO_OPTIN, 'moodle', 'instantmessage', 10);

        $error = $this->retrieve()['data']['rows'][0]['columns'][4];

        $this->assertStringNotContainsString(queue::SKIP_NO_OPTIN, $error);
        $this->assertStringContainsString('has not consented', $error);
    }

    /**
     * A diagnostic from the provider is escaped before it reaches the page.
     *
     * The column holds text this site did not write. The sanitiser of the transport takes the token and anything
     * that looks like a phone number out of it, but it is not an HTML escaper and is not asked to be one: report
     * builder writes column values into the table without escaping them, so the escaping belongs here.
     *
     * @return void
     */
    public function test_a_diagnostic_from_the_provider_is_escaped(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->add_row($user->id, queue::STATUS_FAILED, 5, '<script>alert(1)</script>', 'mod_assign', 'assign', 10);

        $error = $this->retrieve()['data']['rows'][0]['columns'][4];

        $this->assertStringNotContainsString('<script>', $error);
        $this->assertStringContainsString('&lt;script&gt;', $error);
    }

    /**
     * Retrying is offered on a failed message and on no other state.
     *
     * The three states of a message the provider already took are the ones that matter here: re-queueing one of
     * those sends somebody a second copy of a notification they already have.
     *
     * @return void
     */
    public function test_retry_is_offered_only_on_a_failed_message(): void {
        $report = system_report_factory::create(log::class, context_system::instance());
        $actions = $report->get_actions();

        $this->assertCount(1, $actions);
        $action = reset($actions);

        $offered = [];
        foreach (array_keys(queueentity::status_menu()) as $status) {
            $link = $action->get_action_link((object) ['id' => 1, 'status' => $status]);
            if ($link !== null) {
                $offered[] = $status;
            }
        }

        $this->assertSame([queue::STATUS_FAILED], $offered);
    }

    /**
     * The link of the action carries a session key, so the confirmation cannot be reached by a bare GET.
     *
     * @return void
     */
    public function test_the_retry_link_carries_a_session_key(): void {
        $report = system_report_factory::create(log::class, context_system::instance());
        $actions = $report->get_actions();
        $link = reset($actions)->get_action_link((object) ['id' => 42, 'status' => queue::STATUS_FAILED]);

        $this->assertNotNull($link);
        $this->assertSame('42', $link->url->param('id'));
        $this->assertSame(sesskey(), $link->url->param('sesskey'));
        $this->assertSame('retry', $link->url->param('action'));
    }

    /**
     * Someone without the capability is refused by the report itself, not only by the page around it.
     *
     * @return void
     */
    public function test_a_user_without_the_capability_cannot_read_the_report(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->add_row($user->id, queue::STATUS_FAILED, 5, 'Cloud API error 131026', 'mod_assign', 'assign', 10);

        $this->setUser($user);

        $this->expectException(\core_reportbuilder\exception\report_access_exception::class);
        $this->retrieve();
    }

    /**
     * A role that holds the capability is let in.
     *
     * @return void
     */
    public function test_a_user_with_the_capability_can_read_the_report(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->add_row($user->id, queue::STATUS_SENT, 1, null, 'mod_forum', 'posts', 10);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('message/whatsapp:viewlog', CAP_ALLOW, $roleid, context_system::instance()->id);
        role_assign($roleid, $user->id, context_system::instance()->id);

        $this->setUser($user);

        $this->assertEquals(1, $this->retrieve()['data']['totalrowcount']);
    }

    /**
     * The phone number of the recipient is not one of the things the report prints.
     *
     * @return void
     */
    public function test_the_report_does_not_print_the_phone_number(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->add_row($user->id, queue::STATUS_SENT, 1, null, 'mod_forum', 'posts', 10);

        $result = $this->retrieve();

        $this->assertNotContains('Phone', $result['data']['headers']);
        $this->assertStringNotContainsString(
            '+5491133334444',
            implode(' ', $result['data']['rows'][0]['columns'])
        );
    }

    /**
     * Reads the report the way the table does.
     *
     * @return array The result of the external method.
     */
    private function retrieve(): array {
        return retrieve::execute(log::class, ['contextid' => context_system::instance()->id], '', '', 0, [], 0, 30);
    }

    /**
     * Writes one row of the queue in a given state.
     *
     * The states this report is worth reading in are the ones nothing can reach without a provider that fails on
     * demand, so they are written straight into the table, which is also what the delivery reports of Meta end up
     * doing.
     *
     * @param int $userid Recipient.
     * @param string $status One of the queue states.
     * @param int $attempts Attempts already made.
     * @param string|null $error Value of the error column.
     * @param string $component Frankenstyle component of the event.
     * @param string $name Message provider name inside the component.
     * @param int $age Seconds ago the row was queued, so that the ordering of the report is fixed.
     * @return int Id of the row.
     */
    private function add_row(
        int $userid,
        string $status,
        int $attempts,
        ?string $error,
        string $component,
        string $name,
        int $age
    ): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record(queue::TABLE, (object) [
            'userid' => $userid,
            'savedmessageid' => null,
            'component' => $component,
            'name' => $name,
            'courseid' => 0,
            'phone' => '+5491133334444',
            'templatekey' => 'moodle_notification',
            'lang' => 'en',
            'params' => json_encode(['Demo', 'Subject', 'Body']),
            'url' => '',
            'status' => $status,
            'attempts' => $attempts,
            'nextattempt' => 0,
            'providermsgid' => null,
            'error' => $error,
            'pricingcategory' => null,
            'timecreated' => $now - $age,
            'timesent' => $status === queue::STATUS_SENT ? $now - $age : 0,
            'timestatus' => $now - $age,
        ]);
    }
}
