# Installing and upgrading the plugin

For: the Moodle administrator.

Section topics: [Glossary](00-glossary.md) · [How it works](01-how-it-works.md) · [What you need](02-prerequisites.md) · [Service accounts](03-agora-accounts.md) · [The stream template, closed playback and the tag](04-agora-template.md) · **Installing and upgrading the plugin** · [Site settings](06-settings.md) · [The System status report](07-status-report.md) · [Background tasks and capabilities](08-tasks-and-permissions.md) · [The recycle bin and the event log](09-recycle-bin-and-log.md) · [Troubleshooting](10-troubleshooting.md) · [What the Agora operator sees](11-agora-console.md) · [Limitations and what this version does not do](12-limits.md)

---

The plugin lives in the repository in the `mod_agora/` directory. The
**contents** of that directory go into the Moodle tree, and the directory
itself is called `agora`:

```sh
git clone <repository address> /tmp/moodle-agora
cp -r /tmp/moodle-agora/mod_agora <Moodle root>/mod/agora
```

If the repository is not reachable from the Moodle server, copy an archive of
the `mod_agora` directory there and unpack it into `mod/agora`.

Check that the path came out exactly like this:
`<Moodle root>/mod/agora/version.php`.

## File permissions

The files of the plugin must be readable by the user the web server runs as
(usually `www-data`) and must **not** be writable by it — just like the rest of
the Moodle code:

```sh
chown -R root:www-data <Moodle root>/mod/agora
chmod -R u=rwX,g=rX,o= <Moodle root>/mod/agora
```

## Installing in Moodle

Log in to the site as the administrator. Moodle notices the new plugin itself
and shows the plugin check page: Agora broadcast in the list with the "To be
installed" status. The same page opens from Notifications.

![Plugin check: Agora broadcast waiting to be installed](../../img/guide/03-install-check.png)

Press "Upgrade Moodle database now" and wait for it to finish.

![The database is upgraded, the plugin is installed](../../img/guide/03-install-done.png)

The plugin is visible in Site administration → Plugins → Plugins overview,
section "Activity modules".

![Plugins overview: Agora broadcast installed](../../img/guide/03-plugins-list.png)

The plugin asks for nothing else at install time: no separate database, no
background services. It adds two scheduled tasks of its own to the site
schedule — section
[Background tasks and capabilities](08-tasks-and-permissions.md).

## Upgrading the plugin

The same way as the install: take the new version from the repository, put it
over `mod/agora`, log in as the administrator and confirm the database upgrade.
The connection settings and the course activities already created are kept.

```sh
cd /tmp/moodle-agora && git pull
cp -r /tmp/moodle-agora/mod_agora/. <Moodle root>/mod/agora/
```

---

← Previous: [The stream template, closed playback and the tag](04-agora-template.md) · Next: [Site settings](06-settings.md) →
