# Glossary

For: the Moodle administrator.

Section topics: **Glossary** · [How it works](01-how-it-works.md) · [What you need](02-prerequisites.md) · [Service accounts](03-agora-accounts.md) · [The stream template, closed playback and the tag](04-agora-template.md) · [Installing and upgrading the plugin](05-install.md) · [Site settings](06-settings.md) · [The System status report](07-status-report.md) · [Background tasks and capabilities](08-tasks-and-permissions.md) · [The recycle bin and the event log](09-recycle-bin-and-log.md) · [Troubleshooting](10-troubleshooting.md) · [What the Agora operator sees](11-agora-console.md) · [Limitations and what this version does not do](12-limits.md)

---

Six words you will meet below.

- **Agora server** — the corporate video server: it takes the signal, delivers
  it to viewers and keeps the recording.
- **Agora console** — the web interface of the Agora server. The operator works
  there: creating streams, templates and accounts. The console is in English,
  so its sections are named below as they are labelled on screen.
- **Stream (broadcast)** — a named video stream on the Agora server: the live
  signal and its recording. One stream, one course activity.
- **Stream template** — the set of settings every stream created from it
  receives: recording depth, thumbnail frames, quality. Set in the Agora
  console; Moodle does not change it.
- **Viewing ticket** — the short-lived permit Moodle issues to one learner for
  one stream. Without a ticket the Agora server serves no video.
- **Agora account** — a login with a role in the Agora console. The plugin
  needs two service accounts (`player` and `admin`); the operator needs one of
  their own with the `superadmin` role to create them and set up the server.
  Learners and teachers need no Agora account: they sign in to Moodle only.

---

Next: [How it works](01-how-it-works.md) →
