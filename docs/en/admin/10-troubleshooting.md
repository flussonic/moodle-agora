# Troubleshooting

For: the Moodle administrator.

Section topics: [Glossary](00-glossary.md) · [How it works](01-how-it-works.md) · [What you need](02-prerequisites.md) · [Service accounts](03-agora-accounts.md) · [The stream template, closed playback and the tag](04-agora-template.md) · [Installing and upgrading the plugin](05-install.md) · [Site settings](06-settings.md) · [The System status report](07-status-report.md) · [Background tasks and capabilities](08-tasks-and-permissions.md) · [The recycle bin and the event log](09-recycle-bin-and-log.md) · **Troubleshooting** · [What the Agora operator sees](11-agora-console.md) · [Limitations and what this version does not do](12-limits.md)

---

The order is always the same: first the
[System status report](07-status-report.md), then the
[Moodle log](09-recycle-bin-and-log.md#the-event-log), and only then the
network.

Every check names, when it fails, the reason and the settings field that fixes
it: "network", "login or password refused", "the role does not grant the
right", "the licence does not include accounts", "too many sign-ins", "Agora
failed on its side", "the answer is not understood"
([The System status report](07-status-report.md)).

The **"Agora: refresh of stream states"** check turns red when the background
task has not run for over three minutes. Its "fix it" link goes to **Server →
Scheduled tasks**: that is where it is fixed, not in the plugin settings. The
most common cause is that cron does not run on the site.

## A learner is refused although they are enrolled

Almost always this is the address binding behind a proxy or NAT
([Binding the ticket to an address](06-settings.md#binding-the-ticket-to-an-address)).

Moodle sees the proxy address and the delivery node sees the real address of
the learner. They do not match, and the node refuses a legitimate learner.

The check: compare the address recorded in the ticket with the address of that
person's session in the **Sessions** section of the Agora console. Different
addresses mean this is it.

The fix is trusted proxies **on both sides**: in Moodle the `reverseproxy` and
`getremoteaddrconf` parameters, on the delivery nodes the trusted proxy list. A
temporary measure is to clear the "Bind the ticket to an address" checkbox:
switching it off applies to new tickets, the ones issued earlier stay bound
until the end of their lifetime.

## No cover appears on the card

Three causes, in order of frequency:

1. **The stream template has no thumbnails section**
   ([The stream template](04-agora-template.md#the-stream-template)). The card
   is shown without a cover and an event "Preview frame is missing" goes into
   the Moodle log with status `404`, reason `http` and the words "the stream
   template has no thumbnails section".
2. **The Moodle server cannot see the delivery nodes.** The frame is not taken
   from the Agora server directly: it answers with a redirect to a node and the
   Moodle server follows. With no network to the nodes the log carries status
   `0`, reason `network`.
3. **The node cannot serve a frame** — this happens on trimmed builds of the
   server. From outside it looks like "the node does not answer".

The other causes are rarer; each event describes its own cause, the step
(`step: the frame request to the delivery node` — the frame
itself at the node, `step: the service ticket from the Agora server` — issuing
the service ticket) and what fixes it, so read the description, not only the
code. Reason `network` at the ticket step is not the node but the Agora server
itself:

- status `401` or `403`, reason `http` — the node refused the service ticket;
  the plugin drops that ticket and the next page view issues a new one;
- status `401` or `403`, reason `password` or `role` — the service ticket for
  the frame was not issued: check the `player` account in the site settings;
- reason `license` — the Agora licence does not include accounts;
- reason `ratelimited` — too many sign-ins to Agora; passes within a minute;
- reason `busy` — another request of this Moodle was issuing the service
  ticket and did not finish in time; not a refusal by Agora;
- reason `badresponse` or `notfound` — the Agora server answered the ticket
  request with something that is not its API: usually a proxy page or another
  service at the "Agora server address";
- reason `notconfigured` — the site label, the Agora server address or the
  `player` account is not set.

**Timeouts.** The browser asks for each card's frame in a separate request, and
every such request occupies a PHP worker. The frame request therefore has its
own, shorter timeouts: 3 seconds to connect and 5 seconds for the frame request
itself, all redirects to the delivery node included — every redirect gets only what is
left of the five seconds. An unreachable or stalled node ends as "no frame" on
the fifth second instead of holding workers for twenty (an
unreachable node ends after 3 seconds per card, and the course page does not
depend on it at all). The 5 seconds do not include the check of the node name
against the Moodle blocklist ("cURL blocked hosts" — the name is resolved before
the request) nor issuing the service ticket before the frame: those have their
own limits.

The failure is never swallowed: it is remembered as "no frame" for 60 seconds
(the next page view does not call the node) and goes into the event log — one
event per stream and reason per hour. A new reason within the hour is logged
at once: an old `404` does not hide a node that has just stopped answering.

In every case viewing is unaffected — only the cover suffers.

## The recording does not play

- **"This broadcast was not recorded"** — recording is off in the stream
  template ([The stream template](04-agora-template.md#the-stream-template)),
  or there was never any signal. A recording is considered to exist only once
  the archive has actually been written.
- **The recording is gone although it was there** — check the storage depth in
  the template: old data is evicted from the archive by age.
- **The activity is in "Broadcast only" mode** — the recording is deliberately
  not shown to the learner; that is the teacher's choice in the activity form.
- **The operator deleted the stream** — the recording went with it. The "Agora:
  refresh of stream states" check shows how many of the plugin streams are
  already gone from the server.

## The state badge lies or disappeared

The states are kept by the plugin background task
([Background tasks](08-tasks-and-permissions.md#background-tasks)). If cron
does not run on the site, the badges live on the previous value for up to 15
minutes and then disappear. Check **Server → Scheduled tasks**: the last run
time of the plugin tasks.

The teacher in that case sees a dimmed line above the player, "Broadcast states
have not been refreshed for N min", or, if the last run of the task failed,
"The last refresh of broadcast states failed". Learners are not shown these
lines. The badge on the course page has no such outward sign — only the last
run time of the task.

![The activity page for the teacher: the last run of the task failed](../../img/guide/09-teacher-refresh-failed.png)

The reason for the failed run is named by the "Agora: refresh of stream states"
check in the System status report: the summary "The last pass failed", with the
text of the refusal in the details. In the screenshot the task ran against an
"Agora server address" that leads to another service
([what this looks like in the report](07-status-report.md)). Once the field is
fixed, the line goes away with the next run of the task — no need to wait three
minutes.

The activity page itself reads the state of its stream from the Agora server
when what it knows is older than 15 seconds; with the Agora server unreachable
it shows the previous value, and with an empty cache "The state of the
broadcast could not be read" with a reason code. The `AG-BUSY` code means that
another request for the same stream is already under way and takes longer than
two seconds — that is, the Agora server answers slowly.

The same `AG-BUSY` code can come from the service ticket of a stream — the
Moodle server reads the thumbnail frame and the bounds of a recording with it.
One request per stream issues it under a lock; the others wait up to 5 seconds.
If they do not get it in time, the recording opens without bounds, the card
has no frame, and the event log gets a `Moodle did not get to 'issue the service
ticket of a stream (opissueservice)' … (not a refusal by Agora)` record. This is
not a refusal by the Agora server: another request was issuing the ticket for
longer than 5 seconds — usually because the Agora server is slow to issue
tickets. The next page view takes the ticket already issued.

## No "watched for at least M minutes" marks

Open **Server → Scheduled tasks → Logs**, the "Agora broadcast: background
pass" task, and find the lines for the activity (the table of lines is in
[Background tasks](08-tasks-and-permissions.md#background-tasks)):

- `viewing history unavailable (…): 403` — the `admin` account lacks the right
  to read the viewing history;
- `no sessions for user_id, but a live ticket exists: jti=…` — the learner
  opened the page but did not play the video;
- `threshold reached, activity waits for other completion conditions` — enough
  minutes, the activity has another condition;
- `threshold reached, learner not tracked (unenrolled or suspended)` — the
  learner is unenrolled or their enrolment is suspended: no mark is set;
- `completion is off in the course` — switch tracking on in the course, the
  next pass sets the marks;
- `completion mark (…) not set: …` — a failure on the site; the seconds are
  saved and the next pass retries the mark on its own.

A counted time one minute shorter than the session length in the Agora console
is expected: the minute Agora waits before closing an abandoned session is not
counted. The exception is a session closed within that minute by the broadcast
stopping or the ticket being revoked (for example, the learner changed address):
its minute is counted.

---

← Previous: [The recycle bin and the event log](09-recycle-bin-and-log.md) · Next: [What the Agora operator sees](11-agora-console.md) →
