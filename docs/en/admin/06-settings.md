# Site settings

For: the Moodle administrator.

Section topics: [Glossary](00-glossary.md) · [How it works](01-how-it-works.md) · [What you need](02-prerequisites.md) · [Service accounts](03-agora-accounts.md) · [The stream template, closed playback and the tag](04-agora-template.md) · [Installing and upgrading the plugin](05-install.md) · **Site settings** · [The System status report](07-status-report.md) · [Background tasks and capabilities](08-tasks-and-permissions.md) · [The recycle bin and the event log](09-recycle-bin-and-log.md) · [Troubleshooting](10-troubleshooting.md) · [What the Agora operator sees](11-agora-console.md) · [Limitations and what this version does not do](12-limits.md)

---

Once per site. **Site administration → Plugins → Activity modules → Agora
broadcast**.

![Agora broadcast settings right after the install](../../img/guide/04-settings-empty.png)

## The Agora server address

The "Agora server address" field is a scheme, a host and a port **with no
path** — for example `https://agora.example.org` or
`https://agora.example.org:8443`.

Three things about this address:

- **It is one for everyone.** Both the Moodle server (for stream lists and
  tickets) and the learner browser (for the player page) go to the Agora server
  at this address. An internal address visible only from the server network
  will not do.
- **Where to get it.** It is the same address you open the Agora console at.
  Ask the Agora operator if the console is not available to you.
- **The port.** If Agora listens on a non-standard port, write it explicitly:
  without it the browser and the server go to 443 (or 80) and do not get
  through.

## Every field

| Field | What to write |
|---|---|
| Agora server address | Scheme, host and port with no path |
| Site label | Latin letters, digits, `_ . -`, 1–40 characters, e.g. `hr-lms` |
| Login of the `player` account | The account from [Service accounts](03-agora-accounts.md) that issues tickets |
| Password of the `player` account | Its password |
| Login of the `admin` account | The second account from [Service accounts](03-agora-accounts.md), the one that reads streams |
| Password of the `admin` account | Its password |
| Default template | The template name from [The stream template](04-agora-template.md#the-stream-template); checked against the server list |
| Ticket lifetime | 10 minutes to 24 hours, 2 hours by default |
| Screens per ticket | 1–10, 2 by default |
| Bind the ticket to an address | On by default, see [Binding the ticket to an address](#binding-the-ticket-to-an-address) |
| Allow streams without the tag | Off by default |
| Comments under the player | On by default, see [Comments under the player](#comments-under-the-player) |
| Tombstone period without the recycle bin, days | 1–3650, 7 by default; see [The recycle bin and the event log](09-recycle-bin-and-log.md) |
| End a broadcast after silence, seconds | 60–86400, 600 (10 minutes) by default; see [Ending after silence](#ending-after-silence) |
| Publication port range | Start and end, whole ports 1–65535; see [Publishing streams from courses](#publishing-streams-from-courses) |
| Publish address (fallback) | Only for the case where the publish address is not configured in Agora itself |

![The settings filled in](../../img/guide/04-settings-filled.png)

Passwords are stored encrypted: the page only shows "set" or "not set", and
they cannot be read back.

Five notes on the fields that are expensive to change later or easy to
misread.

**Do not change the site label later.** It goes into the `moodle.<label>` tag,
into the names of streams created from courses and into the person identifier
passed to the Agora server (`label:number:login`). Changing it changes the
identity of everyone in the viewing history and hides every previously tagged
stream from teachers.

**Set the ticket lifetime to the length of a session, with some slack.** The
player does not renew the ticket. The lifetime is the only limit in the cases
where access disappeared without a Moodle event: an enrolment that ran out by
date (and is configured to keep the enrolment rather than unenrol), a suspended
person, a hidden course or activity.

**Screens per ticket** is how many windows may watch under one ticket. Two by
default — so that a learner who opened the course in two tabs does not lock
themselves out. Over the limit the Agora server closes the oldest session.
Treat it as a convenience, not a protection: see
[Limitations and what this version does not do](12-limits.md).

**Allow streams without the tag** is off by default: only a stream carrying the
`moodle.<label>` tag can be bound to an activity
([The tag on the streams that go into courses](04-agora-template.md#the-tag-on-the-streams-that-go-into-courses)).
Switch it on only if you are ready to show teachers every stream of the server.

**Bind the ticket to an address** is on by default: the ticket only plays from
the address the learner opened the activity from, so a link forwarded to a
colleague does not work. It requires the same visible address on both sides —
see [Binding the ticket to an address](#binding-the-ticket-to-an-address).

## Comments under the player

The page carries the standard Moodle comment area bound to the activity. For
the learner to see the new comment field all four conditions must hold:

1. comments are on for the site — `usecomments` (Advanced features);
2. the plugin setting **Comments under the player** is on;
3. the role has the plugin capability `mod/agora:comment` (granted by default
   to the student, the teacher and the manager; risk `RISK_SPAM`);
4. the role has the standard `moodle/comment:post`.

Removing any of the last three gives the same screen: the feed is there, the
new comment field is not. A guest never comments: core does not grant them
`moodle/comment:post`. The feed is read by those the activity is available to
who also hold `moodle/comment:view`.

Switching the plugin setting off removes the area from the page entirely and
refuses a direct add request with the `commentsoff` code — the switch is not
decorative. There is no per-stream setting.

Someone else's comment is deleted by whoever holds the standard
`moodle/comment:delete` (the teacher by default). The whole site list is in
**Site administration → Reports → Comments**. Comments are included in the
course backup and restored together with the activity, and in the privacy API
they are counted, exported and deleted by the core comments subsystem.

## Publishing streams from courses

The teacher runs the broadcast alone: from OBS or another encoder over SRT.
(In-browser broadcasting over WHIP is temporarily hidden — frozen
2026-10-16: the activity page shows no button, the publisher page is
reachable only at its direct address, and the `publisher`, publish-ticket
and WHIP settings are hidden from this page; the mechanics below are
documented for when it is unfrozen.)
For this, every stream created from a course
carries a publication section: a port and a key. The port is picked
automatically from the **publication port range** — each new stream gets the
first free port, and ports occupied by other streams of the cluster
(including ones the operator created outside the plugin) are skipped. The
range is a cluster resource: it must not overlap with other Moodle sites
pointed at the same Agora, otherwise a port occupied by one site is reported
to both, and creating an activity refuses with advice to move the range. A
port is released only when the broadcast is ended or the activity is
deleted — while it is taken, it is visible in the "Publication is set up"
check.

The connection string the teacher pastes into the encoder is built from the
publish address and the port. The publish address is read from the Agora
server (the cluster setting `publish_address`, set by the operator); the
**"Publish address (fallback)"** field is used only when the server did not
return one.

The third account, `publisher`, issues **publish tickets** for in-browser
broadcasting over WHIP: the teacher's browser gets such a ticket on the
publisher page and sends the signal with it. The ticket lives for the
**"Publish ticket lifetime"**; the open publisher page renews it after half
of the lifetime, so a long session is not cut by expiry. When the broadcast
ends — by button, by silence, or by deleting the activity — the publish
tickets are revoked: in-browser broadcasting stops, because the publication
section is gone.

The **"WHIP address of the ingest node"** is the base address the browser
sends publication to (`<address>/streaming/whip/<stream>`). It is the ingest
node address, not the console; ask the Agora operator. Without it in-browser
broadcasting does not work, while the "How to broadcast" block with SRT
does.

In-browser broadcasting requires HTTPS: without it the browser refuses
camera and microphone access, and the "Broadcast from the browser" button
says so. Encoder publishing over SRT does not depend on HTTPS.

## Ending after silence

A broadcast that has been declared ("Start broadcast" pressed) and lost its
signal is ended by the scheduled task once the silence lasts longer than the
"End a broadcast after silence" threshold. The default is 600 seconds (10
minutes), anything from 60 seconds to 24 hours is allowed. Until the
threshold the learners see "signal lost" and the broadcast continues; past
the threshold the recording is stopped, the publish tickets are revoked and
the activity moves to the recording with the ending reason "ended
automatically after the signal went silent".

Silence **before** the broadcast is declared ends nothing: until the "Start
broadcast" button is pressed, the activity lives in the "planned" phase as
long as needed. The threshold exists only here, at the site level; there is
no per-activity setting.

## Binding the ticket to an address

On by default. The ticket only plays from the address the learner opened the
activity from: a video link forwarded to a colleague is refused by the delivery
node with `403` when it comes from another address. The plugin takes the
learner address from Moodle (`getremoteaddr()`) and puts it into the ticket.

**The address must match on both sides.** The most common trap: Moodle sits
behind NAT or a reverse proxy and the delivery nodes do not (or the other way
round). Moodle then sees the proxy address, the node sees the real one, they do
not match, and the refusal hits the legitimate learner. There is one check:
compare the address recorded in the ticket with the address of that person's
session in the Agora console. If they differ, configure trusted proxies **on
both sides**: `reverseproxy` and `getremoteaddrconf` in Moodle, the trusted
proxy list on the delivery nodes.

The other cases:

- **A shared exit to the network (NAT).** Learners of one office come from one
  address: the binding neither hurts them nor protects — inside the office the
  link still plays.
- **The address changes mid-session** (Wi-Fi to mobile). Viewing does not
  break: on the next opening of the activity the plugin revokes the old ticket
  and issues a new one for the new address. A link forwarded earlier dies with
  that revocation.
- **How to turn it off.** Clear the "Bind the ticket to an address" checkbox.
  It applies to new tickets; the ones issued earlier stay bound until the end
  of their lifetime — no longer than the ticket lifetime setting.
- **An unknown address.** If Moodle cannot parse the address of the request (a
  broken header from a proxy), the ticket is issued without a binding and the
  event log gets "Ticket issued without address binding" with the address as it
  came: viewing without the protection beats a ticket that plays for nobody.

---

← Previous: [Installing and upgrading the plugin](05-install.md) · Next: [The System status report](07-status-report.md) →
