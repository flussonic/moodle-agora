# If it does not play for a learner

For: the teacher.

Section topics: [The course](01-course.md) · [Adding the Agora broadcast activity](02-add-broadcast.md) · [What to show, the start date and the description](03-display-mode.md) · [Completion](04-completion.md) · [The course page](05-course-page.md) · [The activity page through your eyes](06-broadcast-page.md) · [Learners: enrolment and groups](07-learners.md) · [Unenrolment and what happens to the ticket](08-unenrol.md) · [The completion report](09-completion-report.md) · [Comments](10-comments.md) · **If it does not play for a learner**

---

A short checklist. The details belong to the Moodle administrator.

1. **Are they enrolled and is the activity available to them.** Check the
   participants page: the role must be there. Unenrolled and suspended people
   get no video.
2. **Is the broadcast running.** Look at the badge on the course page. "Not
   started" and "Planned" mean the teacher has not declared the broadcast: the
   signal may be up while the learners do not see it, until "Start broadcast"
   is pressed. "No recording" means the broadcast is over and was not recorded:
   the recording is switched on by "Start broadcast", and for operator streams
   — in the template.
3. **The display mode.** In "Broadcast only" mode there is deliberately no
   recording.
4. **They opened a link somebody sent them.** Such a link does not play: the
   ticket is bound to the learner and to their address.
5. **Their network changed** (Wi-Fi to mobile). Ask them to reload the activity
   page — the plugin will issue a new ticket.
6. **The badge lies or says "State unknown".** That is for the Moodle
   administrator: they have the
   [System status report](../admin/07-status-report.md) and the
   [event log](../admin/09-recycle-bin-and-log.md#the-event-log).

If a learner who is enrolled is refused and none of the above helps, pass it to
the administrator: it is almost always the ticket bound to an address behind a
proxy or NAT, and it is fixed in the [site settings](../admin/06-settings.md).

---

← Previous: [Comments](10-comments.md) · [All topics](README.md)
