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
 * Tests for the gateway transport of the WhatsApp message processor.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp;

use core\http_client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use message_output_whatsapp;
use message_whatsapp\transport\changes;
use message_whatsapp\transport\gateway;
use message_whatsapp\transport\result;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/message/output/whatsapp/message_output_whatsapp.php');

/**
 * Tests for the transport that sends through the wa-gateway service.
 *
 * Nothing here reaches the network: every transport is built on the Guzzle `MockHandler`, through the `mock`
 * option of `\core\http_client`, so the requests this plugin would put on the wire are inspected instead of sent.
 *
 * Three things carry most of the weight, and they are the three differences with direct mode. The
 * **`Idempotency-Key` header**, which is the whole reason §3.7 grew a sixth parameter and the only thing standing
 * between a retried queue row and a person getting the same notification twice. The **`target_url`**, which has
 * to point at `go.php` of this site so that a click is recorded the same way in both modes. And the
 * **classification of the codes of §4.3**, which decides whether the queue writes a row off or spends four more
 * attempts on it.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \message_whatsapp\transport\gateway
 * @covers     \message_whatsapp\transport\changes
 * @covers     \message_whatsapp\transport\change
 */
final class gateway_test extends \advanced_testcase {
    /** Address of the service in these tests. */
    private const URL = 'https://wa.example.com';

    /** API key of the site in these tests. */
    private const APIKEY = 'clave-de-prueba-del-sitio';

    /** Click token as `queue::click_token()` would produce it. */
    private const TOKEN = '4711.0123456789abcdef0123456789abcdef';

    /** @var MockHandler The queue of answers the transport of the current test gets. */
    private MockHandler $mock;

    /**
     * Every test starts from a site with gateway mode fully configured and nothing left over.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('mode', message_output_whatsapp::MODE_GATEWAY, 'message_whatsapp');
        set_config('gatewayurl', self::URL, 'message_whatsapp');
        set_config('gatewayapikey', self::APIKEY, 'message_whatsapp');
    }

    /**
     * Builds a transport whose HTTP client answers with the given responses and nothing else.
     *
     * @param array $answers Responses or exceptions the mock handler returns, in order.
     * @return gateway A transport that reaches the mock instead of the service.
     */
    private function transport(array $answers): gateway {
        $this->mock = new MockHandler($answers);

        return gateway::with_client(new http_client(['mock' => $this->mock]));
    }

    /**
     * Builds one answer of the gateway.
     *
     * @param int $status HTTP status.
     * @param array $body Body to encode as JSON.
     * @return Response The answer.
     */
    private static function answer(int $status, array $body): Response {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * Sends one message through a transport that answers with the given responses.
     *
     * @param array $answers Responses the mock handler returns, in order.
     * @param string|null $urlsuffix Click token, or null for a message with no button.
     * @return result What the transport answered.
     */
    private function send(array $answers, ?string $urlsuffix = self::TOKEN): result {
        return $this->transport($answers)->send_template(
            '+5491122334455',
            'moodle_notification',
            'es_AR',
            ['Instituto Demo', 'Nota publicada', 'Álgebra I'],
            $urlsuffix,
            '4711'
        );
    }

    /**
     * Returns the body of the request the transport made, decoded.
     *
     * @return array The decoded body.
     */
    private function sent_body(): array {
        return json_decode((string) $this->mock->getLastRequest()->getBody(), true);
    }

    /**
     * A message goes out as the contract of §4.3 describes it, at the right address and with the right headers.
     *
     * @return void
     */
    public function test_send_template_posts_the_message_to_the_gateway(): void {
        $result = $this->send([self::answer(201, ['id' => 'e9b37316-5aea-4eed-9426-f98834fcdaa9', 'status' => 'queued'])]);

        $this->assertTrue($result->ok);
        $this->assertSame('e9b37316-5aea-4eed-9426-f98834fcdaa9', $result->providermsgid);

        $request = $this->mock->getLastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::URL . '/v1/messages', (string) $request->getUri());
        $this->assertSame('Bearer ' . self::APIKEY, $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = $this->sent_body();
        $this->assertSame('+5491122334455', $body['to']);
        $this->assertSame('moodle_notification', $body['template']);
        $this->assertSame('es_AR', $body['lang']);
        $this->assertSame(['Instituto Demo', 'Nota publicada', 'Álgebra I'], $body['params']);
    }

    /**
     * The idempotency key travels, which is the whole reason §3.7 grew a sixth parameter: without it a retried
     * queue row sends the same notification to the same person twice.
     *
     * @return void
     */
    public function test_send_template_sends_the_idempotency_key(): void {
        $this->send([self::answer(201, ['id' => 'abc', 'status' => 'queued'])]);

        $this->assertSame('4711', $this->mock->getLastRequest()->getHeaderLine('Idempotency-Key'));
    }

    /**
     * The button lands on `go.php` of this site and not on the address of the course, so that the click is
     * recorded in `message_whatsapp_click` the same way it is in direct mode.
     *
     * @return void
     */
    public function test_send_template_points_the_button_at_this_site(): void {
        global $CFG;

        $this->send([self::answer(201, ['id' => 'abc', 'status' => 'queued'])]);

        $body = $this->sent_body();
        $this->assertArrayNotHasKey('url_suffix', $body);
        $this->assertSame(
            $CFG->wwwroot . '/message/output/whatsapp/go.php?t=' . rawurlencode(self::TOKEN),
            $body['target_url']
        );
    }

    /**
     * A message with no button carries no target, so the gateway does not make a short link nobody will click.
     *
     * @return void
     */
    public function test_send_template_omits_the_target_when_there_is_no_button(): void {
        $this->send([self::answer(201, ['id' => 'abc', 'status' => 'queued'])], null);

        $this->assertArrayNotHasKey('target_url', $this->sent_body());
    }

    /**
     * The codes of §4.3, each on the side of the line the retry horizon puts it on.
     *
     * @dataProvider failure_provider
     * @param int $status HTTP status the gateway answered.
     * @param array $body Body it answered with.
     * @param string $expectedcode Code the result has to carry.
     * @param bool $retryable Whether the queue has to try again.
     * @return void
     */
    public function test_failures_are_classified(
        int $status,
        array $body,
        string $expectedcode,
        bool $retryable
    ): void {
        $result = $this->send([self::answer($status, $body)]);

        $this->assertFalse($result->ok);
        $this->assertSame($expectedcode, $result->code);
        $this->assertSame($retryable, $result->is_retryable());
        $this->assertNotSame('', $result->message);
    }

    /**
     * The cases of {@see test_failures_are_classified()}.
     *
     * @return array[] Status, body, expected code and whether it is retryable.
     */
    public static function failure_provider(): array {
        return [
            'clave que no es de nadie' => [
                401, ['code' => 'invalid_api_key', 'detail' => 'La key no corresponde a ningún tenant.'],
                'invalid_api_key', false,
            ],
            'suscripción suspendida' => [
                403, ['code' => 'tenant_suspended', 'detail' => 'El servicio está suspendido para este tenant.'],
                'tenant_suspended', false,
            ],
            'destino que no es E.164' => [
                422, ['code' => 'invalid_phone', 'detail' => 'El destino no es válido.'],
                'invalid_phone', false,
            ],
            'plantilla rechazada' => [
                422, ['code' => 'template_rejected', 'detail' => 'Meta rechazó la plantilla.'],
                'template_rejected', false,
            ],
            // El 429 que no se reintenta: la cuota mensual no se recarga dentro de la hora del backoff.
            'cuota del plan agotada' => [
                429, ['code' => 'quota_exceeded', 'detail' => 'El plan admite 1000 mensajes por mes.'],
                'quota_exceeded', false,
            ],
            'error de Meta que no es del cliente' => [
                502, ['code' => 'meta_error', 'detail' => 'Meta contestó mal.'],
                'meta_error', true,
            ],
            'el gateway no alcanza su base' => [
                503, ['code' => 'database_unavailable', 'detail' => 'No se pudo alcanzar la base.'],
                'database_unavailable', true,
            ],
            // Un código que no está en §4.3 llegó de una versión posterior, o de algo que no es el gateway.
            'código desconocido con 4xx' => [
                400, ['code' => 'codigo_nuevo', 'detail' => 'Algo nuevo.'], 'codigo_nuevo', false,
            ],
            'código desconocido con 5xx' => [
                500, ['code' => 'codigo_nuevo', 'detail' => 'Algo nuevo.'], 'codigo_nuevo', true,
            ],
        ];
    }

    /**
     * An error with no code at all is not an answer of the gateway: a proxy, a load balancer, a captive portal.
     *
     * @return void
     */
    public function test_an_answer_without_a_code_falls_back_to_the_status(): void {
        $result = $this->send([new Response(502, ['Content-Type' => 'text/html'], '<html>proxy</html>')]);

        $this->assertFalse($result->ok);
        $this->assertSame(gateway::CODE_INVALID_RESPONSE, $result->code);
        $this->assertTrue($result->is_retryable());
    }

    /**
     * An accepted message with no id is a shape the contract does not allow. Retried rather than written off:
     * the idempotency key makes a second attempt safe, which is exactly what it is for.
     *
     * @return void
     */
    public function test_an_acceptance_without_an_id_is_retried(): void {
        $result = $this->send([self::answer(201, ['status' => 'queued'])]);

        $this->assertFalse($result->ok);
        $this->assertSame(gateway::CODE_INVALID_RESPONSE, $result->code);
        $this->assertTrue($result->is_retryable());
    }

    /**
     * A request that never arrived is worth trying again, and the diagnostic does not quote the URL.
     *
     * @return void
     */
    public function test_a_network_failure_is_transient(): void {
        $result = $this->send([new ConnectException('Connection refused', new Request('POST', self::URL))]);

        $this->assertFalse($result->ok);
        $this->assertSame(gateway::CODE_NETWORK, $result->code);
        $this->assertTrue($result->is_retryable());
        $this->assertStringNotContainsString(self::URL, $result->message);
    }

    /**
     * Moodle blocking the address is not the gateway being down, and saying so is the difference between an
     * administrator checking a service that is running perfectly well and one opening the right settings page.
     *
     * @return void
     */
    public function test_an_address_moodle_refuses_to_call_says_so(): void {
        $result = $this->send([new RequestException('Esta URL está bloqueada.', new Request('POST', self::URL))]);

        $this->assertFalse($result->ok);
        $this->assertSame(gateway::CODE_BLOCKED, $result->code);
        // Permanente: un backoff no cambia un setting del sitio, lo cambia una persona.
        $this->assertFalse($result->is_retryable());
        $this->assertStringContainsString('HTTP security', $result->message);
    }

    /**
     * The diagnostic ends up in a column the administrator report prints on a page, so neither the key of the
     * site nor the number of a student may survive in it.
     *
     * @return void
     */
    public function test_the_diagnostic_carries_neither_the_key_nor_a_phone_number(): void {
        $result = $this->send([self::answer(403, [
            'code' => 'tenant_suspended',
            'detail' => 'La clave ' . self::APIKEY . ' no puede escribirle a +54 9 11 2233-4455.',
        ])]);

        $this->assertStringNotContainsString(self::APIKEY, $result->message);
        $this->assertStringNotContainsString('2233-4455', $result->message);
    }

    /**
     * Without an address or without a key nothing is attempted, and it is not retried: an empty setting is
     * filled when somebody opens the settings page, not while a backoff ticks.
     *
     * @dataProvider unconfigured_provider
     * @param string $url Value of the address setting.
     * @param string $apikey Value of the key setting.
     * @return void
     */
    public function test_without_configuration_nothing_is_attempted(string $url, string $apikey): void {
        set_config('gatewayurl', $url, 'message_whatsapp');
        set_config('gatewayapikey', $apikey, 'message_whatsapp');

        $result = $this->send([]);

        $this->assertFalse($result->ok);
        $this->assertSame(gateway::CODE_NOT_CONFIGURED, $result->code);
        $this->assertFalse($result->is_retryable());
        $this->assertNull($this->mock->getLastRequest());
    }

    /**
     * The cases of {@see test_without_configuration_nothing_is_attempted()}.
     *
     * @return array[] Address and key.
     */
    public static function unconfigured_provider(): array {
        return [
            'sin dirección' => ['', self::APIKEY],
            'sin clave' => [self::URL, ''],
            // La clave viaja en cada pedido a esa dirección: algo que no es una URL no se llama.
            'dirección que no es una URL' => ['wa.example.com', self::APIKEY],
            'dirección con otro esquema' => ['ftp://wa.example.com', self::APIKEY],
        ];
    }

    /**
     * The trailing slash of the address does not produce a double one in the path.
     *
     * @return void
     */
    public function test_a_trailing_slash_in_the_address_is_dropped(): void {
        set_config('gatewayurl', self::URL . '/', 'message_whatsapp');

        $this->send([self::answer(201, ['id' => 'abc', 'status' => 'queued'])]);

        $this->assertSame(self::URL . '/v1/messages', (string) $this->mock->getLastRequest()->getUri());
    }

    /**
     * The test connection button asks the service who the key belongs to, and sends nothing.
     *
     * @return void
     */
    public function test_check_validates_the_key(): void {
        $transport = $this->transport([self::answer(200, [
            'tenant' => 'Instituto Demo',
            'mode' => 'shared',
            'plan' => 'free',
            'can_send' => true,
        ])]);

        $result = $transport->check();

        $this->assertTrue($result->ok);
        $this->assertSame(self::URL . '/v1/check', (string) $this->mock->getLastRequest()->getUri());
        $this->assertSame('POST', $this->mock->getLastRequest()->getMethod());
    }

    /**
     * A key that belongs to nobody is what the administrator most often comes to this button to find out.
     *
     * @return void
     */
    public function test_check_reports_a_key_that_belongs_to_nobody(): void {
        $transport = $this->transport([self::answer(401, [
            'code' => 'invalid_api_key',
            'detail' => 'La key no corresponde a ningún tenant.',
        ])]);

        $result = $transport->check();

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_api_key', $result->code);
    }

    /**
     * The cursor answers a page of changes, read into what the queue needs and nothing else.
     *
     * The assertion that matters is which id comes back. `id` is what the gateway assigned when it accepted the
     * message and what {@see gateway::send_template()} stored in `providermsgid` of the queue row;
     * `provider_msgid` is what Meta assigned the gateway, which this site has never seen. Reading the second one
     * would leave every row in `sent` for ever, and nothing would say why.
     *
     * @return void
     */
    public function test_changes_since_reads_a_page(): void {
        $transport = $this->transport([self::answer(200, [
            'messages' => [
                [
                    'id' => 'e9b37316-5aea-4eed-9426-f98834fcdaa9',
                    'status' => 'delivered',
                    'provider_msgid' => 'wamid.DE-META',
                    'error' => null,
                    'pricing_category' => 'utility',
                    'billable' => true,
                    'attempts' => 1,
                    'sent_at' => '2026-09-13T14:35:21.254795Z',
                    'status_at' => '2026-09-13T14:36:00Z',
                    'cursor' => 'CURSOR-1',
                ],
                [
                    'id' => 'fbd4eccb-eb67-4839-9b43-ae873392b812',
                    'status' => 'failed',
                    'provider_msgid' => null,
                    'error' => '131026 Message undeliverable',
                    'pricing_category' => null,
                    'billable' => false,
                    'attempts' => 1,
                    'sent_at' => null,
                    'status_at' => '2026-09-13T14:37:00Z',
                    'cursor' => 'CURSOR-2',
                ],
            ],
            'cursor' => 'CURSOR-2',
            'has_more' => true,
        ])]);

        $page = $transport->changes_since('CURSOR-0', 500);

        $this->assertInstanceOf(changes::class, $page);
        $this->assertSame('CURSOR-2', $page->cursor);
        $this->assertTrue($page->hasmore);
        $this->assertCount(2, $page->items);

        // El id del gateway, no el de Meta.
        $this->assertSame('e9b37316-5aea-4eed-9426-f98834fcdaa9', $page->items[0]->providermsgid);
        $this->assertSame('delivered', $page->items[0]->status);
        $this->assertSame('utility', $page->items[0]->pricingcategory);
        $this->assertNull($page->items[0]->error);
        $this->assertSame(strtotime('2026-09-13T14:36:00Z'), $page->items[0]->timestatus);

        // Una fila que falló antes de salir no tiene id de Meta, y se lee igual: lo que la identifica para
        // este sitio es el id del gateway, que existe desde que el mensaje fue aceptado.
        $this->assertSame('fbd4eccb-eb67-4839-9b43-ae873392b812', $page->items[1]->providermsgid);
        $this->assertSame('failed', $page->items[1]->status);
        $this->assertStringContainsString('131026', (string) $page->items[1]->error);

        $request = $this->mock->getLastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('since=CURSOR-0', (string) $request->getUri());
        $this->assertStringContainsString('limit=500', (string) $request->getUri());
    }

    /**
     * An entry with no id cannot move any row, and is dropped rather than guessed at.
     *
     * @return void
     */
    public function test_an_entry_without_an_id_is_dropped(): void {
        $transport = $this->transport([self::answer(200, [
            'messages' => [
                ['status' => 'delivered', 'cursor' => 'CURSOR-1'],
                ['id' => 'abc', 'cursor' => 'CURSOR-2'],
            ],
            'cursor' => 'CURSOR-2',
        ])]);

        $page = $transport->changes_since('', 500);

        $this->assertInstanceOf(changes::class, $page);
        $this->assertCount(0, $page->items);
        $this->assertSame('CURSOR-2', $page->cursor);
    }

    /**
     * An answer with no cursor is not a page: storing the old one would make the next run ask for the same
     * thing again, for ever.
     *
     * @return void
     */
    public function test_a_page_without_a_cursor_is_refused(): void {
        $transport = $this->transport([self::answer(200, ['messages' => []])]);

        $answer = $transport->changes_since('', 500);

        $this->assertInstanceOf(result::class, $answer);
        $this->assertTrue($answer->is_retryable());
    }

    /**
     * The transport reports its own name, which is what a log line and the report show.
     *
     * @return void
     */
    public function test_name(): void {
        $this->assertSame('gateway', (new gateway())->name());
    }
}
