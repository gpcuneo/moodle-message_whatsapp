# Changelog

All notable changes to `message_whatsapp` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
