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
 * Test fixture: the status sync task with its transport pointed at a mock handler.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use core\http_client;
use GuzzleHttp\Handler\MockHandler;
use message_whatsapp\task\sync_status;
use message_whatsapp\transport\gateway;

/**
 * A task whose transport reaches a mock handler instead of the service.
 *
 * The seam is {@see sync_status::transport()}, which is protected for exactly this: the task decides what to do
 * with a status change and the transport decides what the wire said, and a test of the first should not have to
 * stand up the second.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_status_with_mock extends sync_status {
    /** @var MockHandler Answers the gateway gives in this test. */
    public static MockHandler $mock;

    /**
     * Returns a transport that talks to the mock handler.
     *
     * @return gateway The transport.
     */
    protected function transport(): gateway {
        return gateway::with_client(new http_client(['mock' => self::$mock]));
    }
}
