# Limitations and what this version does not do

For: the Moodle administrator.

Section topics: [Glossary](00-glossary.md) · [How it works](01-how-it-works.md) · [What you need](02-prerequisites.md) · [Service accounts](03-agora-accounts.md) · [The stream template, closed playback and the tag](04-agora-template.md) · [Installing and upgrading the plugin](05-install.md) · [Site settings](06-settings.md) · [The System status report](07-status-report.md) · [Background tasks and capabilities](08-tasks-and-permissions.md) · [The recycle bin and the event log](09-recycle-bin-and-log.md) · [Troubleshooting](10-troubleshooting.md) · [What the Agora operator sees](11-agora-console.md) · **Limitations and what this version does not do**

---

## Limitations worth knowing

- **Watching time includes pauses.** The Agora server merges consecutive
  sessions of the same person with a gap of up to 30 minutes into one, and the
  gap counts towards the duration. The "watched for at least M minutes" rule is
  computed from such merged sessions, so a learner who stepped away for ten
  minutes gets them credited. There is no way to tell a pause from watching —
  that is a property of the viewing history.
- **The end of watching is computed without `last_seen_at`.** The Agora history
  does not return the moment of the last request, so for a session closed as
  idle the plugin subtracts the Agora session TTL — 60 seconds, a number taken
  from the Agora server code. If Agora changes the TTL, the counted time will be
  off by the difference; once the history returns `last_seen_at`, the plugin
  switches to it on its own.
- **The learner identifier in Agora lives a week after their last ticket.** It
  contains the username and is only needed to ask for the viewing history; a
  week later the plugin wipes it. If the learner is renamed and later opens the
  session again, the identifier is built from the new username, and the history
  under the old one no longer counts for that session.
- **The thumbnail frame needs network from the Moodle server to the delivery
  nodes.** If the nodes are not reachable from the Moodle server, the cards
  show no frames and missing-frame events appear in the log. Viewing is not
  affected.
- **Without cron the state on the course page lags silently.** A stale value is
  served at once and the refresh is queued as a background task. If cron does
  not run, the badges keep the previous value for up to 15 minutes and then
  disappear. The activity page does not suffer from this — it reads the state of
  its own stream from the Agora server — but its sidebar stays without badges.
  The only outward sign is on the activity page: the "Agora: refresh of stream
  states" check turns red, and the teacher sees the "Broadcast states have not
  been refreshed for N min" line.
- **Polling by open pages grows with the audience.** Every open page without a
  live player asks the site every 15–20 seconds: a hundred pages make about six
  requests per second, a thousand about sixty. Each request is served from the
  cache and does not reach the Agora server.
- **The screens-per-ticket limit does not yet cut off viewing.** The Agora
  server closes the extra session, but a valid ticket reopens it on the next
  request. Treat the limit as a fact visible in the sessions, not a protection;
  the protections are the ticket lifetime and the address binding.

## What this version does not do

Every line is something the plugin does not do; no dates are promised.

- Uploaded video files (MP4) as a separate course activity.
- An LTI 1.3 connection.
- LMSs other than Moodle.
- A meeting room: chat, hands up, a learner broadcasting.
- The Agora media library and file storage in the Moodle file picker.
- A video assignment: the learner uploads nothing.
- Embedding an arbitrary video link.
- Creating learners in the accounts of the Agora server.
- Viewing rules for a single stream or course.
- Renewing the ticket from the player while watching.
- A numeric grade for watching.

---

← Previous: [What the Agora operator sees](11-agora-console.md)
