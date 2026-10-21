# The System status report

For: the Moodle administrator.

Section topics: [Glossary](00-glossary.md) · [How it works](01-how-it-works.md) · [What you need](02-prerequisites.md) · [Service accounts](03-agora-accounts.md) · [The stream template, closed playback and the tag](04-agora-template.md) · [Installing and upgrading the plugin](05-install.md) · [Site settings](06-settings.md) · **The System status report** · [Background tasks and capabilities](08-tasks-and-permissions.md) · [The recycle bin and the event log](09-recycle-bin-and-log.md) · [Troubleshooting](10-troubleshooting.md) · [What the Agora operator sees](11-agora-console.md) · [Limitations and what this version does not do](12-limits.md)

---

**Site administration → Reports → System status.** The plugin adds seven checks
there:

- **Agora: network to the Agora server** — does the server answer; the details
  carry the number of streams visible to the `admin` account;
- **Agora: the player role issues tickets**;
- **Agora: the admin role reads streams**;
- **Agora: open playback is off** — if open playback is on at the Agora server
  ([Closed playback](04-agora-template.md#closed-playback)), the check gives a
  warning: a ticket protects nothing in that mode. The teacher sees the same
  warning on the activity page;
- **Agora: refresh of stream states** — does the background task that keeps the
  state badges fresh run. It turns red when the last pass was over three
  minutes ago or failed; the details carry the time of the pass or the error
  text of the Agora server plus the number of streams left without a state. Its
  "fix it" link goes not to the plugin settings but to **Server → Scheduled
  tasks**: that is where it is fixed. A pass that ran but found no streams on
  the Agora server is counted separately: some of them is a warning (the
  operator deleted the stream; the field is "Stream" in the activity settings),
  all of them is an error (a wrong Agora server address or another interface
  version; the field is "Agora server address"). With no Agora broadcast
  activities on the site the check reports "not applicable".
- **Agora: publication is set up** — everything the teachers need to broadcast
  from courses. It checks two things and names the failed one with a reason
  and a field: the publish address (read from the Agora server; if it is
  missing, the fallback address from the "Publish address (fallback)" setting)
  and the publication port range (set and having free ports; a green check
  carries the address and the number of free ports in its details). Red for a
  missing range sends you to the plugin settings field. A green check also
  shows a separate line
  "recordings without an export for over an hour: N" — recordings that are
  ready but whose clip never appeared; the plugin has already logged one event
  per such recording.
- **Agora: recording routing** — whether the Agora server has the recordings
  facet. The probe reads the recording list of a stream that certainly exists.
  Green: recordings and exports are read and VOD clips are created. Yellow "Agora
  has no recordings facet": the recording plays through the archive window and
  no clips are created — fixed by updating Agora to a version with recordings,
  not by a settings field. Red: "the admin account is not admitted to the
  recordings API" (fixed with the admin login and password) and "the Agora
  server does not answer the recordings probe" (fixed with the server address).
  With no Agora broadcast activities on the site the check reports "not
  applicable".

This is what a wrong password of the admin account looks like: the checks that
need a login under that account turn red with the summary "the Agora server
answers, but the login of the admin account was refused", the details name the
field "Password of the admin account", and the Action column carries a link to
the settings page where it is fixed. The player check stays green: it has its
own password.

The first three checks name the reason for a failure in their summary:

- "The Agora server does not answer" — no network to the server or a wrong
  address; the field is "Agora server address";
- "…the login of the … account was refused" — a wrong login or password;
- "The role does not allow issuing tickets", "The role does not allow reading
  streams" — the Agora server answered 403: the account was given the wrong
  role in the Agora console;
- "The Agora licence does not include accounts" — the Agora server answered
  the sign-in with 404; fixed by the Agora licence, not by a settings field;
- "Too many sign-ins to Agora" — the Agora server allows no more than 10
  sign-ins a minute for one address and login; wait a minute and refresh the
  report;
- "Agora failed on its side: code N" — the Agora server answered with a 5xx
  error; the details carry the response text, the Agora operator fixes it;
- "The Agora server answer is not understood" — the answer came with a success
  code but is not a JSON object: usually a proxy page or another service at the
  "Agora server address"; the details show the start of the answer;
- "The check failed: code N" — any other code (for example, 404 on a request
  that is not the sign-in); the reason is in the details, and the role is not
  blamed: it is unknown in this case;
- "The check failed without an answer from the Agora server" — a failure with
  no answer and no known reason (for example, a failure in Moodle itself); the
  reason is in the details.

A "who is signed in" answer without a role (an empty `{}` included) makes the
role checks say "The Agora server answer is not understood", not "the role does
not allow". When the Agora server does not answer, the role checks show
"unknown" with the summary "No data: the Agora server does not answer" — fixed
by the address, as for the network check.

This is what an "Agora server address" looks like when it leads not to Agora
but to another service that answers with an HTML page and code 200. The three
checks that sign in say "The Agora server answer is not understood", with the
start of the answer in the details (here, the head of the HTML page). The open
playback check shows "Unknown" with the summary "No data: nothing to judge by":
the access policy could not be read, and the check will not say "fine". "Agora:
refresh of stream states" turns red with "The last pass failed" as soon as the
background task runs against this address. The fix is the "Agora server
address" field; once it is fixed, all seven checks turn green with the next run
of the task.

![System status: another service answers at the "Agora server address"](../../img/guide/04-status-unparsed.png)

This is what too many sign-ins look like: more than 10 sign-in attempts a
minute from the address of the Moodle server with the login of the admin
account. The checks that sign in with this account turn red with "Too many
sign-ins to Agora"; the player check stays green — it has its own login, and
its rate is counted separately. The account is not locked: after a minute the
rate window closes, and the report opened again is green. If the summary stays
longer, someone keeps signing in with this login from the Moodle address.

![System status: too many sign-ins of the admin account](../../img/guide/04-status-ratelimited.png)

![System status: the Agora checks are red](../../img/guide/04-status-red.png)

Fix the password and save.

![The settings are saved](../../img/guide/04-settings-saved.png)

![System status: every Agora check is green](../../img/guide/04-status-green.png)

Missing data is reported as "unknown", never as "fine". When something does not
work, look here: every refusal of the plugin names its check and its settings
field.

---

← Previous: [Site settings](06-settings.md) · Next: [Background tasks and capabilities](08-tasks-and-permissions.md) →
