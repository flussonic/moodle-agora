# What you need

For: the Moodle administrator.

Section topics: [Glossary](00-glossary.md) · [How it works](01-how-it-works.md) · **What you need** · [Service accounts](03-agora-accounts.md) · [The stream template, closed playback and the tag](04-agora-template.md) · [Installing and upgrading the plugin](05-install.md) · [Site settings](06-settings.md) · [The System status report](07-status-report.md) · [Background tasks and capabilities](08-tasks-and-permissions.md) · [The recycle bin and the event log](09-recycle-bin-and-log.md) · [Troubleshooting](10-troubleshooting.md) · [What the Agora operator sees](11-agora-console.md) · [Limitations and what this version does not do](12-limits.md)

---

This document assumes you already have a **working Agora server** and a
**working Moodle 4.5 LTS site** (with the PHP version the core requires).
Everything else is described here step by step.

Collect four things in advance.

**Moodle administrator access.** The right to administer the site, access to
the file system of the Moodle server (to put a directory into `mod/`) and the
ability to run the database upgrade.

**Agora operator access.** An Agora console account with the `superadmin`
role — only it can create new accounts. A lower role cannot create the service
accounts the plugin needs.

**Access to the plugin repository.** The address of the `mod_agora` git
repository (or an archive of its directory) — you take the code from it in
section [Installing and upgrading the plugin](05-install.md).

**Network both ways.** You need access:

| From | To | What for |
|---|---|---|
| Moodle server | Agora server | lists of streams and templates, tickets, stream states, viewing history |
| Moodle server | Delivery nodes | the thumbnail frame for the activity card and the recording bounds (start and end of the archive) — without them the recording of a finished broadcast opens at the live edge, where nothing plays; the Agora server answers both requests with a redirect to a node |
| Learner browser | Agora server | the player page |
| Learner browser | Delivery nodes | the video itself; media bypasses Moodle — the server does not proxy it |

A *delivery node* is a server the viewer takes the video from. There may be
several, and they are often different addresses and different firewall rules
than the Agora server itself.

**The same visible learner address on both sides.** By default the ticket is
bound to the learner address. The address Moodle sees and the address the
delivery node sees must match. If a proxy or NAT sits between the learner and
one of the sides but not the other, the refusal hits the legitimate learner.
How that is fixed — section [Troubleshooting](10-troubleshooting.md).

---

← Previous: [How it works](01-how-it-works.md) · Next: [Service accounts](03-agora-accounts.md) →
