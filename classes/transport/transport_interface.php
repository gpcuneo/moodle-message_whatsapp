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
 * Contract of everything that can put a WhatsApp template on the wire.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\transport;

/**
 * What the plugin needs from a way of sending a WhatsApp template, and nothing more.
 *
 * The plugin has two sending modes that differ only here: in direct mode it talks to the Graph API of Meta with the
 * credentials of the site, and in gateway mode it talks to the wa-gateway service with an API key. Everything else
 * -- the opt-in, the phone numbers, the sanitising, the queue, the retries and the report -- is the same code in
 * both modes, so this interface is the whole of the difference between them.
 *
 * Three rules hold for every implementation:
 *
 * 1. **It never throws.** Both methods answer with a {@see result}, including when the network is down, the
 *    credentials are wrong or the provider returned something nobody expected. The caller of `send_template()` is
 *    a scheduled task that is draining a queue and must not lose the rest of the batch over one row, and the
 *    caller of `check()` is an administration screen that has to print the problem rather than a stack trace.
 * 2. **It is the only part of the plugin that reaches the network.** Nothing above this interface makes an HTTP
 *    request, because `send_message()` runs inside the web request of the user that triggered the event.
 * 3. **It sends approved templates only.** Meta allows free text just inside the 24 hour window that opens when
 *    the user writes first, and a Moodle notification almost never falls inside it. There is deliberately no
 *    method here for sending a message that is not a template.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface transport_interface {
    /**
     * Sends one approved template to one phone number.
     *
     * The parameters arrive already sanitised and within the length limits of the template: they come from
     * {@see \message_whatsapp\local\mapped}, which is what the queue stored when the notification was raised. An
     * implementation formats them for its provider and sends them; it does not edit them.
     *
     * The language is the one frozen at queue time and not the one of the user right now. A template is approved by
     * Meta per language, and a profile that changed language between the queueing and the send must not make the
     * plugin ask for a version that was never approved.
     *
     * @param string $phone Destination in E.164, with no separators and no leading plus sign convention of its own.
     * @param string $template Name of the approved template, as registered with the provider.
     * @param string $lang Language code of the approved version of the template, for instance `es_AR` or `en`.
     * @param string[] $params Body parameters in template order, already sanitised.
     * @param string|null $urlsuffix Dynamic suffix of the URL button, or null when the template has no button.
     * @return result Outcome of the attempt. Never throws: a failure is a result, not an exception.
     */
    public function send_template(string $phone, string $template, string $lang, array $params, ?string $urlsuffix): result;

    /**
     * Checks that this transport could send something right now, without sending anything.
     *
     * This is what the "test connection" button of the settings page calls, so it answers the question an
     * administrator is actually asking: are these credentials valid and is this account able to send. It costs
     * nothing and delivers nothing.
     *
     * @return result Success when the transport is usable, otherwise the reason why it is not.
     */
    public function check(): result;

    /**
     * Returns the short machine name of this transport.
     *
     * It goes into the `mtrace()` output of the sending task and into the administration screens, so that a log
     * line says which of the two modes produced it. It is a stable identifier and not a translated label: it is
     * compared in tests and grepped in logs.
     *
     * @return string Machine name, for instance `meta_cloud` or `gateway`.
     */
    public function name(): string;
}
