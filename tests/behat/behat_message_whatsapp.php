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
 * Behat steps of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @category   test
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;
use message_whatsapp\local\queue;

/**
 * Behat steps of the WhatsApp message processor.
 *
 * The consent lives in a table of the plugin and not in a user preference, so the outcome of the dialogue cannot be
 * checked with a core step. These steps read the row, which is exactly what the queue will read later on.
 *
 * @package    message_whatsapp
 * @category   test
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_message_whatsapp extends behat_base {
    /**
     * Checks the consent stored for a user.
     *
     * @Then /^the WhatsApp opt-in of "(?P<username_string>(?:[^"]|\\")*)" should be "(?P<optin_string>[01])"$/
     * @param string $username Username of the user.
     * @param string $optin Expected value of the optin column, 0 or 1.
     * @return void
     */
    public function the_whatsapp_optin_of_should_be(string $username, string $optin): void {
        $this->assert_field($username, 'optin', $optin);
    }

    /**
     * Checks the phone number stored for a user.
     *
     * @Then /^the WhatsApp number of "(?P<username_string>(?:[^"]|\\")*)" should be "(?P<phone_string>[^"]*)"$/
     * @param string $username Username of the user.
     * @param string $phone Expected value of the phone column, in E.164.
     * @return void
     */
    public function the_whatsapp_number_of_should_be(string $username, string $phone): void {
        $this->assert_field($username, 'phone', $phone);
    }

    /**
     * Puts rows straight into the outgoing queue, in whatever state the scenario needs them in.
     *
     * The delivery report has to be read against messages that already went somewhere, and the states it is worth
     * reading are the ones nothing in a test can reach on its own: a message the provider refused, one it
     * accepted, one the queue never attempted. Driving the queue to those through the sending task would mean
     * standing up a provider that fails on demand, which tests the transport and not the report.
     *
     * The columns of the table are `user`, `component`, `name`, `status`, `attempts` and `error`; everything else
     * gets the value a real row would carry. The phone number is deliberately a fixed fake one: it is never shown
     * by the report, and a test fixture is not a place to put a number that could be somebody's.
     *
     * @Given /^the following WhatsApp queue entries exist:$/
     * @param TableNode $data Rows to insert.
     * @return void
     */
    public function the_following_whatsapp_queue_entries_exist(TableNode $data): void {
        global $DB;

        $now = time();

        foreach ($data->getHash() as $index => $entry) {
            $username = $entry['user'] ?? '';
            $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
            $status = $entry['status'] ?? queue::STATUS_PENDING;

            $DB->insert_record(queue::TABLE, (object) [
                'userid' => $userid,
                'savedmessageid' => null,
                'component' => $entry['component'] ?? 'moodle',
                'name' => $entry['name'] ?? 'instantmessage',
                'courseid' => 0,
                'phone' => '+5491100000000',
                'templatekey' => 'moodle_notification',
                'lang' => 'en',
                'params' => json_encode(['Site', 'Subject', 'Body']),
                'url' => '',
                'status' => $status,
                'attempts' => (int) ($entry['attempts'] ?? 0),
                'nextattempt' => 0,
                'providermsgid' => null,
                'error' => ($entry['error'] ?? '') === '' ? null : $entry['error'],
                'pricingcategory' => null,
                // Descending ids and descending times, so that the default ordering of the report is stable.
                'timecreated' => $now - $index,
                'timesent' => $status === queue::STATUS_SENT ? $now - $index : 0,
                'timestatus' => $now - $index,
            ]);
        }
    }

    /**
     * Checks the state a queue entry of a user ended up in.
     *
     * @Then /^the WhatsApp entry of "(?P<user_string>[^"]*)" is "(?P<status_string>[^"]*)" with (?P<n_number>\d+) tries$/
     * @param string $username Username of the recipient.
     * @param string $status Expected value of the status column.
     * @param string $attempts Expected value of the attempts column.
     * @return void
     */
    public function the_whatsapp_entry_of_is_with_tries(string $username, string $status, string $attempts): void {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $records = $DB->get_records(queue::TABLE, ['userid' => $userid], 'id ASC');

        if (count($records) !== 1) {
            throw new ExpectationException(
                "The user '$username' has " . count($records) . ' WhatsApp queue entries and not one',
                $this->getSession()
            );
        }

        $record = reset($records);
        $actual = $record->status . '/' . (int) $record->attempts;
        $expected = $status . '/' . (int) $attempts;

        if ($actual !== $expected) {
            throw new ExpectationException(
                "The WhatsApp queue entry of '$username' is '$actual' and not '$expected'",
                $this->getSession()
            );
        }
    }

    /**
     * Checks one column of the message_whatsapp_user row of a user.
     *
     * @param string $username Username of the user.
     * @param string $column Name of the column to check.
     * @param string $expected Expected value of the column.
     * @return void
     */
    protected function assert_field(string $username, string $column, string $expected): void {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $record = $DB->get_record('message_whatsapp_user', ['userid' => $userid]);

        if (!$record) {
            throw new ExpectationException("The user '$username' has no WhatsApp row", $this->getSession());
        }

        $actual = (string) $record->{$column};

        if ($actual !== $expected) {
            throw new ExpectationException(
                "The WhatsApp $column of '$username' is '$actual' and not '$expected'",
                $this->getSession()
            );
        }
    }
}
