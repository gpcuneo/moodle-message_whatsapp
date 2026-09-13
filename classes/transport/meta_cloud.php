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
 * Transport that sends WhatsApp templates through the Meta Cloud API with the credentials of the site.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace message_whatsapp\transport;

use core\http_client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * The direct mode: this site's own Meta Cloud API credentials, no intermediary.
 *
 * It does one thing per public method and nothing else: `POST /{phone_number_id}/messages` with a bearer token to
 * send an approved template, and `GET /{phone_number_id}` to answer the test connection button. It holds no state
 * between calls, it writes nothing to the database and it logs nothing, because the row state and the diagnostic
 * for the administrator both travel back inside the {@see result}.
 *
 * **It never throws.** A refused connection, a DNS failure, a timeout, an HTML error page from a corporate proxy
 * and a bug in this file all come back as a `result`. See {@see transport_interface} for why: the caller is a
 * scheduled task draining a queue that must survive one bad row, and an administration page that has to print the
 * problem instead of a stack trace.
 *
 * **It ignores `$idempotencykey` on purpose.** The Cloud API has no idempotency header, so there is nothing to send
 * it in. In direct mode the protection against a duplicate is one layer down, in the queue: the named lock, the
 * transaction and the `UPDATE ... AND status = 'pending'` of {@see \message_whatsapp\local\queue::claim()} are what
 * make sure two workers never carry the same row to this method. The parameter stays in the signature because the
 * gateway transport of T4.4 does need it, and because a contract that is honoured by one implementation and not by
 * the other is still one contract. See §3.7 of `docs/arquitectura.md`, decision **[D]** of 13-sep-2026.
 *
 * **It reads three settings and not five.** `metatoken`, `metaphoneid` and `graphversion` are what sending needs.
 * `metaappsecret` is the key that `webhook.php` validates the `X-Hub-Signature-256` of an incoming delivery report
 * with, and `metawabaid` identifies the business account for template management; neither takes part in a send, and
 * a `check()` that failed because one of them is empty would report a problem that does not exist to an
 * administrator who is trying to find out whether they can send.
 *
 * @package    message_whatsapp
 * @copyright  2026 Guillermo Cuneo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class meta_cloud implements transport_interface {
    /** Machine name reported by {@see self::name()}. Stable: log lines and tests compare it. */
    public const NAME = 'meta_cloud';

    /**
     * Graph API version used when the site has none configured.
     *
     * v25.0 is the current version of the Graph API (released 18-feb-2026, verified against the Meta changelog on
     * 13-sep-2026). Meta keeps a version usable for about two years, so a site that never touches the setting stays
     * on a supported version for longer than the release cycle of this plugin.
     */
    public const DEFAULT_GRAPH_VERSION = 'v25.0';

    /**
     * Code of the one failure that says something about the person and not about the site.
     *
     * §3.4 of the architecture asks the sending task to mark the recipient as invalid when the number turns out not
     * to be reachable on WhatsApp, and this is the code that says so. It is a constant so that the task can branch
     * on it without writing the number of a Meta error into its own source.
     */
    public const CODE_UNDELIVERABLE = 'meta_131026';

    /** Failure code used when the request never reached Meta: refused connection, DNS, timeout, TLS. */
    public const CODE_NETWORK = 'network_error';

    /** Failure code used when Meta answered an HTTP error that carried no error code of its own. */
    public const CODE_HTTP = 'http_error';

    /** Failure code used when the answer was not the JSON this transport knows how to read. */
    public const CODE_INVALID_RESPONSE = 'invalid_response';

    /** Failure code used when something inside this plugin threw while building or reading the request. */
    public const CODE_INTERNAL = 'internal_error';

    /** Prefix of every failure code that comes from a numeric error code of the Cloud API. */
    public const CODE_PREFIX = 'meta_';

    /** Base of the Graph API. Hard coded: it is not a setting, and a settable host is a way to leak the token. */
    private const HOST = 'https://graph.facebook.com';

    /** Seconds to wait for the connection itself. Short: an unreachable host must not hold up the queue. */
    private const CONNECT_TIMEOUT = 10;

    /** Seconds to wait for the whole request. The sending task runs every minute and processes a batch. */
    private const TIMEOUT = 30;

    /** Longest diagnostic kept from an answer of Meta. The queue truncates too; this keeps a blob out of memory. */
    private const MAX_DIAGNOSTIC = 400;

    /**
     * Error codes of the Cloud API that must never be retried, and why each one of them is on this list.
     *
     * This list and {@see self::TRANSIENT_CODES} are the most consequential twenty lines of this class, because
     * they are what the queue reads to decide between writing the row off now and trying it four more times:
     *
     * - A transient failure filed here **loses a notification** that the second attempt would have delivered.
     * - A permanent failure filed in the other list **hammers Meta five times** with a request that cannot succeed,
     *   and buries the real cause under four identical attempts in the administrator's report.
     *
     * The test that decides the list is not whether the condition could ever change, but whether it can change
     * **inside the retry horizon of the queue**, which is five attempts over roughly an hour of exponential
     * backoff. A restriction that is lifted by an appeal, a template that is unpaused after a quality review and a
     * credit card that somebody has to go and fix are all "temporary" in the ordinary sense of the word and all
     * permanent in this one.
     *
     * Verified against the Cloud API error reference of Meta on 13-sep-2026.
     *
     * @var int[]
     */
    private const PERMANENT_CODES = [
        // 0 AuthException: the token could not be parsed or was invalidated. Every retry sends the same token.
        0,
        // 3 the application lacks a capability or a permission. It is granted in the App Dashboard, not by waiting.
        3,
        // 10 permission not granted or withdrawn. Same as 3: a person has to change the application.
        10,
        // 33 the business phone number does not exist or was deleted. The `metaphoneid` setting is simply wrong.
        33,
        // 100 unsupported or misspelled parameter. Our own payload is wrong, and it will be identical next time.
        100,
        // 190 the access token expired. Only a new token fixes it, and issuing one is a manual step.
        190,
        // 200 the token carries none of the permissions this call needs. A person has to reissue it with them.
        200,
        // 368 the business account is restricted for a policy violation. Lifted by appeal, in days, never in an hour.
        368,
        // 130472 the recipient is inside a Meta marketing experiment and the message is withheld from them.
        // The experiment does not end because we asked twice, and Meta's own advice is not to retry.
        130472,
        // 131005 access denied to the resource. A permission problem wearing a messaging error number.
        131005,
        // 131008 a required parameter is missing from the request. Ours to fix in code, not Meta's to recover from.
        131008,
        // 131009 a parameter value is not valid. Same payload next attempt, same answer.
        131009,
        // 131021 sender and recipient are the same number. The two numbers do not change between attempts.
        131021,
        // 131026 undeliverable: not a WhatsApp number, terms of service not accepted, ancient client, or a device
        // that has been offline for over thirty days. Every one of those outlives an hour of backoff, and this is
        // the code §3.4 wants the recipient marked invalid on. THE one that must not be misfiled: a site whose
        // phone field is full of landlines would otherwise send five requests per notification forever.
        131026,
        // 131031 the account is locked or restricted. A person has to resolve it with Meta.
        131031,
        // 131037 the display name of the business number is not approved yet. Approval is a review, not a wait.
        131037,
        // 131042 there is something wrong with the payment method of the account. Somebody has to pay.
        131042,
        // 131045 the business phone number is not registered, or its certificate is wrong. Registration is manual.
        131045,
        // 131047 more than 24 hours since the recipient wrote, so only a template may be sent. The window does not
        // reopen because we asked again; it reopens when the person writes. Should never reach us -- this plugin
        // only ever sends templates -- and if it does, it means the template was not accepted as one.
        131047,
        // 131049 withheld to maintain healthy ecosystem engagement, the per recipient cap on marketing templates.
        // Meta's own instruction is to wait more than 24 hours, and the backoff of the queue tops out at one.
        131049,
        // 131051 unsupported message type. Our payload again.
        131051,
        // 131053 the media of the message could not be uploaded. The base template of §3.5 carries no media, so
        // this can only mean a template with a media header whose file is wrong, which no retry repairs.
        131053,
        // 132000 the number of parameters does not match the template. Either the mapper or the approved template
        // changed. The mismatch is structural and identical on every attempt.
        132000,
        // 132001 the template does not exist in that language, or is not approved. Structural: a template is
        // approved per language, and the language of the queue row was frozen when the notification was raised.
        132001,
        // 132005 the hydrated text is too long for the template. Same parameters, same length, same failure.
        132005,
        // 132007 the content violates a WhatsApp policy. A review decision, not a passing condition.
        132007,
        // 132012 a parameter value has a format the template does not accept, a newline for instance. It means the
        // sanitiser let something through, which is a bug here and not a bad moment at Meta.
        132012,
        // 132015 the template is paused for low quality. Pauses last hours to days and are lifted by editing the
        // template. Filed as permanent knowingly: see the report of T2.2, this is the closest call on the list.
        132015,
        // 132016 the template was disabled after being paused repeatedly. There is no coming back from this one.
        132016,
        // 133010 the phone number is not registered on the WhatsApp Business Platform. Registration is a manual
        // step in WhatsApp Manager, so every attempt of the hour would reach exactly this sentence again.
        133010,
        // 133015 the phone number was deleted and the deletion is still in progress. It is on its way out, not
        // on its way back: nothing that happens in the next hour turns it into a sender again.
        133015,
        // 135000 generic error blamed on the parameters of the request. Meta points at our payload, and our
        // payload is byte for byte the same on the next attempt.
        135000,
    ];

    /**
     * Error codes of the Cloud API that are worth trying again, and why each one of them is on this list.
     *
     * These are the ones about the moment rather than about the request: Meta is overloaded, this site is going too
     * fast, or the account is briefly in maintenance. The exponential backoff of the queue is the right answer to
     * all of them, and a code that stays on for the whole hour ends `failed` anyway, once, with the true cause
     * written in the report instead of a made up one.
     *
     * Verified against the Cloud API error reference of Meta on 13-sep-2026.
     *
     * @var int[]
     */
    private const TRANSIENT_CODES = [
        // 1 API unknown, which Meta documents as possibly a temporary problem on their side, to be retried.
        1,
        // 2 API service temporarily down or overloaded. The textbook case for a backoff.
        2,
        // 4 application level rate limit. It refills with time and with nothing else.
        4,
        // 80007 rate limit of the business account reached. Same: it refills.
        80007,
        // 130429 Cloud API throughput reached. The canonical retryable code of this API.
        130429,
        // 131000 an unknown internal error at Meta. Their documented solution is to retry the request.
        131000,
        // 131016 the service is temporarily unavailable.
        131016,
        // 131048 spam rate limit: sending is restricted because the quality rating of the number dropped. It is a
        // rate limit and it decays, so the backoff is the right answer. If the rating stays low the five attempts
        // are spent and the row ends `failed` with this code, which is the true story of what happened.
        131048,
        // 131056 pair rate limit: too many messages to this same recipient too quickly. Minutes, which is exactly
        // the range the backoff of the queue covers.
        131056,
        // 131057 the business account is in maintenance mode, usually a throughput upgrade. Temporary by
        // definition, and Meta gives no other instruction than to wait.
        131057,
        // 133004 the server is temporarily unavailable.
        133004,
    ];

    /**
     * The HTTP client to use, or null to build the default one on first use.
     *
     * @var http_client|null
     */
    private ?http_client $client = null;

    /**
     * Builds the transport the factory asks for, with no arguments and no side effects.
     *
     * {@see factory::instance()} does `new $class()`, so this constructor cannot require anything. Everything it
     * would need is read from the configuration at the moment of the call instead, which is also what makes a
     * setting saved between two runs of the sending task take effect without a restart.
     */
    public function __construct() {
    }

    /**
     * Builds the transport on top of a given HTTP client, which is how the tests reach the wire.
     *
     * The seam is here and not in the constructor so that the factory keeps its `new $class()`, and it is a named
     * constructor rather than a setter so that the client is fixed for the life of the object: the one place in
     * this plugin that makes network calls has no mutable state, for the same reason
     * {@see transport_interface::send_template()} takes the idempotency key as an argument instead of a setter.
     *
     * A test passes `new \core\http_client(['mock' => $mockhandler])`, which is the seam core itself uses in
     * `lib/tests/http_client_test.php`. Nothing in production code calls this.
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
     * @return string Always `meta_cloud`.
     */
    public function name(): string {
        return self::NAME;
    }

    /**
     * Sends one approved template to one number through the Cloud API.
     *
     * @param string $phone Destination in E.164.
     * @param string $template Name of the approved template, as registered in Meta.
     * @param string $lang Language code of the approved version, for instance `es_AR`.
     * @param string[] $params Body parameters in template order, already sanitised.
     * @param string|null $urlsuffix Dynamic suffix of the URL button, or null when the template has no button.
     * @param string $idempotencykey Ignored on purpose: the Cloud API has no idempotency header and the queue is
     *      what protects direct mode against a duplicate. See the class documentation.
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

        try {
            $body = self::encode(self::payload($phone, $template, $lang, $params, $urlsuffix));
        } catch (\Throwable $e) {
            return result::permanent_failure(self::CODE_INTERNAL, 'The message could not be encoded for the Cloud API.');
        }

        $answer = $this->execute('POST', self::endpoint($config) . '/messages', $config['token'], $body);
        if ($answer instanceof result) {
            return $answer;
        }

        $data = self::decode($answer);
        $failure = self::failure_of($answer, $data, $config['token']);
        if ($failure !== null) {
            return $failure;
        }

        $id = $data['messages'][0]['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            // Meta answered a success with no message id, which is not a shape this API produces: something in
            // between rewrote the body. Retried rather than written off, because the alternative is losing a
            // notification that may well have been accepted, and T1.5 already chose a possible duplicate over a
            // lost message for the same reason.
            return result::transient_failure(
                self::CODE_INVALID_RESPONSE,
                'The Cloud API accepted the message but returned no message id.'
            );
        }

        return result::success($id);
    }

    /**
     * Asks the Cloud API whether these credentials can send, without sending anything.
     *
     * `GET /{phone_number_id}` is the cheapest call that exercises both halves of the configuration at once: the
     * token has to be valid for the request to be authorised, and the phone number id has to exist and belong to
     * that token for the object to come back. It delivers nothing and costs nothing.
     *
     * @return result Success when the credentials work, otherwise the reason why they do not.
     */
    public function check(): result {
        $config = $this->config();
        if ($config === null) {
            return self::unconfigured_failure();
        }

        $answer = $this->execute('GET', self::endpoint($config), $config['token'], null);
        if ($answer instanceof result) {
            return $answer;
        }

        $data = self::decode($answer);
        $failure = self::failure_of($answer, $data, $config['token']);
        if ($failure !== null) {
            return $failure;
        }

        if (!isset($data['id'])) {
            return result::transient_failure(
                self::CODE_INVALID_RESPONSE,
                'The Cloud API answered the connection test with something other than the business phone number.'
            );
        }

        return result::success();
    }

    /**
     * Reads the settings this transport needs, or reports that they are not there.
     *
     * @return array{token: string, phoneid: string, version: string}|null The credentials, or null when the site
     *      has not finished configuring direct mode.
     */
    private function config(): ?array {
        $token = trim((string) get_config('message_whatsapp', 'metatoken'));
        $phoneid = trim((string) get_config('message_whatsapp', 'metaphoneid'));

        if ($token === '' || $phoneid === '') {
            return null;
        }

        return [
            'token' => $token,
            'phoneid' => $phoneid,
            'version' => self::version(),
        ];
    }

    /**
     * Returns the Graph API version to call.
     *
     * The value is checked against the shape Meta uses rather than trusted, because it is pasted into a URL. A
     * setting that has been hand edited to `../../oauth` would otherwise turn a message into a request to another
     * endpoint carrying the bearer token of the site.
     *
     * @return string A version of the form `v25.0`.
     */
    private static function version(): string {
        $version = trim((string) get_config('message_whatsapp', 'graphversion'));

        return preg_match('/^v\d+\.\d+$/', $version) === 1 ? $version : self::DEFAULT_GRAPH_VERSION;
    }

    /**
     * Builds the URL of the business phone number, which every call of this transport hangs off.
     *
     * @param array $config Credentials as {@see self::config()} returned them: `token`, `phoneid` and `version`.
     * @return string Absolute URL of the phone number object.
     */
    private static function endpoint(array $config): string {
        return self::HOST . '/' . $config['version'] . '/' . rawurlencode($config['phoneid']);
    }

    /**
     * Builds the body Meta expects for a template message.
     *
     * The shape is fixed by the Cloud API: a `template` message carries the name of the template, the language of
     * the approved version and a list of components. The body component holds the positional parameters in template
     * order, and the button component holds the dynamic suffix that Meta appends to the fixed base URL of the
     * button -- which is why there is a suffix and not a URL: §2.3 of the architecture, the base belongs to the
     * approved template and cannot be chosen per message.
     *
     * The parameters are passed through untouched. They arrive sanitised from
     * {@see \message_whatsapp\local\sanitizer} and within the length limits of the template, and a transport that
     * quietly repaired them would hide the bug that produced them.
     *
     * @param string $phone Destination in E.164.
     * @param string $template Name of the approved template.
     * @param string $lang Language code of the approved version.
     * @param string[] $params Body parameters in template order.
     * @param string|null $urlsuffix Dynamic suffix of the URL button, or null when there is no button.
     * @return array The request body, ready to be encoded.
     */
    private static function payload(
        string $phone,
        string $template,
        string $lang,
        array $params,
        ?string $urlsuffix
    ): array {
        $components = [];

        if ($params !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn($value): array => ['type' => 'text', 'text' => (string) $value],
                    array_values($params)
                ),
            ];
        }

        if ($urlsuffix !== null) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $urlsuffix]],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $lang],
                'components' => $components,
            ],
        ];
    }

    /**
     * Encodes the body with the same flags the queue stores its parameters with.
     *
     * `JSON_UNESCAPED_UNICODE` keeps an accented subject readable on the wire and in a test failure, and
     * `JSON_UNESCAPED_SLASHES` keeps a URL suffix looking like a URL suffix. Both match
     * {@see \message_whatsapp\local\mapped::params_json()}, so what is stored and what is sent can be compared by
     * eye. `JSON_THROW_ON_ERROR` is what turns malformed UTF-8 into something the caller can answer with a result
     * instead of a silent `false`.
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
     * `http_errors` is off so that a 4xx or a 5xx comes back as a response to be read rather than as an exception
     * to be caught: the interesting part of an error of this API is the JSON body, not the status line. Redirects
     * are off because a redirect would resend the bearer token of the site to whatever host Meta pointed at.
     *
     * @param string $method HTTP method.
     * @param string $url Absolute URL to call.
     * @param string $token Access token to authenticate with.
     * @param string|null $body JSON body for a POST, or null for a GET.
     * @return ResponseInterface|result The answer, or the failure that replaces it when there was none.
     */
    private function execute(string $method, string $url, string $token, ?string $body): ResponseInterface|result {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
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
        } catch (GuzzleException $e) {
            // Refused connection, DNS, TLS, timeout: the request never got an answer. The message is built here
            // rather than taken from the exception, because a Guzzle message quotes the whole URL and a plugin
            // that pastes provider text into a report has no promise left about what ends up in it.
            return result::transient_failure(
                self::CODE_NETWORK,
                'The Cloud API could not be reached: ' . self::describe_exception($e)
            );
        } catch (\Throwable $e) {
            // A bug in this plugin, not a bad moment at Meta. Permanent on purpose: four more identical attempts
            // would reach the same line of code an hour later with the cause buried under them.
            return result::permanent_failure(self::CODE_INTERNAL, 'The request to the Cloud API could not be made.');
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
     * @param ResponseInterface $response Answer of the Cloud API.
     * @return array The decoded object, or an empty array when the body is not a JSON object.
     */
    private static function decode(ResponseInterface $response): array {
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Turns an answer into a failure, or returns null when the answer is a success.
     *
     * Every failure of this transport that came from Meta is built here, so that the classification is applied in
     * one place for both `send_template()` and `check()` and cannot drift between them.
     *
     * @param ResponseInterface $response Answer of the Cloud API.
     * @param array $data Decoded body, empty when the body was not JSON.
     * @param string $token Access token, so that it can be scrubbed if it is ever echoed back.
     * @return result|null The failure, or null when this answer is a success.
     */
    private static function failure_of(ResponseInterface $response, array $data, string $token): ?result {
        $status = $response->getStatusCode();
        $error = $data['error'] ?? null;

        if (is_array($error)) {
            return self::failure_from_error($error, $status, $token);
        }

        if ($status < 200 || $status >= 300) {
            return self::by_status(
                $status,
                self::CODE_HTTP,
                'The Cloud API answered HTTP ' . $status . ' with no error code.'
            );
        }

        if ($data === []) {
            return result::transient_failure(
                self::CODE_INVALID_RESPONSE,
                'The Cloud API answered HTTP ' . $status . ' with a body that is not JSON.'
            );
        }

        return null;
    }

    /**
     * Classifies one error object of the Cloud API.
     *
     * The numeric `code` is what decides, and the two tables of this class are where the decision is written down
     * code by code. A code that is on neither table is classified by its HTTP status, which is the only honest
     * thing left to do with a code that did not exist when this was written: Meta adds codes, and the plugin must
     * not turn an unknown one into a wrong certainty.
     *
     * @param array $error The `error` object of the answer.
     * @param int $status HTTP status of the answer.
     * @param string $token Access token, so that it can be scrubbed if it is ever echoed back.
     * @return result The classified failure.
     */
    private static function failure_from_error(array $error, int $status, string $token): result {
        $code = array_key_exists('code', $error) && is_numeric($error['code']) ? (int) $error['code'] : null;
        $details = $error['error_data']['details'] ?? null;
        $message = is_string($details) && trim($details) !== '' ? $details : (string) ($error['message'] ?? '');
        $diagnostic = self::scrub(($code === null ? 'Cloud API error' : 'Cloud API error ' . $code) . ': ' . $message, $token);

        if ($code === null) {
            return self::by_status($status, self::CODE_HTTP, $diagnostic);
        }

        $failurecode = self::CODE_PREFIX . $code;

        if (in_array($code, self::PERMANENT_CODES, true)) {
            return result::permanent_failure($failurecode, $diagnostic);
        }

        if (in_array($code, self::TRANSIENT_CODES, true)) {
            return result::transient_failure($failurecode, $diagnostic);
        }

        return self::by_status($status, $failurecode, $diagnostic);
    }

    /**
     * Classifies a failure this transport has no error code for, using the HTTP status.
     *
     * A 429 or a 5xx is the moment; any other 4xx is the request, and the request is the same on every attempt. An
     * answer that is neither is retried, because if this transport could not even tell what went wrong it should
     * not be the one deciding that the notification is lost.
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
     * The failure returned when direct mode has no credentials to use.
     *
     * Permanent, and for the same reason {@see unconfigured} is permanent: an empty setting is not a condition that
     * resolves while a backoff ticks, it resolves when somebody opens the settings page. The code is the one the
     * factory already uses for the same situation, so the report shows one cause and not two spellings of it.
     *
     * @return result A permanent failure naming the missing configuration.
     */
    private static function unconfigured_failure(): result {
        return result::permanent_failure(
            factory::CODE_NOT_CONFIGURED,
            'Direct mode needs the access token and the business phone number id of the Meta Cloud API, '
                . 'and at least one of the two is empty.'
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
     * Two things are scrubbed. The access token, because a provider that echoes a request back would otherwise
     * write the credentials of the site into a database column that the administrator report prints on a page.
     * And any run of digits long enough to be a phone number, because the error column of the queue is shown next
     * to the user it belongs to, which is where the number already is, and a phone number copied into free text is
     * personal data that outlives its purpose. The error codes of the Cloud API are six digits or fewer and survive
     * the scrubbing.
     *
     * @param string $text Diagnostic as it came from Meta.
     * @param string $token Access token of the site.
     * @return string A diagnostic that is safe to store, capped in length.
     */
    private static function scrub(string $text, string $token): string {
        if ($token !== '') {
            $text = str_replace($token, '[token]', $text);
        }

        $text = (string) preg_replace('/\+?\d[\d\-. ()]{6,}\d/', '[number]', $text);

        return \core_text::substr(trim($text), 0, self::MAX_DIAGNOSTIC);
    }
}
