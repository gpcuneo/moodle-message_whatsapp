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
 * Tests for the Meta Cloud API transport of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use core\http_client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use message_output_whatsapp;
use message_whatsapp\transport\factory;
use message_whatsapp\transport\meta_cloud;
use message_whatsapp\transport\result;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');

/**
 * Tests for the transport that talks to the Meta Cloud API directly.
 *
 * Nothing here reaches the network. Every test builds the transport on the Guzzle `MockHandler`, through the
 * `mock` option of `\core\http_client` that core itself uses in `lib/tests/http_client_test.php`, so the requests
 * this plugin would put on the wire are inspected instead of sent.
 *
 * Two groups of tests carry most of the weight. One checks the **exact body** of the request, byte for byte and
 * key for key, because a template message that is merely accepted by `json_encode()` is not the same thing as a
 * template message Meta will deliver. The other pins the **error table**, code by code: the difference between a
 * permanent and a transient failure is what makes the queue write a row off or spend four more attempts on it, and
 * it is invisible in review.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\transport\meta_cloud
 */
final class meta_cloud_test extends \advanced_testcase {
    /** Access token the tests configure. Invented, and never sent anywhere: the handler is a mock. */
    private const TOKEN = 'test-access-token-not-a-real-one';

    /** Business phone number id the tests configure. */
    private const PHONEID = '109876543210987';

    /** Phone number the tests send to. A documentation number, not anybody's. */
    private const PHONE = '+5491123456789';

    /** @var MockHandler The queue of answers the transport of the current test gets. */
    private MockHandler $mock;

    /**
     * Every test starts from a site with direct mode fully configured and nothing left over.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('mode', message_output_whatsapp::MODE_DIRECT, 'message_whatsapp');
        set_config('metatoken', self::TOKEN, 'message_whatsapp');
        set_config('metaphoneid', self::PHONEID, 'message_whatsapp');
    }

    /**
     * Builds a transport whose HTTP client answers with the given responses and nothing else.
     *
     * @param array $answers Responses or exceptions the mock handler returns, in order.
     * @return meta_cloud A transport that reaches the mock instead of Meta.
     */
    private function transport(array $answers): meta_cloud {
        $this->mock = new MockHandler($answers);

        return meta_cloud::with_client(new http_client(['mock' => $this->mock]));
    }

    /**
     * Builds one answer of the Cloud API.
     *
     * @param int $status HTTP status.
     * @param array $body Body to encode as JSON.
     * @return Response The answer.
     */
    private static function answer(int $status, array $body): Response {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * Builds the answer the Cloud API gives when a message was accepted.
     *
     * @param string $wamid Message id to return.
     * @return Response The answer.
     */
    private static function accepted(string $wamid = 'wamid.HBgNNTQ5MTEyMzQ1Njc4ORUCABEYEjhDN0E='): Response {
        return self::answer(200, [
            'messaging_product' => 'whatsapp',
            'contacts' => [['input' => self::PHONE, 'wa_id' => '5491123456789']],
            'messages' => [['id' => $wamid, 'message_status' => 'accepted']],
        ]);
    }

    /**
     * Builds the answer the Cloud API gives when it refuses a request.
     *
     * @param int $status HTTP status.
     * @param int|null $code Numeric error code, or null for an error object without one.
     * @param string $message Error message.
     * @param string|null $details Value of `error_data.details`, when there is one.
     * @return Response The answer.
     */
    private static function refusal(
        int $status,
        ?int $code,
        string $message = 'Something went wrong',
        ?string $details = null
    ): Response {
        $error = [
            'message' => $message,
            'type' => 'OAuthException',
            'fbtrace_id' => 'AbCdEfGhIjK',
        ];

        if ($code !== null) {
            $error['code'] = $code;
        }

        if ($details !== null) {
            $error['error_data'] = ['messaging_product' => 'whatsapp', 'details' => $details];
        }

        return self::answer($status, ['error' => $error]);
    }

    /**
     * Sends the template of §3.5 through the given transport with the standard arguments of these tests.
     *
     * @param meta_cloud $transport Transport to send through.
     * @param string|null $urlsuffix Suffix of the URL button.
     * @param string $idempotencykey Key the transport is expected to ignore.
     * @return result Whatever the transport answered.
     */
    private function send(meta_cloud $transport, ?string $urlsuffix = 'abc123', string $idempotencykey = '42'): result {
        return $transport->send_template(
            self::PHONE,
            'moodle_notification',
            'es_AR',
            ['Campus Demo', 'Nota publicada', 'Física & Química: 8 (ocho)'],
            $urlsuffix,
            $idempotencykey
        );
    }

    /**
     * Returns the body of the last request the mock handler received, decoded.
     *
     * @return array The decoded request body.
     */
    private function last_body(): array {
        return json_decode((string) $this->mock->getLastRequest()->getBody(), true);
    }

    /**
     * The machine name is the one the factory registry and the logs use.
     *
     * @return void
     */
    public function test_the_name_is_the_stable_machine_name(): void {
        $this->assertSame('meta_cloud', $this->transport([])->name());
        $this->assertSame(meta_cloud::NAME, $this->transport([])->name());
    }

    /**
     * The factory of T2.1 builds this transport now that the file exists.
     *
     * The factory test pins the class name; this pins that the class name resolves to something instantiable, which
     * is the half of that contract only this task can close.
     *
     * @return void
     */
    public function test_the_factory_builds_this_transport_in_direct_mode(): void {
        $this->assertInstanceOf(meta_cloud::class, factory::instance());
        $this->assertSame(meta_cloud::NAME, factory::instance()->name());
    }

    /**
     * The request is exactly the template message the Cloud API documents, and nothing else.
     *
     * This is the test the task exists for: method, URL, authentication, content type and every key of the body.
     * A template that is merely valid JSON is not a template Meta delivers.
     *
     * @return void
     */
    public function test_the_request_is_exactly_the_template_message_meta_expects(): void {
        $transport = $this->transport([self::accepted()]);

        $this->send($transport);

        $request = $this->mock->getLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            'https://graph.facebook.com/' . meta_cloud::DEFAULT_GRAPH_VERSION . '/' . self::PHONEID . '/messages',
            (string) $request->getUri()
        );
        $this->assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));

        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => self::PHONE,
            'type' => 'template',
            'template' => [
                'name' => 'moodle_notification',
                'language' => ['code' => 'es_AR'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => 'Campus Demo'],
                            ['type' => 'text', 'text' => 'Nota publicada'],
                            ['type' => 'text', 'text' => 'Física & Química: 8 (ocho)'],
                        ],
                    ],
                    [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => '0',
                        'parameters' => [['type' => 'text', 'text' => 'abc123']],
                    ],
                ],
            ],
        ], $this->last_body());
    }

    /**
     * A template with no button sends no button component, rather than an empty one.
     *
     * @return void
     */
    public function test_a_template_without_a_button_sends_no_button_component(): void {
        $transport = $this->transport([self::accepted()]);

        $this->send($transport, null);

        $components = $this->last_body()['template']['components'];
        $this->assertCount(1, $components);
        $this->assertSame('body', $components[0]['type']);
    }

    /**
     * A template with no parameters sends no body component.
     *
     * @return void
     */
    public function test_a_template_without_parameters_sends_no_body_component(): void {
        $transport = $this->transport([self::accepted()]);

        $transport->send_template(self::PHONE, 'moodle_notification', 'en', [], null, '7');

        $this->assertSame([], $this->last_body()['template']['components']);
    }

    /**
     * The parameters travel as they were given, with their accents readable on the wire.
     *
     * Same flags as `mapped::params_json()`, so what the queue stored and what went to Meta can be compared by eye.
     *
     * @return void
     */
    public function test_the_parameters_travel_unescaped_and_untouched(): void {
        $transport = $this->transport([self::accepted()]);

        $this->send($transport, 'a/b');

        $raw = (string) $this->mock->getLastRequest()->getBody();
        $this->assertStringContainsString('Física & Química: 8 (ocho)', $raw);
        $this->assertStringContainsString('"text":"a/b"', $raw);
        $this->assertStringNotContainsString('\\u00ed', $raw);
    }

    /**
     * The language sent is the one given, because a template is approved per language.
     *
     * @return void
     */
    public function test_the_language_of_the_queue_row_is_the_one_sent(): void {
        $transport = $this->transport([self::accepted()]);

        $transport->send_template(self::PHONE, 'moodle_notification', 'en', ['a'], null, '1');

        $this->assertSame(['code' => 'en'], $this->last_body()['template']['language']);
    }

    /**
     * A message that was accepted comes back as a success carrying the message id of Meta.
     *
     * @return void
     */
    public function test_an_accepted_message_returns_the_provider_message_id(): void {
        $transport = $this->transport([self::accepted('wamid.TESTID')]);

        $result = $this->send($transport);

        $this->assertTrue($result->ok);
        $this->assertSame('wamid.TESTID', $result->providermsgid);
        $this->assertFalse($result->permanent);
        $this->assertFalse($result->is_retryable());
        $this->assertSame('', $result->code);
    }

    /**
     * The idempotency key is ignored, deliberately and completely.
     *
     * The Cloud API has no idempotency header to put it in, and in direct mode the protection against a duplicate
     * is the lock and the conditional update of the queue. Two sends that differ only in the key produce the same
     * bytes and no extra header, which is what "ignored on purpose" has to mean to be checkable.
     *
     * @return void
     */
    public function test_the_idempotency_key_is_ignored(): void {
        $transport = $this->transport([self::accepted(), self::accepted()]);

        $this->send($transport, 'abc123', 'queue-row-1');
        $first = (string) $this->mock->getLastRequest()->getBody();
        $headers = $this->mock->getLastRequest()->getHeaders();

        $this->send($transport, 'abc123', 'a-completely-different-key');
        $second = (string) $this->mock->getLastRequest()->getBody();

        $this->assertSame($first, $second);
        $this->assertArrayNotHasKey('Idempotency-Key', $headers);
        $this->assertStringNotContainsString('queue-row-1', $first);
    }

    /**
     * The Graph version of the setting is the one called.
     *
     * @return void
     */
    public function test_the_configured_graph_version_is_the_one_called(): void {
        set_config('graphversion', 'v19.0', 'message_whatsapp');
        $transport = $this->transport([self::accepted()]);

        $this->send($transport);

        $this->assertStringStartsWith(
            'https://graph.facebook.com/v19.0/' . self::PHONEID,
            (string) $this->mock->getLastRequest()->getUri()
        );
    }

    /**
     * A version setting that is not a version is ignored rather than pasted into the URL.
     *
     * The setting ends up in a path, and a hand edited one could otherwise send the bearer token of the site to
     * another endpoint.
     *
     * @param string $version Value stored in the setting.
     * @return void
     * @dataProvider bad_version_provider
     */
    public function test_a_version_that_is_not_a_version_falls_back_to_the_default(string $version): void {
        set_config('graphversion', $version, 'message_whatsapp');
        $transport = $this->transport([self::accepted()]);

        $this->send($transport);

        $this->assertSame(
            'https://graph.facebook.com/' . meta_cloud::DEFAULT_GRAPH_VERSION . '/' . self::PHONEID . '/messages',
            (string) $this->mock->getLastRequest()->getUri()
        );
    }

    /**
     * Version settings that must never reach the URL.
     *
     * @return array[] Each case is [value of the setting].
     */
    public static function bad_version_provider(): array {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'no v' => ['25.0'],
            'no minor' => ['v25'],
            'traversal' => ['../../oauth'],
            'another host' => ['v25.0/../../../evil.example.com'],
            'query string' => ['v25.0?access_token=x'],
        ];
    }

    /**
     * A throughput limit is transient: the queue backs off and tries again.
     *
     * @return void
     */
    public function test_a_throughput_limit_is_transient(): void {
        $transport = $this->transport([self::refusal(429, 130429, '(#130429) Rate limit hit')]);

        $result = $this->send($transport);

        $this->assertFalse($result->ok);
        $this->assertFalse($result->permanent);
        $this->assertTrue($result->is_retryable());
        $this->assertSame('meta_130429', $result->code);
        $this->assertStringContainsString('130429', $result->message);
    }

    /**
     * An unknown internal error of Meta is transient: their own advice is to retry it.
     *
     * @return void
     */
    public function test_a_server_error_is_transient(): void {
        $transport = $this->transport([self::refusal(500, 131000, 'Something went wrong')]);

        $result = $this->send($transport);

        $this->assertTrue($result->is_retryable());
        $this->assertSame('meta_131000', $result->code);
    }

    /**
     * A number that is not reachable on WhatsApp is permanent, and carries the code §3.4 branches on.
     *
     * @return void
     */
    public function test_an_undeliverable_number_is_permanent(): void {
        $transport = $this->transport([
            self::refusal(400, 131026, 'Message undeliverable', 'Receiver is incapable of receiving this message'),
        ]);

        $result = $this->send($transport);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->permanent);
        $this->assertFalse($result->is_retryable());
        $this->assertSame(meta_cloud::CODE_UNDELIVERABLE, $result->code);
        $this->assertSame('meta_131026', $result->code);
    }

    /**
     * A template that does not exist in that language is permanent: approval is per language.
     *
     * @return void
     */
    public function test_a_template_that_does_not_exist_is_permanent(): void {
        $transport = $this->transport([
            self::refusal(400, 132001, 'Template name does not exist in the translation'),
        ]);

        $result = $this->send($transport);

        $this->assertTrue($result->permanent);
        $this->assertSame('meta_132001', $result->code);
    }

    /**
     * A parameter count that does not match the template is permanent: the mismatch is structural.
     *
     * @return void
     */
    public function test_a_parameter_count_mismatch_is_permanent(): void {
        $transport = $this->transport([
            self::refusal(400, 132000, 'Number of parameters does not match the expected number of params'),
        ]);

        $result = $this->send($transport);

        $this->assertTrue($result->permanent);
        $this->assertSame('meta_132000', $result->code);
    }

    /**
     * Every code of the table is classified by the table, and not by the HTTP status it arrived with.
     *
     * The status of each case is deliberately the opposite of what the classification says: the permanent codes
     * arrive as a 500, which the fallback would call transient, and the transient ones as a 400, which the fallback
     * would call permanent. A table that stopped being consulted would fail every one of these at once.
     *
     * @param int $code Numeric error code of the Cloud API.
     * @param bool $permanent Whether the queue must write the row off instead of retrying it.
     * @return void
     * @dataProvider classification_provider
     */
    public function test_every_documented_code_is_classified_by_the_table(int $code, bool $permanent): void {
        $transport = $this->transport([self::refusal($permanent ? 500 : 400, $code)]);

        $result = $this->send($transport);

        $this->assertFalse($result->ok);
        $this->assertSame($permanent, $result->permanent, 'Wrong classification for Cloud API code ' . $code);
        $this->assertSame($permanent, !$result->is_retryable());
        $this->assertSame('meta_' . $code, $result->code);
    }

    /**
     * The whole error table, code by code, as the class documents it.
     *
     * A second copy of the table on purpose. It has to agree with the one in `meta_cloud.php`, so moving a code
     * from one list to the other is a deliberate edit in two files and never an accident in one.
     *
     * @return array[] Each case is [code, whether it is permanent].
     */
    public static function classification_provider(): array {
        $permanent = [
            0, 3, 10, 33, 100, 190, 200, 368, 130472, 131005, 131008, 131009, 131021, 131026, 131031, 131037,
            131042, 131045, 131047, 131049, 131051, 131053, 132000, 132001, 132005, 132007, 132012, 132015,
            132016, 133010, 133015, 135000,
        ];
        $transient = [1, 2, 4, 80007, 130429, 131000, 131016, 131048, 131056, 131057, 133004];

        $cases = [];
        foreach ($permanent as $code) {
            $cases['permanent ' . $code] = [$code, true];
        }
        foreach ($transient as $code) {
            $cases['transient ' . $code] = [$code, false];
        }

        return $cases;
    }

    /**
     * A code that is not on either table is classified by the HTTP status it arrived with.
     *
     * Meta adds error codes, and an unknown one must not become a wrong certainty in either direction.
     *
     * @param int $status HTTP status of the answer.
     * @param bool $permanent Whether the failure must be permanent.
     * @return void
     * @dataProvider unknown_code_provider
     */
    public function test_an_unknown_code_is_classified_by_its_http_status(int $status, bool $permanent): void {
        $transport = $this->transport([self::refusal($status, 999999, 'A code from the future')]);

        $result = $this->send($transport);

        $this->assertSame($permanent, $result->permanent);
        $this->assertSame('meta_999999', $result->code);
    }

    /**
     * Statuses an unknown error code can arrive with.
     *
     * @return array[] Each case is [status, whether it is permanent].
     */
    public static function unknown_code_provider(): array {
        return [
            'bad request' => [400, true],
            'unauthorised' => [401, true],
            'forbidden' => [403, true],
            'not found' => [404, true],
            'timeout' => [408, false],
            'too many requests' => [429, false],
            'server error' => [500, false],
            'bad gateway' => [502, false],
            'unavailable' => [503, false],
        ];
    }

    /**
     * An HTTP error with no error object at all is still classified, and still named.
     *
     * @return void
     */
    public function test_an_http_error_without_an_error_object_is_classified_by_status(): void {
        $transport = $this->transport([self::answer(503, ['nothing' => 'useful'])]);
        $first = $this->send($transport);
        $this->assertTrue($first->is_retryable());
        $this->assertSame('http_error', $first->code);

        $transport = $this->transport([self::answer(403, ['nothing' => 'useful'])]);
        $second = $this->send($transport);
        $this->assertTrue($second->permanent);
        $this->assertSame('http_error', $second->code);
    }

    /**
     * An answer that is not JSON is a transient failure and never an exception.
     *
     * A corporate proxy that answers an HTML error page is the usual way this happens.
     *
     * @return void
     */
    public function test_a_body_that_is_not_json_is_a_transient_failure(): void {
        $transport = $this->transport([new Response(200, ['Content-Type' => 'text/html'], '<html>Proxy error</html>')]);

        $result = $this->send($transport);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->is_retryable());
        $this->assertSame('invalid_response', $result->code);
    }

    /**
     * A success with no message id is retried rather than written off.
     *
     * Without the id the delivery report can never be matched to the row, so the send did not really succeed. The
     * plugin prefers a possible duplicate to a lost notification, the same choice T1.5 made for orphan rows.
     *
     * @return void
     */
    public function test_a_success_without_a_message_id_is_a_transient_failure(): void {
        $transport = $this->transport([self::answer(200, ['messaging_product' => 'whatsapp', 'messages' => []])]);

        $result = $this->send($transport);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->is_retryable());
        $this->assertSame('invalid_response', $result->code);
    }

    /**
     * A network failure comes back as a transient result and never as an exception.
     *
     * @return void
     */
    public function test_a_network_failure_is_transient_and_never_throws(): void {
        $transport = $this->transport([
            new ConnectException('cURL error 6: Could not resolve host', new Request('POST', 'https://graph.facebook.com')),
        ]);

        $result = $this->send($transport);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->is_retryable());
        $this->assertSame('network_error', $result->code);
        $this->assertStringContainsString('ConnectException', $result->message);
    }

    /**
     * Without credentials the transport refuses permanently and does not touch the network.
     *
     * The untouched answer left in the mock is the assertion: a transport that had made the request would have
     * consumed it.
     *
     * @param string $setting Setting to empty.
     * @return void
     * @dataProvider missing_credential_provider
     */
    public function test_missing_credentials_refuse_permanently_without_a_request(string $setting): void {
        set_config($setting, '', 'message_whatsapp');
        $transport = $this->transport([self::accepted()]);

        $result = $this->send($transport);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->permanent);
        $this->assertSame(factory::CODE_NOT_CONFIGURED, $result->code);
        $this->assertCount(1, $this->mock);
    }

    /**
     * The credentials sending cannot do without.
     *
     * @return array[] Each case is [name of the setting].
     */
    public static function missing_credential_provider(): array {
        return [
            'no token' => ['metatoken'],
            'no phone number id' => ['metaphoneid'],
        ];
    }

    /**
     * The check refuses without credentials too, and also without making a request.
     *
     * @return void
     */
    public function test_the_check_refuses_without_credentials(): void {
        set_config('metatoken', '', 'message_whatsapp');
        $transport = $this->transport([self::accepted()]);

        $result = $transport->check();

        $this->assertTrue($result->permanent);
        $this->assertSame(factory::CODE_NOT_CONFIGURED, $result->code);
        $this->assertCount(1, $this->mock);
    }

    /**
     * The access token never appears in what the plugin stores, whatever Meta echoes back.
     *
     * @return void
     */
    public function test_the_access_token_never_reaches_the_diagnostic(): void {
        $transport = $this->transport([
            self::refusal(401, 190, 'Invalid OAuth access token ' . self::TOKEN . ' for this application'),
        ]);

        $result = $this->send($transport);

        $this->assertStringNotContainsString(self::TOKEN, $result->message);
        $this->assertStringContainsString('[token]', $result->message);
        $this->assertTrue($result->permanent);
    }

    /**
     * The phone number never appears in what the plugin stores, whatever Meta echoes back.
     *
     * The error column of the queue is printed in the administrator report next to the user it belongs to, which is
     * where the number already is. A copy of it in free text is personal data with no purpose.
     *
     * @return void
     */
    public function test_the_phone_number_never_reaches_the_diagnostic(): void {
        $transport = $this->transport([
            self::refusal(400, 131026, 'Recipient 5491123456789 is not a valid WhatsApp user'),
        ]);

        $result = $this->send($transport);

        $this->assertStringNotContainsString('5491123456789', $result->message);
        $this->assertStringContainsString('[number]', $result->message);
        $this->assertStringContainsString('131026', $result->message);
    }

    /**
     * The diagnostic prefers the `details` of Meta, which says more than the generic message.
     *
     * @return void
     */
    public function test_the_diagnostic_prefers_the_details_of_meta(): void {
        $transport = $this->transport([
            self::refusal(400, 132012, 'Parameter format mismatch', 'The parameter has a new line character'),
        ]);

        $result = $this->send($transport);

        $this->assertStringContainsString('The parameter has a new line character', $result->message);
        $this->assertStringNotContainsString('Parameter format mismatch', $result->message);
    }

    /**
     * A provider that answers with a wall of text does not get to fill the error column with it.
     *
     * @return void
     */
    public function test_the_diagnostic_is_capped(): void {
        $transport = $this->transport([self::refusal(400, 100, str_repeat('x', 5000))]);

        $result = $this->send($transport);

        $this->assertLessThanOrEqual(400, \core_text::strlen($result->message));
    }

    /**
     * The connection test asks for the business phone number object and nothing else.
     *
     * @return void
     */
    public function test_the_check_asks_for_the_business_phone_number(): void {
        $transport = $this->transport([
            self::answer(200, [
                'id' => self::PHONEID,
                'display_phone_number' => '+54 9 11 2345-6789',
                'verified_name' => 'Campus Demo',
                'quality_rating' => 'GREEN',
            ]),
        ]);

        $result = $transport->check();

        $request = $this->mock->getLastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(
            'https://graph.facebook.com/' . meta_cloud::DEFAULT_GRAPH_VERSION . '/' . self::PHONEID,
            (string) $request->getUri()
        );
        $this->assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        $this->assertSame('', (string) $request->getBody());

        $this->assertTrue($result->ok);
        $this->assertNull($result->providermsgid);
    }

    /**
     * An expired token makes the check fail permanently, which is what the administrator has to be told.
     *
     * @return void
     */
    public function test_the_check_reports_an_expired_token_as_permanent(): void {
        $transport = $this->transport([self::refusal(401, 190, 'Error validating access token: Session has expired')]);

        $result = $transport->check();

        $this->assertFalse($result->ok);
        $this->assertTrue($result->permanent);
        $this->assertSame('meta_190', $result->code);
    }

    /**
     * A phone number id that does not exist makes the check fail permanently.
     *
     * @return void
     */
    public function test_the_check_reports_an_unknown_phone_number_id_as_permanent(): void {
        $transport = $this->transport([self::refusal(400, 33, 'Unsupported get request. Object does not exist')]);

        $result = $transport->check();

        $this->assertTrue($result->permanent);
        $this->assertSame('meta_33', $result->code);
    }

    /**
     * A bad moment at Meta makes the check fail transiently: the credentials may be perfectly good.
     *
     * @return void
     */
    public function test_the_check_reports_a_server_error_as_transient(): void {
        $transport = $this->transport([self::refusal(500, 2, 'Service temporarily unavailable')]);

        $result = $transport->check();

        $this->assertTrue($result->is_retryable());
        $this->assertSame('meta_2', $result->code);
    }

    /**
     * A check that comes back without the phone number object is not a passed check.
     *
     * @return void
     */
    public function test_the_check_rejects_an_answer_that_is_not_the_phone_number(): void {
        $transport = $this->transport([self::answer(200, ['data' => []])]);

        $result = $transport->check();

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_response', $result->code);
    }

    /**
     * A network failure during the check is a result too, not an exception on an administration page.
     *
     * @return void
     */
    public function test_the_check_never_throws_on_a_network_failure(): void {
        $transport = $this->transport([
            new ConnectException('Connection timed out', new Request('GET', 'https://graph.facebook.com')),
        ]);

        $result = $transport->check();

        $this->assertFalse($result->ok);
        $this->assertTrue($result->is_retryable());
        $this->assertSame('network_error', $result->code);
    }
}
