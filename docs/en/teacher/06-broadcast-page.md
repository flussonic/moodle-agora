# The activity page through your eyes

For: the teacher.

Section topics: [The course](01-course.md) · [Adding the Agora broadcast activity](02-add-broadcast.md) · [What to show, the start date and the description](03-display-mode.md) · [Completion](04-completion.md) · [The course page](05-course-page.md) · **The activity page through your eyes** · [Learners: enrolment and groups](07-learners.md) · [Unenrolment and what happens to the ticket](08-unenrol.md) · [The completion report](09-completion-report.md) · [Comments](10-comments.md) · [If it does not play for a learner](11-when-it-does-not-play.md)

---

The learner sees the player, the list of course broadcasts, the comments and
the cards of their other courses on this page — that is described in the
[learner document](../student/03-broadcast-page.md). You see the same and four
more things.

**The stream name on the Agora server.** Learners are not shown it — you need
it to talk to the operator.

**The "Open in the Agora console" button.** It leads to this stream in the
Agora console. Whoever may edit the course sees it; learners do not.

**Hidden and restricted activities in the list of course broadcasts.** A hidden
activity is invisible to the learner, while you see it dimmed with the Moodle
core label "Hidden from students"; an activity closed by an access restriction
has no link, a lock instead of the frame and the condition as its subtitle.
Such an activity becomes the target of another course card by the same rule as
any other: it has to be available to whoever is looking. A hidden broadcast is
not available to the learner and never becomes their target — but in a course
where you see hidden activities, it becomes yours.

The heading of the list carries the number "Broadcasts: N". The second number,
"Completed k of M", is shown to the learner only: completion is not counted for
you, so there is nothing to count.

**The "Broadcast states have not been refreshed for N min" line** — a dimmed
line above the player. It means the background task that keeps the badges and
frames of the neighbouring streams fresh has not run for over three minutes,
usually because cron is not running on the site. If the last run of the task
ended with an error, the line is different: "The last refresh of broadcast
states failed" — it appears at once, without waiting three minutes. While
either line is there, the neighbours lose their badges and frames. Learners are
not shown these lines; the Moodle administrator fixes them.

![The "The last refresh of broadcast states failed" line above the player](../../img/guide/09-teacher-refresh-failed.png)

In the screenshot the task failed because the "Agora server address" in the
site settings leads somewhere other than Agora — which is also why the player
shows the server's refusal instead of the broadcast. Once the administrator
fixes the address and the task runs again, the line goes away.

One more difference: the cards of other courses lead you to a running
broadcast, and when none is running, to the first broadcast of the course. The
hint under the card is there for you too — "Live: …" or "Next: …" — but for you
it names exactly where the card leads: the running broadcast or the first one
of the course. For the learner the same hint names the first broadcast they
have not completed, because completion is counted for them, not for you.

The badges and frames of the neighbouring broadcasts in the list come from the
cache only: the page never asks the Agora server about the neighbours. It does
ask about the open broadcast — for its state when what the site knows is older
than 15 seconds (one request per broadcast per 15 seconds, however many
learners open the page), plus the archive bounds when a recording is shown. If
you may edit the course, the playback policy is read as well — once a minute
for the whole site. Until the cache is warm the neighbours are drawn with no
badges and no frames; that is not an error.

An open page without a live player asks the site — not the Agora server —
every 15–20 seconds whether the broadcast has started, and swaps the
placeholder for the player on its own. If a recording is playing on the page it
is not cut off: a "The broadcast has started." bar with a "Go to the broadcast"
button appears above it.

![The "The broadcast has started. Go to the broadcast" bar above a recording](../../img/guide/10-student-live-banner.png)

And if open playback for everyone is on at the Agora server, you will see a
warning on this page: tickets protect nothing in that mode. The Moodle
administrator fixes that together with the Agora operator.

## Running the broadcast

An activity created from a template is the full life cycle of a session. The
learners see the broadcast only after you decide so, and the recording
starts at the same moment.

**The "How to broadcast" block** stays on the page until the broadcast ends.
By default it is collapsed behind the "Show address" button: neither the
address nor the key is present on the page. The button reveals the
connection string like `srt://<address>:<port>?passphrase=<key>` (the click
is logged without the key value), and "Hide address" collapses the block
back. The string is the
only thing that goes into the encoder: in OBS it is Settings → Stream →
Service "Custom", the string goes into "Server".
"Rotate key" generates a new one: the running broadcast breaks until you
enter the new string into the encoder — the button warns about that. Rotate
the key only if it could have leaked. Under the string — the port state:
"The port accepts connections" or "The port is not up: … — tell the
administrator": the second means something is configured wrong, not that you
have not switched the encoder on yet.

![The planned broadcast page with the "How to broadcast" block revealed](../../img/guide/06-teacher-publish-block.png)

**The signal is up, the learners do not see it.** As soon as the encoder
connects, the player spot shows you a live picture to check and the "Start
broadcast" button. The learners still see "The broadcast has not started":
the signal by itself does not let them in. Check the picture and the sound,
then press: the learner pages switch to the player on their own, and the
recording starts in Agora named after the activity. Without a signal the
button is inactive and explains what it waits for: "No signal yet: start
your encoder — the button lights up when the signal arrives".

**"End broadcast" is forever.** The button stops the recording, removes the
publication from the stream and opens the recording to the learners; there
is no second broadcast in this activity — the confirmation asks about
exactly that. On the ended activity the "Broadcast ended: …" line names the
way and the moment: "ended by the teacher", "ended automatically after the
signal went silent" or "ended automatically, the recording was closed by the
system" (a recording has a maximum duration — 8 hours by default). Next to
it a hint: a new session is a new activity, and this one can be duplicated.

**Ending after silence.** If the encoder is silent longer than the
threshold the administrator set (10 minutes by default), the scheduled task
ends the broadcast: the recording stops, the learners move to the recording,
and the ending reason on the page becomes "ended automatically after the
signal went silent". Until the threshold the learners see "signal lost" and
the broadcast continues — bring the encoder back. If the ending fails (the
network to Agora went down), the activity does not hang in limbo: the page
shows which step did not run, and it is retried by the same button or by the
scheduled task.

---

← Previous: [The course page](05-course-page.md) · Next: [Learners: enrolment and groups](07-learners.md) →
