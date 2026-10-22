# mod_agora — Agora broadcast

A Moodle activity module that shows an Agora live broadcast and its recording
inside a course. The learner watches in their Moodle session under a viewing
ticket issued for them personally; the video goes from the Agora delivery nodes
straight to the browser and never passes through Moodle. The one thing Moodle
does fetch itself is the JPEG preview frame of a card: the server asks Agora for
it under a service ticket, caches it and serves it to the browser through
`mod_agora_pluginfile`, checking the learner's access on every request.

## Requirements

- Moodle 4.5 LTS (`$plugin->requires = 2024100700`).
- PHP as required by the Moodle core (8.2 or 8.3 for 4.5).
- An Agora installation with two role accounts created by its superadmin:
  `player` — issues and revokes viewing tickets, and reads the archive bounds of
  a recording under the ticket it issued; `admin` — reads streams, templates,
  stream state, viewing history and the admission policy, and also writes:
  it creates a stream from a template and deletes a stream the plugin created.
- Network access from the Moodle server to the Agora server and to the delivery
  nodes, and from the learner browser to the Agora server and to the delivery
  nodes.

## Install

Unpack the plugin into `mod/agora` of the Moodle tree, open Notifications and
confirm the install, then fill in Site administration → Plugins → Activity
modules → Agora broadcast.

To build the distributable archive from a checkout (tests are not shipped):

```
cp -r <checkout> agora
zip -r mod_agora.zip agora -x '*/tests/*'
rm -rf agora
```

The directory inside the archive must be named `agora`, whatever the checkout
itself is called: Moodle installs an activity module by the directory name,
and the plugin only works at `mod/agora`. Zipping the checkout directory as it
is produces an archive Moodle rejects.

## Documentation

Three roles in two languages, one topic per file. The documentation lives next
to the plugin, in `docs/` at the repository root: the root index is
[`docs/README.md`](docs/README.md); the language indexes are
[`docs/ru/README.md`](docs/ru/README.md) and
[`docs/en/README.md`](docs/en/README.md).

- [`docs/ru/admin/README.md`](docs/ru/admin/README.md),
  [`docs/en/admin/README.md`](docs/en/admin/README.md) — preparing the
  Agora server, installing the plugin, the site settings and troubleshooting,
  for the Moodle administrator.
- [`docs/ru/teacher/README.md`](docs/ru/teacher/README.md),
  [`docs/en/teacher/README.md`](docs/en/teacher/README.md) — adding a
  broadcast to a course, enrolment and completion, for teachers.
- [`docs/ru/student/README.md`](docs/ru/student/README.md),
  [`docs/en/student/README.md`](docs/en/student/README.md) — how to watch,
  for learners.

Every section index lists its topics in reading order; each topic file carries
that index in its header and previous/next links at the foot.

The screenshots ([`docs/img/guide/`](docs/img/guide/)) are shared by all
three and are taken on a Russian-language Moodle.

Language packs shipped: `en`, `ru`.

## Tests

PHPUnit and Behat live in `tests/`. Agora is replaced by a stub in both: a
mocked HTTP client for PHPUnit (`tests/fixtures/mock_central.php`) and a PHP
stub of the Agora server for Behat (`tests/fixtures/fake_central.php`).

```
vendor/bin/phpunit --testsuite mod_agora_testsuite
vendor/bin/behat --config <behatdir>/behat/behat.yml --profile chrome --tags @mod_agora
```

## Licence

GNU General Public License v3 or later —
<https://www.gnu.org/licenses/gpl-3.0.html>. Every PHP file and the AMD source
`amd/src/watch.js` carry the standard Moodle GPL header; the remaining files
(`styles.css`, `pix/*.svg`, `db/install.xml`, `.phpcs.xml.dist`, the built
`amd/build/watch.min.js`, the Behat `.feature` files) carry no header. There is
no separate `LICENSE` file.

Copyright 2026 Flussonic.
