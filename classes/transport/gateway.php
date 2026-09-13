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
 * Transport that sends WhatsApp templates through the wa-gateway service.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\transport;

use core\http_client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * The gateway mode: a subscribed service sends on behalf of the site, with an API key instead of Meta credentials.
 *
 * It is the other half of {@see meta_cloud}, and it exists because most sites do not want to create a WhatsApp
 * Business account, get a template approved and keep a token alive. Here the site holds two settings -- the
 * address of the service and one API key -- and the service holds the WhatsApp account, the approved templates
 * and the delivery reports.
 *
 * **The differences with direct mode are three, and they are all in this file.**
 *
 * 1. **The idempotency key is used.** The gateway accepts `Idempotency-Key` and answers the same message id for
 *    the same key for ever, so a row that is retried after the process died between the send and the write of
 *    the answer does not reach anybody twice. The Cloud API has no such header, which is why `meta_cloud`
 *    ignores the parameter and this class does not. It is the whole reason §3.7 gained the sixth parameter.
 * 2. **The button travels as a URL and not as a suffix.** Direct mode hands Meta the suffix of a template whose
 *    base URL was frozen at approval time, and that base is `go.php` of this site. Here the approved template
 *    belongs to the service, so its base is the service's shortener and the suffix is a code the service
 *    generates. What this transport sends is therefore `target_url`, and what it sends as the target is **the
 *    `go.php` of this site**, not the address of the course: that keeps the click landing on the same endpoint
 *    in both modes, so `message_whatsapp_click` is filled in the same way and the report does not have a hole
 *    in one of the two modes.
 * 3. **Errors arrive already named.** The gateway answers `{"code","detail"}` with a closed table of codes, so
 *    there is no numeric table to keep here: what this class decides is which of those codes is worth trying
 *    again, and the test is the same as in T2.2 -- not whether the condition could ever change, but whether it
 *    can change inside the retry horizon of the queue, five attempts over about an hour.
 *
 * Like every transport, **it never throws**: a refused connection, a service that answers HTML and a bug in this
 * file all come back as a {@see result}.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class gateway implements transport_interface {
    /** Machine name reported by {@see self::name()}. Stable: log lines and tests compare it. */
    public const NAME = 'gateway';

    /** Failure code used when the request never reached the gateway: refused connection, DNS, timeout, TLS. */
    public const CODE_NETWORK = 'network_error';

    /** Failure code used when the gateway answered something that is not the JSON of its own contract. */
    public const CODE_INVALID_RESPONSE = 'invalid_response';

    /** Failure code used when something inside this plugin threw while building or reading the request. */
    public const CODE_INTERNAL = 'internal_error';

    /**
     * Failure code used when Moodle itself refused to make the request.
     *
     * The HTTP client of core runs every request past `\core\files\curl_security_helper`, which blocks the
     * loopback address and the private ranges by default. That is the right default -- it is what stops a plugin
     * from being turned into a way of reaching the inside of the network the site runs on -- and it means that a
     * gateway hosted on the same private network as the Moodle is blocked until an administrator allows it.
     *
     * It has a code of its own because the failure it names is not the one it looks like. Without it the answer
     * is "the gateway could not be reached", which sends whoever reads it to check a service that is running
     * perfectly well, while the setting that has to change is in Moodle.
     */
    public const CODE_BLOCKED = 'blocked_by_site';

    /** Failure code used when the site has not finished configuring gateway mode. */
    public const CODE_NOT_CONFIGURED = factory::CODE_NOT_CONFIGURED;

    /** Path of the endpoint that enqueues a message. */
    private const PATH_MESSAGES = '/v1/messages';

    /** Path of the endpoint that validates the API key without sending anything. */
    private const PATH_CHECK = '/v1/check';

    /** Path of the endpoint that reports status changes since a cursor. */
    public const PATH_CHANGES = '/v1/messages';

    /** Seconds to wait for the connection itself. Short: an unreachable host must not hold up the queue. */
    private const CONNECT_TIMEOUT = 10;

    /** Seconds to wait for the whole request. The sending task runs every minute and processes a batch. */
    private const TIMEOUT = 30;

    /** Longest diagnostic kept from an answer. The queue truncates too; this keeps a blob out of memory. */
    private const MAX_DIAGNOSTIC = 400;

    /**
     * Codes of the gateway that must never be retried, and why each one is here.
     *
     * The table is `docs/arquitectura.md` §4.3 **[D]**, read with the question of T2.2: can this change inside
     * the five attempts of about an hour that the queue is willing to spend.
     *
     * @var string[]
     */
    private const PERMANENT_CODES = [
        // The header is missing or malformed. This plugin builds it, so it is identical on the next attempt.
        'unauthorized',
        // The key belongs to no tenant: it was revoked, or mistyped into the settings. A person has to fix it.
        'invalid_api_key',
        // The subscription is suspended. It is an invoice, and a backoff does not pay one.
        'tenant_suspended',
        // The body failed validation. Ours to fix, and byte for byte the same next time.
        'invalid_request',
        // The destination is not E.164. The number is in the profile of the user and does not change by waiting.
        'invalid_phone',
        // The service rejected the template or its parameters: structural, exactly as 132001 is in direct mode.
        'template_rejected',
        // The monthly quota of the plan ran out. It is filed here **knowingly**, and it is the closest call on
        // this list: a 429 is transient everywhere else. But this one refills on the first of the month, not
        // inside the hour of the backoff, so five attempts would only reach the same sentence with the cause
        // buried under four repeats. The report says so, and its retry action is what puts the rows back once
        // the plan is raised.
        'quota_exceeded',
        // A resource that does not exist for this tenant, or belongs to another one. Neither changes by waiting.
        'not_found',
        'forbidden',
    ];

    /**
     * Codes of the gateway that are worth trying again.
     *
     * @var string[]
     */
    private const TRANSIENT_CODES = [
        // Meta failed in a way that is not the client's fault. The gateway has already decided that much.
        'meta_error',
        // The gateway could not reach its own database. Minutes, and nothing this site can do about it.
        'database_unavailable',
    ];

    /**
     * The HTTP client to use, or null to build the default one on first use.
     *
     * @var http_client|null
     */
    private ?http_client $client = null;

    /**
     * Builds the transport the factory asks for, with no arguments and no side effects.
     */
    public function __construct() {
    }

    /**
     * Builds the transport on top of a given HTTP client, which is how the tests reach the wire.
     *
     * Same seam, and for the same reasons, as {@see meta_cloud::with_client()}: a named constructor rather than a
     * setter, so the client is fixed for the life of the object and the one class that makes network calls has no
     * mutable state.
     *
     * @param http_client $client Client every request of this instance goes through.
     * @return self A transport that talks through that client.
     */
    public static function with_client(http_client $client): self {
        $transport = new self();
        $transport->client = $client;

        return $transport;
    }

    /**
     * Returns the machine name of this transport.
     *
     * @return string Always `gateway`.
     */
    public function name(): string {
        return self::NAME;
    }

    /**
     * Sends one approved template through the gateway.
     *
     * @param string $phone Destination in E.164.
     * @param string $template Name of the approved template, as registered in the service.
     * @param string $lang Language code of the approved version, for instance `es_AR`.
     * @param string[] $params Body parameters in template order, already sanitised.
     * @param string|null $urlsuffix Signed click token of the queue row, or null when the template has no button.
     * @param string $idempotencykey Stable identifier of this message. Sent as `Idempotency-Key`, which is what
     *      makes a retry of the same queue row answer the same message id instead of sending a second one.
     * @return result The message id on success, otherwise the reason and whether it is worth trying again.
     */
    public function send_template(
        string $phone,
        string $template,
        string $lang,
        array $params,
        ?string $urlsuffix,
        string $idempotencykey
    ): result {
        $config = $this->config();
        if ($config === null) {
            return self::unconfigured_failure();
        }

        $payload = [
            'to' => $phone,
            'template' => $template,
            'lang' => $lang,
            'params' => array_values(array_map(static fn($value): string => (string) $value, $params)),
        ];

        if ($urlsuffix !== null && $urlsuffix !== '') {
            $payload['target_url'] = self::click_url($urlsuffix);
        }

        try {
            $body = self::encode($payload);
        } catch (\Throwable $e) {
            return result::permanent_failure(self::CODE_INTERNAL, 'The message could not be encoded for the gateway.');
        }

        $answer = $this->execute('POST', $config['url'] . self::PATH_MESSAGES, $config['apikey'], $body, [
            // The whole point of the sixth parameter of §3.7. The gateway answers the same message id for the
            // same key for ever, so a row retried after this site died between the send and the write of the
            // answer does not reach the recipient twice.
            'Idempotency-Key' => $idempotencykey,
        ]);
        if ($answer instanceof result) {
            return $answer;
        }

        $data = self::decode($answer);
        $failure = self::failure_of($answer, $data, $config['apikey']);
        if ($failure !== null) {
            return $failure;
        }

        $id = $data['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            // The gateway accepted the message and did not say which one it is, which its own contract does not
            // allow. Retried rather than written off, for the same reason as in direct mode: the alternative is
            // losing a notification that was very probably accepted, and the idempotency key makes the retry
            // safe here in a way it is not there.
            return result::transient_failure(
                self::CODE_INVALID_RESPONSE,
                'The gateway accepted the message but returned no id.'
            );
        }

        return result::success(trim($id));
    }

    /**
     * Asks the gateway whether this site could send right now, without sending anything.
     *
     * @return result Success when the key is valid and the subscription can send, otherwise why not.
     */
    public function check(): result {
        $config = $this->config();
        if ($config === null) {
            return self::unconfigured_failure();
        }

        $answer = $this->execute('POST', $config['url'] . self::PATH_CHECK, $config['apikey'], null);
        if ($answer instanceof result) {
            return $answer;
        }

        $data = self::decode($answer);
        $failure = self::failure_of($answer, $data, $config['apikey']);
        if ($failure !== null) {
            return $failure;
        }

        if (empty($data['can_send'])) {
            // The key resolved but the service says this subscription may not send. The gateway answers
            // `tenant_suspended` for that, so reaching here means a shape nobody expected.
            return result::permanent_failure(
                self::CODE_INVALID_RESPONSE,
                'The gateway answered the connection test without saying whether this site can send.'
            );
        }

        return result::success();
    }

    /**
     * Asks the gateway for the status changes since a cursor.
     *
     * This is what {@see \message_whatsapp\task\sync_status} calls, and it lives here rather than in the task for
     * the same reason everything else does: this is the only class of the plugin that reaches the network, and a
     * scheduled task that opened its own HTTP client would be a second one.
     *
     * @param string $cursor Cursor of the previous run, or the empty string to start from the beginning.
     * @param int $limit Largest page to ask for.
     * @return changes|result The page of changes, or the failure that stopped it from arriving.
     */
    public function changes_since(string $cursor, int $limit): changes|result {
        $config = $this->config();
        if ($config === null) {
            return self::unconfigured_failure();
        }

        $query = '?' . http_build_query(['since' => $cursor, 'limit' => $limit]);

        $answer = $this->execute('GET', $config['url'] . self::PATH_CHANGES . $query, $config['apikey'], null);
        if ($answer instanceof result) {
            return $answer;
        }

        $data = self::decode($answer);
        $failure = self::failure_of($answer, $data, $config['apikey']);
        if ($failure !== null) {
            return $failure;
        }

        $parsed = changes::from_answer($data);

        return $parsed ?? result::transient_failure(
            self::CODE_INVALID_RESPONSE,
            'The gateway answered the status cursor with something other than a page of changes.'
        );
    }

    /**
     * Reads the settings this transport needs, or reports that they are not there.
     *
     * @return array{url: string, apikey: string}|null The configuration, or null when gateway mode is not set up.
     */
    private function config(): ?array {
        $url = self::base_url((string) get_config('message_whatsapp', 'gatewayurl'));
        $apikey = trim((string) get_config('message_whatsapp', 'gatewayapikey'));

        if ($url === null || $apikey === '') {
            return null;
        }

        return ['url' => $url, 'apikey' => $apikey];
    }

    /**
     * Turns the configured address into a base this transport is willing to call.
     *
     * The value is checked rather than trusted even though an administrator typed it, because the API key travels
     * on every request made to it: an address hand edited to somebody else's host would hand them the credential
     * of the site. Only `http` and `https` are accepted, and the trailing slash is dropped so that the paths of
     * this class can be concatenated without producing a double one.
     *
     * @param string $value Value of the `gatewayurl` setting.
     * @return string|null The base URL with no trailing slash, or null when it is not usable.
     */
    public static function base_url(string $value): ?string {
        $url = rtrim(trim($value), '/');

        if ($url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }

        return $url;
    }

    /**
     * Returns the address the button of a message has to land on.
     *
     * `go.php` of this site and not the address of the course, so that a click is recorded the same way in both
     * modes: the gateway counts it for its own billing, `go.php` records it in `message_whatsapp_click` and then
     * sends the reader on to wherever the queue row points. The alternative, giving the gateway the target
     * directly, would leave the click report of the plugin empty for every site in gateway mode.
     *
     * @param string $token Signed click token of the queue row, as `queue::click_token()` produced it.
     * @return string Absolute URL inside this site.
     */
    public static function click_url(string $token): string {
        global $CFG;

        return $CFG->wwwroot . '/message/output/whatsapp/go.php?t=' . rawurlencode($token);
    }

    /**
     * Encodes a body with the same flags the queue stores its parameters with.
     *
     * @param array $payload Body to encode.
     * @return string The JSON body.
     * @throws \JsonException When the payload cannot be encoded.
     */
    private static function encode(array $payload): string {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Makes one request and gives back either the answer or the failure that stopped it from arriving.
     *
     * @param string $method HTTP method.
     * @param string $url Absolute URL to call.
     * @param string $apikey API key to authenticate with.
     * @param string|null $body JSON body, or null when there is none.
     * @param array $extraheaders Headers to add to the request.
     * @return ResponseInterface|result The answer, or the failure that replaces it when there was none.
     */
    private function execute(
        string $method,
        string $url,
        string $apikey,
        ?string $body,
        array $extraheaders = []
    ): ResponseInterface|result {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $apikey,
                'Accept' => 'application/json',
            ] + $extraheaders,
            // A 4xx of this API is a `{"code","detail"}` to be read, not an exception to be caught.
            'http_errors' => false,
            // A redirect would resend the API key of the site to whatever host the answer pointed at.
            'allow_redirects' => false,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TIMEOUT,
        ];

        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $body;
        }

        try {
            return $this->client()->request($method, $url, $options);
        } catch (ConnectException $e) {
            // Refused connection, DNS, TLS, timeout: the request never got an answer. Built here rather than
            // taken from the exception, whose message quotes the whole URL.
            return result::transient_failure(
                self::CODE_NETWORK,
                'The gateway could not be reached: ' . self::describe_exception($e)
            );
        } catch (RequestException $e) {
            if (!$e->hasResponse()) {
                // Nothing ever went on the wire, and it was not the network that stopped it: this is the cURL
                // security of the site refusing the address. Permanent, because a backoff does not change a
                // setting -- an administrator does.
                return result::permanent_failure(
                    self::CODE_BLOCKED,
                    'Moodle refused to call that address. It is the HTTP security of the site, under Site '
                        . 'administration / General / Security / HTTP security: a gateway on a private address '
                        . 'or a non standard port has to be allowed there before this site can reach it.'
                );
            }

            return result::transient_failure(
                self::CODE_NETWORK,
                'The gateway could not be reached: ' . self::describe_exception($e)
            );
        } catch (GuzzleException $e) {
            return result::transient_failure(
                self::CODE_NETWORK,
                'The gateway could not be reached: ' . self::describe_exception($e)
            );
        } catch (\Throwable $e) {
            return result::permanent_failure(self::CODE_INTERNAL, 'The request to the gateway could not be made.');
        }
    }

    /**
     * Returns the HTTP client of this transport, building the default one the first time it is needed.
     *
     * @return http_client The client every request goes through.
     */
    private function client(): http_client {
        if ($this->client === null) {
            $this->client = new http_client();
        }

        return $this->client;
    }

    /**
     * Decodes the body of an answer into an array, or an empty array when it is not one.
     *
     * @param ResponseInterface $response Answer of the gateway.
     * @return array The decoded object, or an empty array when the body is not a JSON object.
     */
    private static function decode(ResponseInterface $response): array {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Turns an answer into a failure, or returns null when the answer is a success.
     *
     * Every failure that came from the gateway is built here, so that the classification is applied in one place
     * for the three callers and cannot drift between them.
     *
     * @param ResponseInterface $response Answer of the gateway.
     * @param array $data Decoded body, empty when the body was not JSON.
     * @param string $apikey API key, so that it can be scrubbed if it is ever echoed back.
     * @return result|null The failure, or null when this answer is a success.
     */
    private static function failure_of(ResponseInterface $response, array $data, string $apikey): ?result {
        $status = $response->getStatusCode();
        $code = isset($data['code']) && is_string($data['code']) ? trim($data['code']) : '';

        if ($code !== '') {
            $detail = isset($data['detail']) && is_string($data['detail']) ? $data['detail'] : '';
            $diagnostic = self::scrub('Gateway error ' . $code . ': ' . $detail, $apikey);

            if (in_array($code, self::PERMANENT_CODES, true)) {
                return result::permanent_failure($code, $diagnostic);
            }

            if (in_array($code, self::TRANSIENT_CODES, true)) {
                return result::transient_failure($code, $diagnostic);
            }

            // A code that is not in §4.3 either postdates this file or came from something that is not the
            // gateway. Classified by status, which is the only honest thing left: turning an unknown code into
            // a wrong certainty is worse than falling back.
            return self::by_status($status, $code, $diagnostic);
        }

        if ($status < 200 || $status >= 300) {
            return self::by_status(
                $status,
                self::CODE_INVALID_RESPONSE,
                'The gateway answered HTTP ' . $status . ' with no code of its own.'
            );
        }

        if ($data === []) {
            return result::transient_failure(
                self::CODE_INVALID_RESPONSE,
                'The gateway answered HTTP ' . $status . ' with a body that is not JSON.'
            );
        }

        return null;
    }

    /**
     * Classifies a failure this transport has no code for, using the HTTP status.
     *
     * Same rule as {@see meta_cloud}: a 408, a 429 or a 5xx is the moment, any other 4xx is the request, and
     * anything else is retried because a transport that could not tell what went wrong should not be the one
     * deciding that the notification is lost.
     *
     * @param int $status HTTP status of the answer.
     * @param string $code Failure code to report.
     * @param string $message Diagnostic for the administrator.
     * @return result The classified failure.
     */
    private static function by_status(int $status, string $code, string $message): result {
        if ($status === 408 || $status === 429 || $status >= 500) {
            return result::transient_failure($code, $message);
        }

        if ($status >= 400) {
            return result::permanent_failure($code, $message);
        }

        return result::transient_failure($code, $message);
    }

    /**
     * The failure returned when gateway mode has nothing to call.
     *
     * Permanent, for the same reason the equivalent of direct mode is: an empty setting resolves when somebody
     * opens the settings page, not while a backoff ticks.
     *
     * @return result A permanent failure naming the missing configuration.
     */
    private static function unconfigured_failure(): result {
        return result::permanent_failure(
            self::CODE_NOT_CONFIGURED,
            'Gateway mode needs the address of the service and an API key, and at least one of the two is empty '
                . 'or not usable.'
        );
    }

    /**
     * Describes an exception of the HTTP client without quoting it.
     *
     * @param \Throwable $e The exception Guzzle threw.
     * @return string The class of the exception, which says what kind of failure it was and nothing else.
     */
    private static function describe_exception(\Throwable $e): string {
        $parts = explode('\\', get_class($e));

        return (string) end($parts);
    }

    /**
     * Removes from a diagnostic everything that must not end up in the report or in a log.
     *
     * The same two things {@see meta_cloud::scrub()} removes, and for the same reasons: the API key, because a
     * service that echoed a request back would write the credential of the site into a column an administration
     * page prints; and anything shaped like a phone number, because the error is shown next to the user it
     * belongs to and a number copied into free text is personal data that outlives its purpose.
     *
     * @param string $text Diagnostic as it came from the gateway.
     * @param string $apikey API key of the site.
     * @return string A diagnostic that is safe to store, capped in length.
     */
    private static function scrub(string $text, string $apikey): string {
        if ($apikey !== '') {
            $text = str_replace($apikey, '[apikey]', $text);
        }

        $text = (string) preg_replace('/\+?\d[\d\-. ()]{6,}\d/', '[number]', $text);

        return \core_text::substr(trim($text), 0, self::MAX_DIAGNOSTIC);
    }
}
