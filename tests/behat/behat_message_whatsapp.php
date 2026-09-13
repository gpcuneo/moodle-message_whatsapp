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

use Behat\Mink\Exception\ExpectationException;

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
