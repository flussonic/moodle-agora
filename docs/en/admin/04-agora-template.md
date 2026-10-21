# The stream template, closed playback and the tag

For: the Moodle administrator.

Section topics: [Glossary](00-glossary.md) · [How it works](01-how-it-works.md) · [What you need](02-prerequisites.md) · [Service accounts](03-agora-accounts.md) · **The stream template, closed playback and the tag** · [Installing and upgrading the plugin](05-install.md) · [Site settings](06-settings.md) · [The System status report](07-status-report.md) · [Background tasks and capabilities](08-tasks-and-permissions.md) · [The recycle bin and the event log](09-recycle-bin-and-log.md) · [Troubleshooting](10-troubleshooting.md) · [What the Agora operator sees](11-agora-console.md) · [Limitations and what this version does not do](12-limits.md)

---

## The stream template

The template defines what every stream created from a course receives. The
plugin does not change these settings from Moodle — it only names the template.

Open **Templates**, type the name of the new template and press **Create**.

![Creating a stream template](../../img/guide/02-agora-template-new.png)

![The list of stream templates](../../img/guide/02-agora-templates.png)

For teaching streams the template needs two things.

**Recording.** The **DVR** tab: switch on `Record to DVR` and set the storage
depth in hours. Without recording the course activity will honestly say "this
broadcast was not recorded" once it is over — there is no second viewing.

![The DVR tab: recording on, 24 hours of storage](../../img/guide/02-agora-template-dvr.png)

**Thumbnail frames.** The **Thumbnails** tab: press `+ Add thumbnail` and set
the frame width. The thumbnails section gives the picture on the activity card
in the course. Without it the cards have no covers — viewing is unaffected, but
the course looks poorer. The `Store thumbnails in DVR` switch puts the frames
into the recording as well: a past session then has a cover too.

![The Thumbnails tab: a thumbnail frame configured](../../img/guide/02-agora-template-thumbnails.png)

Do not forget **Save** on every tab. Remember the template name: you will type
it into the plugin settings.

## Closed playback

The key check of this section. A ticket protects the video only when the Agora
server requires a ticket at all.

Open **Auth & DRM** and make sure `Open playback for everyone` is **off**.

![Auth & DRM: open playback off, viewing requires a ticket](../../img/guide/02-agora-openplayback.png)

If it is on, the video is served to anyone who knows the link, and everything
the plugin does loses its point. The plugin notices this and writes a warning
into the Moodle [System status report](07-status-report.md) and onto the
activity page for the teacher.

## At least one stream to test the connection

One running stream is enough for a test. Streams are created in the **Streams**
section of the console, and the signal source is set there as well:

- **a file source** — the simplest way: the stream plays a file put on the
  server in advance;
- **publication** — a camera, an encoder or a broadcasting program publishes
  the signal to the stream over RTMP/SRT.

The details of sources, quality and layout are in the Agora documentation: they
are not configured from Moodle and are not covered here.

## The tag on the streams that go into courses

The teacher must not see every stream of the server in the form. They only see
the ones the operator tagged with `moodle.<site label>` — you set the label in
section [Site settings](06-settings.md).

A stream created from a course gets that tag by itself. Streams created by the
operator by hand are tagged by the operator, in the console, on the tags tab of
the stream.

---

← Previous: [Service accounts](03-agora-accounts.md) · Next: [Installing and upgrading the plugin](05-install.md) →
