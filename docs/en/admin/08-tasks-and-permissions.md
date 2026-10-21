# Background tasks and capabilities

For: the Moodle administrator.

Section topics: [Glossary](00-glossary.md) · [How it works](01-how-it-works.md) · [What you need](02-prerequisites.md) · [Service accounts](03-agora-accounts.md) · [The stream template, closed playback and the tag](04-agora-template.md) · [Installing and upgrading the plugin](05-install.md) · [Site settings](06-settings.md) · [The System status report](07-status-report.md) · **Background tasks and capabilities** · [The recycle bin and the event log](09-recycle-bin-and-log.md) · [Troubleshooting](10-troubleshooting.md) · [What the Agora operator sees](11-agora-console.md) · [Limitations and what this version does not do](12-limits.md)

---

## Background tasks

**Server → Scheduled tasks.** The plugin adds two scheduled tasks.

**"Agora broadcast: background pass"** — every 10 minutes by default. It
expires stale tickets and removes their rows a day later, counts the watching
time for the completion rule, and deletes streams created from courses whose
deletion deadline has come.

The task handles completion by minutes in two steps. First it reads the viewing
history and saves the collected seconds: time counts from opening the player to
its last request to the Agora server, and the minute Agora waits before closing
an abandoned session is subtracted. Then it sets the marks — for every
"learner and activity" pair that has reached the threshold and has no mark,
including those whose mark a previous pass failed to set. The task log is under
**Scheduled tasks → Logs**.

Below are all the lines the plugin tasks write, in the order of a pass. The
task log is in English, like the core tasks.

**"Agora broadcast: background pass"**

| Task log line | Meaning |
|---|---|
| `expired tickets closed: N` | tickets past their expiry are marked closed and their JWT is wiped |
| `closed ticket rows deleted: N` | ticket rows older than a day past expiry are deleted |
| `no activities with a watching-time condition` | nothing to count: no activity has the "watched for at least M minutes" condition |
| `history window F-T is full (N sessions), splitting in half (stream)` | the history answered with 500 or more merged sessions; the window is asked in halves |
| `viewing history unavailable (stream, userid Y): code …` | the Agora server refused — or the history did not fit into 10 pages, or the server returned the same page cursor twice (the reason is in the line); the pair count and the poll boundary are left alone, marks from the seconds already saved are still set |
| `activity X: pairs without an Agora user_id, history not asked: N; the next ticket of the pair restores it` | the pair has no identifier in Agora (the upgrade step could not build it: no site label, or the user is deleted); the next ticket of the pair builds it |
| `no sessions for user_id, but a live ticket exists: jti=… (stream, userid Y)` | the learner opened the page but did not play the video |
| `pair (activity X, userid Y) skipped: …` | a failure on one pair; the pass goes on |
| `activity X: N sessions, S s counted, T s of idle time after the last request left out` | how much was counted from the history and how much idle time was left out |
| `activity X: completion is off in the course, N pairs reached the threshold, no marks set` | the marks come once tracking is switched on |
| `completion mark (activity X, userid Y) not set: …` | a failure on one pair's mark; the seconds are saved |
| `activity X: completion marks not set for N pairs: …` | a failure once the pairs were found (for example, the course could not be read) |
| `activity X: completion marks not set: …` | a failure before the pairs were selected |
| `watching-time completion marks set: N` | the marks were set |
| `threshold reached, activity waits for other completion conditions: K` | the activity has another condition, such as "view" |
| `threshold reached, learner not tracked (unenrolled or suspended), no mark: K` | unenrolled and suspended learners get no mark |
| `completion marks failed: K; seconds are saved, the next pass retries` | counted per pair; the next pass retries without asking the history again |
| `activities whose marks failed before the pairs were known: K; the next pass retries` | the number of pairs is unknown; the next pass retries |
| `Agora user references forgotten after the tail window: N` | the pair's `user_id` (with the username) is wiped a week after its last ticket |
| `stream NAME could not be deleted: code …` | a tombstone came due but the Agora server refused; the next pass retries |
| `streams deleted by tombstones: N` | streams of activities removed from courses were deleted in Agora |

**"Agora broadcast: refresh of stream states"** and one-off refreshes

| Task log line | Meaning |
|---|---|
| `no activity streams, nothing to refresh` | the site has no Agora broadcast activities; the pass counts as a success |
| `stream states not refreshed: code …` | the Agora server refused; old values live until the freshness window ends |
| `stream states refreshed: N, missing in Agora: M, no answer: K` | the outcome of the pass; "missing in Agora" means the operator deleted the stream |
| `stream state NAME refreshed: state` | a one-off task refreshed one stale value |

**Plugin upgrade** (`admin/cli/upgrade.php`, version 2026092507), only when there
are such rows:

| Output line | Meaning |
|---|---|
| `pair rows without tickets given an age: N, of them given user_id from the current username: K, left without user_id: L` | old pairs got an age back; `user_id` was built as `<site label>:<id>:<username>`; without a site label or for a deleted user it is not guessed, and the task will not ask the history for such a pair |

The task polls a pair and sets its mark for a week after the pair's last
ticket. This is tracked in the plugin's own record of the pair, not in the
tickets: closed tickets are deleted a day after they expire. A week later the
pair's `user_id` is wiped; the collected seconds stay. The pair rows of a
deleted user are deleted at once.

**"Agora broadcast: refresh of stream states"** — every minute by default. It
reads the state of every stream bound to an activity from the Agora server with
paged lists (100 at a time) and stores it in the cache. The sidebar of the
activity page takes the badges and the frames of the neighbouring streams from
that cache and never calls the Agora server itself, so without this task the
neighbours lose their badges and frames after three minutes. The outcome of
every pass is kept in the plugin settings — the time, the number of refreshed
streams and the text of the last error; the "Agora: refresh of stream states"
check reads the same values.

The same task ends broadcasts after silence: an activity in the running phase
whose signal has been gone longer than the "End a broadcast after silence"
threshold is brought to completion with the same actions as the "End
broadcast" button (stop the recording, remove the publication, set the
"ended" phase), and a completion that failed earlier is retried until it is
confirmed. Details: [Site settings → Ending after silence](06-settings.md#ending-after-silence).

On top of these the plugin queues one-off **"Agora broadcast: stream state
refresh"** tasks: when a cached stream state goes stale, the course page serves
the previous value and the refresh goes to the background. If cron does not
run, the states on the cards get stuck — that is the first thing to check when
someone reports a wrong badge.

The activity page itself does not depend on cron: when the state of its stream
is older than 15 seconds it reads it from the Agora server on its own — under a
per-stream lock, so the Agora server gets at most one such request per stream
per 15 seconds, however many learners arrive at the start. An open page without
a live player asks the site every 15–20 seconds through the
`mod_agora_get_stream_state` external function (same rights as the page; it
does not extend the session) and swaps the placeholder for the player on its
own. A hundred such open pages make about 6 requests per second to Moodle, each
served from the cache; during the broadcast the pages with a player do not ask
at all.

The plugin has no schedule setting of its own — the schedule lives only here.

## Capabilities

The plugin adds four capabilities.

| Capability | What it grants | Default roles |
|---|---|---|
| `mod/agora:addinstance` | add an Agora broadcast to a course | editing teacher, manager |
| `mod/agora:view` | watch a broadcast | student, teacher, manager |
| `mod/agora:comment` | write comments under the player | student, teacher, manager |
| `mod/agora:broadcast` | run a broadcast: the "How to broadcast" block with the key, the "Start broadcast" and "End broadcast" buttons, the in-browser publisher (currently hidden) | teacher with and without editing rights, manager |

`mod/agora:broadcast` is the right to run, not the right to edit: a teacher
without course editing rights broadcasts just the same. Every entry point
(revealing the key, rotating it, both buttons, the publish ticket, the
publisher page) checks this capability on its own: without it the action is
refused without a single call to Agora, and the block and the buttons are
not rendered. A learner never sees the "How to broadcast" block or the key.

On top of these the plugin relies on core capabilities: `moodle/comment:post`
(write a comment), `moodle/comment:view` (read the feed) and
`moodle/comment:delete` (delete someone else's). Details in
[Comments under the player](06-settings.md#comments-under-the-player).

The plugin has no separate "manage the connection" capability: the site
settings belong to whoever administers the site.

---

← Previous: [The System status report](07-status-report.md) · Next: [The recycle bin and the event log](09-recycle-bin-and-log.md) →
