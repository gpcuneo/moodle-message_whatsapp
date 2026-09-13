# Changelog

All notable changes to `message_whatsapp` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The plugin now sends end to end in direct mode. The version number of the release is set when it is tagged;
everything below has been built and verified on Moodle 4.5 and 5.2.

### Added
- **Gateway mode.** `transport\gateway` sends through the wa-gateway service with an API key instead of Meta
  credentials: `POST /v1/messages` with `Idempotency-Key` set to the queue row id, so a retried row answers the
  same message id instead of reaching the recipient twice, and `POST /v1/check` behind the *Test connection*
  button. `task\sync_status` pulls the delivery statuses every five minutes by cursor, because nothing can be
  pushed at a site the service cannot reach, and stores the cursor in the plugin configuration. Settings
  *Service address* and *API key*.

- **Database.** Three tables: `message_whatsapp_user` (phone number, its source, opt-in and its timestamp,
  verification, status), `message_whatsapp_queue` (one row per notification: recipient, phone, template, its
  language, parameters, destination URL, status, attempts, provider message id, error, pricing category) and
  `message_whatsapp_click`. Installed by `install.xml` and upgraded with savepoints.
- **Phone numbers.** `local\phone`: normalisation to E.164 with a default country setting, including the
  Argentine mobile rules (`+549`, area code, the dropped `15`).
- **Sanitiser.** `local\sanitizer`: turns Moodle message content into a string Meta accepts as a template
  parameter. Strips newlines, tabs, control characters and runs of spaces, converts HTML to plain text through
  core, cuts on characters rather than bytes.
- **Recipient and opt-in.** `local\recipient` resolves a user into a phone number and a consent, reading the
  profile field named by `phonesource` the first time. `form\preferences_form` adds the phone field and the
  opt-in checkbox to the notification preferences of each user. Nothing is sent without both.
- **Queue.** `local\queue`: enqueue, claim with a status lock, exponential backoff to five attempts, quiet
  hours, daily cap per user, delivery status that only ever moves forward, and the signed click token used by
  the button of the template.
- **Template mapping.** `local\template_mapper` maps any notification onto the `moodle_notification` template:
  three body parameters in order (short site name, subject, summary), the language version chosen from the
  recipient's own language, and the destination URL. `local\mapped` is the value object it returns.
- **Transports.** `transport\transport_interface` and `transport\result`; `transport\meta_cloud`, which talks
  to the Graph API directly, with a classification of every Cloud API error code into permanent and retryable;
  `transport\factory`, `transport\unconfigured` (a transport that refuses and says why) and `transport\fake`
  (in memory, for Behat and manual testing).
- **Scheduled tasks.** `send_queue` every minute, `sync_status` every five minutes (gateway mode only),
  `cleanup` daily. `cleanup` deletes finished queue entries older than the `retention` setting along with the
  records of the clicks on their buttons, in bounded batches; entries still waiting to be sent are never
  deleted, and a retention of 0 keeps everything.
- **Webhook.** `webhook.php`: GET answers Meta's verification challenge, POST validates the
  `X-Hub-Signature-256` HMAC of the raw body against the app secret before doing anything at all, and applies
  the delivery status to the queue row. Anything it does not understand is answered 200.
- **Click redirection.** `go.php`: takes the signed token from the button, records the click and redirects. The
  destination comes from the queue row and must start with `wwwroot`, so it is not an open redirect.
- **Administration.** Sixteen settings in six blocks (mode, Meta credentials, recipients, template, sending,
  retention), with the three secrets in masked fields, and the webhook URL printed ready to paste into Meta. A
  *Test WhatsApp* page with *Test connection*, which asks Meta whether the credentials work without sending
  anything, and *Send a test to my number*, which queues a real notification.
- **Delivery report.** A reportbuilder system report at `report.php`, listing every notification the plugin
  handled with its status, attempts and error, filterable by status, recipient, component and date, with a
  retry action on failed rows. Behind `message/whatsapp:viewlog`, so a manager can read it without holding
  the capability that hands out the site's credentials.
- **Status page.** `status.php`, next to the report and behind the same capability: what the channel did today
  by outcome, what is still waiting, the age of the oldest waiting entry, and when the sending task last ran.
  It says so in red when something is waiting and that task has gone quiet for ten minutes, which is the only
  place on the site a stopped cron shows up as a stopped WhatsApp channel.
- **Undeliverable numbers are marked.** When Meta answers that a number cannot be delivered to, the recipient
  is marked invalid: nothing more is queued for it, the entries are recorded as skipped under a reason of their
  own, and the user is told in their notification preferences that WhatsApp could not deliver to that number.
  Saving a number in the preferences clears the mark. Only that one failure marks a recipient; every other
  permanent failure is about the site.
- **Capabilities.** `message/whatsapp:managesettings`, `message/whatsapp:viewlog`,
  `message/whatsapp:optinusers`.
- **Privacy.** A full Privacy API provider: exports and deletes the three tables by user, and declares Meta as
  an external location.
- **Documentation.** `README.md` rewritten as a complete setup guide: creating the Meta app, the exact token
  permissions, creating the `moodle_notification` template with your own domain in the button, the webhook, how
  a user opts in, the error codes, and how Meta bills. `docs/template.json` holds the exact template definition
  for both languages.
- Tests: PHPUnit suite and Behat features, green on Moodle 4.5 and 5.2.

### Fixed
- **The template body no longer starts and ends with a variable.** Meta rejects those: *"The message template
  cannot start or end with a parameter (dangling parameters are not allowed)"*
  ([Template review](https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/template-review),
  verified 13 September 2026). The body was `{{1}}: {{2}}` and `{{3}}`, which breaks the rule twice, so the
  first submission to Meta would have been rejected. `docs/template.json` now has fixed text before `{{1}}` and
  after `{{3}}` in both languages, and `README.md` no longer tells you to paste the old body into the WhatsApp
  Manager form. No code changed: `local\template_mapper` supplies three parameters in order and never read the
  wording around them, so a site is free to reword it — but not to remove it.

- **The two modes table no longer says the service holds the WhatsApp account and pays Meta for you.** It does
  neither. In gateway mode the account is yours, the number is yours, the messages go out under your name, and
  Meta bills you on the payment method in your own account; what the service charges for is the software. The
  table also claimed gateway mode needs no Meta account at all, which was never going to be true: it needs one,
  and the difference is that the service creates it with you and then operates it with permission you can
  withdraw. Adding the payment method and verifying the business stay with the account holder in both modes.

### Known limitations

- **Gateway mode is not implemented.** The *Sending mode* setting offers it and choosing it reports
  `not_available`.
- **The Meta test phone number reaches five recipients only.** A sixth receives nothing, with no error. A real
  pilot needs an own number and Meta's business verification.
- No phone number verification (no one time code). A wrong number fails silently or reaches a stranger.
- An undeliverable number (Meta error 131026) is written off for that message but is not marked invalid on the
  user's record, so the next notification for that person fails the same way. The number has to be corrected
  by hand.
- No inbound: a reply typed in WhatsApp goes nowhere.
- Personal messages between users are not sent; only notifications.
- One template for every notification. A per component template map is not implemented.
- `adminoptin` of the architecture is not implemented: there is no way for an administrator to opt other people
  in.

## [0.1.0-alpha] - 2026-09-12

Plugin skeleton. The plugin installs and is visible in the messaging settings, but it does not send anything.

### Added

- `version.php`: component `message_whatsapp`, requires Moodle 4.5 (2024100700), supported branches 4.5 and 5.2,
  maturity alpha.
- `message_output_whatsapp`: the message processor class, with every method returning a neutral value. No queue,
  no tables, no transport and no webhook yet.
- `settings.php`: the sending mode setting (direct or gateway). No credentials yet.
- English and Spanish language packs.
- Privacy null provider: this release stores no personal data.
- GitHub Actions workflow running moodle-plugin-ci on Moodle 4.5 (PHP 8.3) and Moodle 5.2 (PHP 8.4), against
  PostgreSQL and MariaDB.

### Known limitations

- Nothing is sent. `send_message()` discards the message.
- `is_user_configured()` always returns false, so no user is ever considered reachable.
