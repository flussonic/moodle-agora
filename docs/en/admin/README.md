# Video in Moodle courses: the Agora server

For: the Moodle administrator — prepare the Agora server, install the Agora
broadcast plugin, connect the site and keep the connection healthy.

Next to this: [for teachers](../teacher/README.md) — adding a broadcast to a
course; [for learners](../student/README.md) — how to watch.

Read in order: every topic builds on the previous one.

> The screenshots are taken on a Russian-language Moodle; the English interface
> carries the same pages in the same order. The site label on them is `stand`,
> so the names of the created streams start with it; your label will be your
> own and the prefix will change with it.

## Topics in this section

1. [Glossary](00-glossary.md) — six words you will meet below.
2. [How it works](01-how-it-works.md) — who decides who may watch, and why video does not go through Moodle.
3. [What you need](02-prerequisites.md) — access, network both ways and the same visible learner address.
4. [Service accounts](03-agora-accounts.md) — `player`, `admin` and `publisher` in the Agora console: why three, and what disabling them breaks.
5. [The stream template, closed playback and the tag](04-agora-template.md) — recording and thumbnails, requiring a ticket, the tag on the streams for courses.
6. [Installing and upgrading the plugin](05-install.md) — put the code into `mod/agora`, file permissions, the database upgrade.
7. [Site settings](06-settings.md) — server address, site label, accounts, ticket lifetime, comments, address binding, stream publication and ending after silence.
8. [The System status report](07-status-report.md) — the seven checks of the plugin: each names the reason and its settings field.
9. [Background tasks and capabilities](08-tasks-and-permissions.md) — two scheduled tasks and four capabilities of the plugin.
10. [The recycle bin and the event log](09-recycle-bin-and-log.md) — when a stream created from a course is deleted, and what the plugin logs.
11. [Troubleshooting](10-troubleshooting.md) — a legitimate learner refused, a missing cover, a recording that does not play, a lying badge.
12. [What the Agora operator sees](11-agora-console.md) — streams and sessions in the console, the learner identifier, service sessions.
13. [Limitations and what this version does not do](12-limits.md) — what to know in advance and what the plugin does not do.
