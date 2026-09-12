# WhatsApp message output for Moodle (`message_whatsapp`)

A Moodle *message output* plugin that delivers Moodle notifications over WhatsApp.

> **Status: alpha (0.1.0-alpha). This release is a skeleton and sends nothing.**
> The plugin installs, appears under *Site administration > General > Messaging > Notification settings*
> and exposes its sending mode, but there is no queue, no phone number handling and no transport yet.
> Do not install it on a production site.

## What it will do

Moodle notifications (assignment due, forum post, badge awarded, ...) are handed to this output, which writes
them to a database queue. A scheduled task drains that queue and delivers each notification through WhatsApp as
an **approved message template** (Meta only allows free text inside the 24 hour customer service window, which a
Moodle notification almost never falls into).

Two sending modes:

| Mode | What the site needs | Cost |
|---|---|---|
| **Direct** | Its own Meta Cloud API credentials (WABA, phone number id, token) | Free plugin, the site pays Meta directly |
| **Gateway** | An API key of the WhatsApp gateway service | Paid service: shared number, templates, delivery reports |

Both modes share the same queue, opt-in, sanitising and reporting code. Only the transport changes.

Only notifications are sent (`notification == 1`); personal messages between users are out of scope. Nothing is
ever sent to a user who has not explicitly opted in with a valid phone number.

## Requirements

- Moodle 4.5 LTS (build 2024100700) or Moodle 5.2 LTS.
- PHP 8.1 to 8.3 on Moodle 4.5; PHP 8.3 to 8.4 on Moodle 5.2.
- No Composer dependencies: all HTTP goes through Moodle's own `\core\http_client`.

## Installation

Install as any other Moodle plugin, in the `message/output/whatsapp` directory.

```bash
# Moodle 4.5
git clone https://github.com/gpcuneo/moodle-message_whatsapp.git \
    /path/to/moodle/message/output/whatsapp

# Moodle 5.2 (the code moved under the public/ directory in Moodle 5.1)
git clone https://github.com/gpcuneo/moodle-message_whatsapp.git \
    /path/to/moodle/public/message/output/whatsapp
```

Then finish the installation from *Site administration > Notifications*, or from the command line:

```bash
php admin/cli/upgrade.php
```

Enable the output in *Site administration > General > Messaging > Notification settings* and choose a sending
mode in the plugin settings.

Uninstalling from *Site administration > Plugins > Plugins overview* removes the plugin and its data.

## Privacy

A phone number is personal data. This alpha release stores none: it declares itself as a privacy *null
provider*. As soon as the plugin stores phone numbers, opt-in and queued messages it ships a full Privacy API
provider that exports and deletes them, and that declares Meta and the gateway as external locations.

## Development

The development environment (Moodle 4.5 and 5.2 in Docker), the architecture document and the task plan live in
the parent workspace. Continuous integration runs
[moodle-plugin-ci](https://github.com/moodlehq/moodle-plugin-ci) on both supported branches, Moodle 4.5 on PHP
8.3 and Moodle 5.2 on PHP 8.4, against PostgreSQL and MariaDB: `phplint`, `phpcs`, `phpdoc`, `validate`,
`savepoints`, `mustache`, `grunt`, `phpunit` and `behat`.

## Licence

GPL v3 or later. See [LICENSE](LICENSE).

Copyright 2026 Guillermo Cuneo.

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General
Public License as published by the Free Software Foundation, either version 3 of the License, or (at your
option) any later version. This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
General Public License for more details.
