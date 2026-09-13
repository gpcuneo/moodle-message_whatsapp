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
 * Tests for the webhook Meta calls with the delivery reports of the WhatsApp messages this site sent.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use message_whatsapp\local\queue;
use message_whatsapp\local\template_mapper;

/**
 * Tests for the webhook Meta calls with the delivery reports of the WhatsApp messages this site sent.
 *
 * The payloads below are the real shape of what Meta posts, signed byte for byte the way Meta signs them, because
 * the two things this endpoint has to get right are exactly that the signature is checked over the bytes that
 * arrived and that a body without a good one never reaches anything that writes.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::message_whatsapp_webhook_report
 * @covers     ::message_whatsapp_webhook_challenge
 * @covers     ::message_whatsapp_webhook_signature_matches
 * @covers     ::message_whatsapp_webhook_apply
 * @covers     \message_whatsapp\local\queue::update_by_providermsgid
 */
final class webhook_test extends \advanced_testcase {
    /** @var string App secret of the Meta application of the test site. */
    private const SECRET = 'e9f1c0a7b4d2e8f60a1b2c3d4e5f6071';

    /** @var string Verify token the test site was configured with. */
    private const VERIFY = 'moodle-whatsapp-verify-token';

    /** @var string Message id Meta gave the message the payloads are about. */
    private const WAMID = 'wamid.HBgLNTQ5MTEyMjMzNDQ0FQIAERgSQTM1RDA5RDk1QzJDMUE5NkE2AA==';

    /** @var int Moment the payloads report their status at. */
    private const REPORTED = 1757721600;

    /**
     * Every test starts from a site configured for direct mode with an app secret and a verify token.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        require_once(__DIR__ . '/../webhook.php');

        set_config('mode', \message_output_whatsapp::MODE_DIRECT, 'message_whatsapp');
        set_config('metaappsecret', self::SECRET, 'message_whatsapp');
        set_config('metaverifytoken', self::VERIFY, 'message_whatsapp');
    }

    /**
     * The handshake answers with the challenge Meta sent when the token is the one the site was configured with.
     *
     * @return void
     */
    public function test_verification_echoes_the_challenge(): void {
        $challenge = message_whatsapp_webhook_challenge('subscribe', self::VERIFY, '1158201444', self::VERIFY);

        $this->assertSame('1158201444', $challenge);
    }

    /**
     * A wrong token gets nothing back, which is what turns into a 403.
     *
     * @return void
     */
    public function test_verification_refuses_a_wrong_token(): void {
        $this->assertNull(message_whatsapp_webhook_challenge('subscribe', 'nope', '1158201444', self::VERIFY));
    }

    /**
     * A site with no verify token configured refuses the handshake instead of accepting an empty token.
     *
     * @return void
     */
    public function test_verification_refuses_when_the_site_has_no_token(): void {
        $this->assertNull(message_whatsapp_webhook_challenge('subscribe', '', '1158201444', ''));
    }

    /**
     * Only the `subscribe` handshake is answered.
     *
     * @return void
     */
    public function test_verification_refuses_another_mode(): void {
        $this->assertNull(message_whatsapp_webhook_challenge('unsubscribe', self::VERIFY, '115820', self::VERIFY));
    }

    /**
     * The signature is over the bytes that arrived, so the same payload encoded again no longer matches.
     *
     * This is the trap of the whole endpoint: decoding the JSON and encoding it back to check the signature looks
     * equivalent and never is, because the whitespace, the escaping and the key order all move.
     *
     * @return void
     */
    public function test_signature_is_taken_over_the_raw_body(): void {
        $body = $this->status_payload('delivered');
        $reencoded = json_encode(json_decode($body, true));

        $this->assertTrue(message_whatsapp_webhook_signature_matches($body, $this->sign($body), self::SECRET));
        $this->assertNotSame($body, $reencoded);
        $this->assertFalse(
            message_whatsapp_webhook_signature_matches($body, $this->sign($reencoded), self::SECRET)
        );
    }

    /**
     * A header without the `sha256=` prefix Meta uses is not a signature.
     *
     * @return void
     */
    public function test_signature_needs_the_sha256_prefix(): void {
        $body = $this->status_payload('delivered');
        $bare = hash_hmac('sha256', $body, self::SECRET);

        $this->assertFalse(message_whatsapp_webhook_signature_matches($body, $bare, self::SECRET));
        $this->assertFalse(message_whatsapp_webhook_signature_matches($body, 'sha1=' . $bare, self::SECRET));
    }

    /**
     * A site with no app secret configured never accepts a signature, however the body was signed.
     *
     * @return void
     */
    public function test_signature_never_matches_without_a_secret(): void {
        $body = $this->status_payload('delivered');

        $this->assertFalse(message_whatsapp_webhook_signature_matches($body, 'sha256=' . hash_hmac('sha256', $body, ''), ''));
    }

    /**
     * A correctly signed `delivered` moves the row on and records what Meta will bill for it.
     *
     * @return void
     */
    public function test_a_signed_report_marks_the_row_delivered(): void {
        global $DB;

        $id = $this->sent_row();
        $body = $this->status_payload('delivered');

        $this->assertSame(200, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_DELIVERED, $row->status);
        $this->assertSame('utility', $row->pricingcategory);
        $this->assertSame(self::REPORTED, (int) $row->timestatus);
    }

    /**
     * A forged signature gets a 403 and leaves the database exactly as it was.
     *
     * @return void
     */
    public function test_a_forged_signature_returns_403_and_touches_nothing(): void {
        global $DB;

        $this->sent_row();
        $body = $this->status_payload('delivered');
        $before = $DB->get_records(queue::TABLE);

        $code = message_whatsapp_webhook_report($body, 'sha256=' . str_repeat('0', 64), self::SECRET);

        $this->assertSame(403, $code);
        $this->assertEquals($before, $DB->get_records(queue::TABLE));
        $this->assertSame(0, $DB->count_records('message_whatsapp_click'));
    }

    /**
     * A body signed with some other secret is a forgery like any other.
     *
     * @return void
     */
    public function test_a_report_signed_with_another_secret_is_refused(): void {
        global $DB;

        $id = $this->sent_row();
        $body = $this->status_payload('delivered');
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'the-wrong-secret');

        $this->assertSame(403, message_whatsapp_webhook_report($body, $signature, self::SECRET));
        $this->assertSame(queue::STATUS_SENT, $DB->get_field(queue::TABLE, 'status', ['id' => $id]));
    }

    /**
     * A report with no signature header at all is refused.
     *
     * @return void
     */
    public function test_an_unsigned_report_is_refused(): void {
        global $DB;

        $id = $this->sent_row();
        $body = $this->status_payload('delivered');

        $this->assertSame(403, message_whatsapp_webhook_report($body, '', self::SECRET));
        $this->assertSame(queue::STATUS_SENT, $DB->get_field(queue::TABLE, 'status', ['id' => $id]));
    }

    /**
     * An empty body with an empty header is refused, and is never parsed.
     *
     * @return void
     */
    public function test_an_empty_body_is_refused(): void {
        $this->assertSame(403, message_whatsapp_webhook_report('', '', self::SECRET));
    }

    /**
     * A signed body that is not JSON is answered with 400, and still writes nothing.
     *
     * @return void
     */
    public function test_invalid_json_is_refused_with_400_and_touches_nothing(): void {
        global $DB;

        $this->sent_row();
        $body = '{"object": "whatsapp_business_account", "entry": [';
        $before = $DB->get_records(queue::TABLE);

        $this->assertSame(400, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));
        $this->assertEquals($before, $DB->get_records(queue::TABLE));
    }

    /**
     * A body far larger than any delivery report is refused on its size, before the signature or the parser.
     *
     * @return void
     */
    public function test_a_body_over_the_limit_is_refused_without_parsing(): void {
        global $DB;

        $id = $this->sent_row();
        $body = str_repeat('a', MESSAGE_WHATSAPP_WEBHOOK_MAX_BODY + 1);

        $this->assertSame(413, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));
        $this->assertSame(queue::STATUS_SENT, $DB->get_field(queue::TABLE, 'status', ['id' => $id]));
    }

    /**
     * A status about a message this site never sent is accepted and changes nothing.
     *
     * Meta must get a 200 or it retries the delivery and eventually disables the subscription, and a message id we
     * do not know is not an error: it can belong to another site sharing the same application.
     *
     * @return void
     */
    public function test_a_status_for_an_unknown_message_is_accepted_and_changes_nothing(): void {
        global $DB;

        $before = $DB->get_records(queue::TABLE);
        $body = $this->status_payload('delivered');

        $this->assertSame(200, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));
        $this->assertEquals($before, $DB->get_records(queue::TABLE));
    }

    /**
     * The same report twice, which is what Meta does when it is not sure we got the first one, changes nothing.
     *
     * @return void
     */
    public function test_a_repeated_report_changes_nothing(): void {
        global $DB;

        $id = $this->sent_row();
        $body = $this->status_payload('delivered');

        message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET);
        $after = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);

        $this->assertSame(200, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));
        $this->assertEquals($after, $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST));
    }

    /**
     * A `delivered` arriving after the `read` of the same message leaves the `read` alone.
     *
     * @return void
     */
    public function test_a_late_delivered_does_not_undo_a_read(): void {
        global $DB;

        $id = $this->sent_row(['status' => queue::STATUS_READ, 'timestatus' => self::REPORTED + 60]);
        $body = $this->status_payload('delivered');

        $this->assertSame(200, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_READ, $row->status);
        $this->assertSame(self::REPORTED + 60, (int) $row->timestatus);
    }

    /**
     * A `read` does move a row that was already `delivered` forward.
     *
     * @return void
     */
    public function test_read_moves_a_delivered_row_forward(): void {
        global $DB;

        $id = $this->sent_row(['status' => queue::STATUS_DELIVERED]);
        $body = $this->status_payload('read');

        $this->assertSame(200, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));
        $this->assertSame(queue::STATUS_READ, $DB->get_field(queue::TABLE, 'status', ['id' => $id]));
    }

    /**
     * A failure is recorded by its code and its title, and never by the text that quotes the phone number.
     *
     * @return void
     */
    public function test_failed_records_the_code_without_the_phone_number(): void {
        global $DB;

        $id = $this->sent_row();
        $body = $this->status_payload('failed', $this->failure_errors());

        $this->assertSame(200, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));

        $row = $DB->get_record(queue::TABLE, ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(queue::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('131049', $row->error);
        $this->assertStringNotContainsString('5491122334444', $row->error);
        $this->assertStringNotContainsString('allowed list', $row->error);
    }

    /**
     * Proof that the message did arrive outranks a failure, which only ever means it did not.
     *
     * @return void
     */
    public function test_delivered_supersedes_a_failure(): void {
        global $DB;

        $id = $this->sent_row(['status' => queue::STATUS_FAILED]);
        $body = $this->status_payload('delivered');

        message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET);

        $this->assertSame(queue::STATUS_DELIVERED, $DB->get_field(queue::TABLE, 'status', ['id' => $id]));
    }

    /**
     * A notification that is not about delivery statuses is accepted and ignored.
     *
     * @return void
     */
    public function test_a_payload_without_statuses_is_accepted(): void {
        global $DB;

        $this->sent_row();
        $before = $DB->get_records(queue::TABLE);
        $body = $this->template_update_payload();

        $this->assertSame(200, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));
        $this->assertEquals($before, $DB->get_records(queue::TABLE));
    }

    /**
     * An inbound message is counted for v2 and nothing of what it carries is written down anywhere.
     *
     * @return void
     */
    public function test_inbound_messages_are_counted_and_nothing_else(): void {
        global $DB;

        $this->sent_row();
        $before = $DB->get_records(queue::TABLE);
        $body = $this->inbound_payload();

        $this->assertSame(200, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));
        $this->assertDebuggingCalled(
            'message_whatsapp: 1 inbound WhatsApp message(s) received; v1 does not answer them'
        );
        $this->assertEquals($before, $DB->get_records(queue::TABLE));
    }

    /**
     * A status of a kind the plugin does not know is ignored rather than written to the row.
     *
     * @return void
     */
    public function test_a_status_of_an_unknown_kind_is_ignored(): void {
        global $DB;

        $id = $this->sent_row();
        $body = $this->status_payload('warning');

        $this->assertSame(200, message_whatsapp_webhook_report($body, $this->sign($body), self::SECRET));
        $this->assertSame(queue::STATUS_SENT, $DB->get_field(queue::TABLE, 'status', ['id' => $id]));
    }

    /**
     * A status with no message id matches nothing, and in particular does not match the rows that have none.
     *
     * @return void
     */
    public function test_a_status_without_an_id_is_ignored(): void {
        global $DB;

        $id = $this->sent_row(['providermsgid' => null]);

        $this->assertFalse(queue::update_by_providermsgid('', queue::STATUS_DELIVERED));
        $this->assertSame(queue::STATUS_SENT, $DB->get_field(queue::TABLE, 'status', ['id' => $id]));
    }

    /**
     * The ordering rule on its own, without a row in sight.
     *
     * @return void
     */
    public function test_the_order_of_the_delivery_statuses(): void {
        $this->assertTrue(queue::supersedes(queue::STATUS_SENT, queue::STATUS_SENDING));
        $this->assertTrue(queue::supersedes(queue::STATUS_FAILED, queue::STATUS_SENT));
        $this->assertTrue(queue::supersedes(queue::STATUS_DELIVERED, queue::STATUS_FAILED));
        $this->assertTrue(queue::supersedes(queue::STATUS_READ, queue::STATUS_DELIVERED));

        $this->assertFalse(queue::supersedes(queue::STATUS_DELIVERED, queue::STATUS_READ));
        $this->assertFalse(queue::supersedes(queue::STATUS_SENT, queue::STATUS_DELIVERED));
        $this->assertFalse(queue::supersedes(queue::STATUS_DELIVERED, queue::STATUS_DELIVERED));
        $this->assertFalse(queue::supersedes('warning', queue::STATUS_SENT));
    }

    /**
     * Signs a body the way Meta signs it.
     *
     * @param string $body Raw body.
     * @return string Value for the `X-Hub-Signature-256` header.
     */
    private function sign(string $body): string {
        return 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
    }

    /**
     * Writes a queue row that has already been accepted by Meta, which is the only kind a report can be about.
     *
     * @param array $overrides Fields to change.
     * @return int Id of the row.
     */
    private function sent_row(array $overrides = []): int {
        global $DB;

        $now = time();
        $user = $this->getDataGenerator()->create_user();

        return (int) $DB->insert_record(queue::TABLE, (object) ($overrides + [
            'userid' => $user->id,
            'savedmessageid' => null,
            'component' => 'mod_assign',
            'name' => 'assign_notification',
            'courseid' => 0,
            'phone' => '+5491122334444',
            'templatekey' => template_mapper::TEMPLATE,
            'lang' => 'es_AR',
            'params' => '["Demo","Tarea 1 calificada","Tu entrega fue calificada."]',
            'url' => 'https://example.com/mod/assign/view.php?id=1',
            'status' => queue::STATUS_SENT,
            'attempts' => 1,
            'nextattempt' => 0,
            'providermsgid' => self::WAMID,
            'error' => null,
            'pricingcategory' => null,
            'timecreated' => $now,
            'timesent' => $now,
            'timestatus' => $now,
        ]));
    }

    /**
     * Returns a delivery report exactly as Meta posts one.
     *
     * @param string $state Value of `status`.
     * @param string $extra Extra members of the status object, starting with a comma.
     * @return string Raw body.
     */
    private function status_payload(string $state, string $extra = ''): string {
        $template = <<<'JSON'
        {
          "object": "whatsapp_business_account",
          "entry": [
            {
              "id": "104598152735435",
              "changes": [
                {
                  "value": {
                    "messaging_product": "whatsapp",
                    "metadata": {
                      "display_phone_number": "15550781234",
                      "phone_number_id": "106540352242922"
                    },
                    "statuses": [
                      {
                        "id": "%s",
                        "status": "%s",
                        "timestamp": "%d",
                        "recipient_id": "5491122334444",
                        "conversation": {
                          "id": "2b9f7a1c0d3e4f5a6b7c8d9e0f1a2b3c",
                          "origin": {
                            "type": "utility"
                          }
                        },
                        "pricing": {
                          "billable": true,
                          "pricing_model": "CBP",
                          "category": "utility"
                        }%s
                      }
                    ]
                  },
                  "field": "messages"
                }
              ]
            }
          ]
        }
        JSON;

        return sprintf($template, self::WAMID, $state, self::REPORTED, $extra);
    }

    /**
     * Returns the `errors` member Meta adds to a failed status, with its two chatty fields included.
     *
     * @return string JSON fragment, starting with a comma.
     */
    private function failure_errors(): string {
        return <<<'JSON'
        ,
                        "errors": [
                          {
                            "code": 131049,
                            "title": "Message not delivered to maintain engagement",
                            "message": "Recipient phone number not in allowed list: +5491122334444",
                            "error_data": {
                              "details": "Recipient 5491122334444 is not in the allowed list"
                            }
                          }
                        ]
        JSON;
    }

    /**
     * Returns the payload Meta posts when a user writes to the number of the site.
     *
     * @return string Raw body.
     */
    private function inbound_payload(): string {
        return <<<'JSON'
        {
          "object": "whatsapp_business_account",
          "entry": [
            {
              "id": "104598152735435",
              "changes": [
                {
                  "value": {
                    "messaging_product": "whatsapp",
                    "metadata": {
                      "display_phone_number": "15550781234",
                      "phone_number_id": "106540352242922"
                    },
                    "contacts": [
                      {
                        "profile": {
                          "name": "Alumna de Prueba"
                        },
                        "wa_id": "5491122334444"
                      }
                    ],
                    "messages": [
                      {
                        "from": "5491122334444",
                        "id": "wamid.HBgLNTQ5MTEyMjMzNDQ0FQIAEhggMEE4RjRBAA==",
                        "timestamp": "1757721700",
                        "text": {
                          "body": "Hola, no entendi la consigna"
                        },
                        "type": "text"
                      }
                    ]
                  },
                  "field": "messages"
                }
              ]
            }
          ]
        }
        JSON;
    }

    /**
     * Returns the payload Meta posts when it finishes reviewing a template.
     *
     * @return string Raw body.
     */
    private function template_update_payload(): string {
        return <<<'JSON'
        {
          "object": "whatsapp_business_account",
          "entry": [
            {
              "id": "104598152735435",
              "changes": [
                {
                  "value": {
                    "event": "APPROVED",
                    "message_template_id": 1234567890,
                    "message_template_name": "moodle_notification",
                    "message_template_language": "es_AR"
                  },
                  "field": "message_template_status_update"
                }
              ]
            }
          ]
        }
        JSON;
    }
}
