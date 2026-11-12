=== Axle Config Sync ===
Contributors: aryanjasala
Tags: config, deploy, staging, settings, git
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep site settings in git as JSON files. See what differs from the live site, and apply a chosen set with a preview and undo.

== Description ==

Settings live in the database, not in files. Moving them from staging to production means clicking through every screen again, copying the whole database (which overwrites live orders, comments and users), or using an export plugin per feature with no diff and no undo. Sooner or later a staging API key, a staging URL or a test email address ends up on the live site.

Axle Config Sync writes your settings to one JSON file per item, in a folder you commit to git. On the other site it shows what differs, lets you pick what to apply, previews every change, and keeps a change set so you can undo.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

Part of Axle by AryanX, a family of plugins for running WordPress sites safely.

= What it syncs =

* **Options**: the core settings screens (General, Writing, Reading, Discussion, Media, Permalinks, Privacy), plus any plugin option you track by name or `prefix_*` pattern.
* **Theme mods** of the active theme.
* **Widgets**, one file per sidebar. Block widgets included.
* **Classic menus** with their items, order, parents and locations.
* **Block navigation** menus (`wp_navigation`).
* **Roles** and their capabilities.

Templates, template parts and global styles belong in the theme; Block Theme Ops exports those.

= Three-way status =

After every export and apply, the plugin remembers both sides. So it can tell "changed in files" from "changed on this site" and "changed in both" (a conflict), not just "different". Each item shows one of: in sync, changed in files, changed on site, conflict, only in files, only on site, or blocked.

= IDs, URLs and secrets travel safely =

* **IDs become paths.** A front page is stored as `{"$ref":"post","type":"page","path":"about"}`, a menu as its slug, a logo as its file in uploads. On apply they resolve to the IDs on that site. If the page does not exist there, the item is blocked with a plain message.
* **URLs become placeholders.** The site's own URL is written as `{{home_url}}` and expanded to the target's URL on apply. It never runs a find and replace on serialized data.
* **Secrets never go into files.** Values under keys like `api_key`, `token` or `password`, and values that look random, are written as `{"$secret":"ACME_API_KEY"}`. Apply reads them from the constant `AXLE_SECRET_ACME_API_KEY`, then the environment variable, then a filter. If nothing sets it, the live value is kept and the preview says why.
* **Per-environment values.** `env/production.json` sets values for production only, such as the admin email.

= Drift watch =

You set `comments off` in the files. Production has had them on since Tuesday, and the next deploy would turn them off again without a word. Config Sync finds that for you.

* A **daily check** (and one on demand) compares the live values with the files. A setting has drifted when it changed on the site after the files were written.
* New drift raises an **admin notice** for people who can apply, a **Site Health** test, and, if you turn them on, an **email** and a **webhook**. Both are off by default. Each message lists the item, the value in the files and on the site (secrets masked), and who changed it.
* **Who changed it**: the plugin records the user and time when a tracked setting changes, with a cheap hook that ignores every other option. If Activity Log with Undo is active, its entry is used instead.
* **Keep or put back**, for one item or all: "Write to files" accepts the live value (when the folder cannot be written, you get the file to download or copy), and "Put back from files" is the normal apply with a preview and undo.
* An **expected list** holds settings a site may change on purpose. They are never reported.
* `wp axle-config-sync drift` exits with 1 when anything drifted, so a deploy script can stop before it overwrites a live change.

= Coming from WP-CFM? =

WP-CFM was closed on WordPress.org in January 2026, and your deploy script may still call `wp config pull`. The Import tab and `wp axle-config-sync import wp-cfm` read your bundles (the files in `wp-content/config` and the bundle list in the database, with or without WP-CFM active), show how each item maps and which cannot be mapped and why, and write Config Sync files. Nothing is applied to the site, and WP-CFM's files are left alone. Serialized values are decoded without loading any class; a value that holds a PHP object is left out with a reason. The reference has the commands side by side.

= Safe to apply =

* Preview first: before and after for every item, with warnings for missing pages, unset secrets and plugin version differences.
* **Dangerous changes** need an extra "I have read this" (or `--allow-dangerous`): the default role, registration, the admin email, permalinks, hiding production from search engines, and roles gaining powerful capabilities. Anyone who can write to your git repository could otherwise make themselves an admin through an apply.
* Values are written through the core API, so sanitize callbacks and plugin hooks run. If a callback changes a value, the item shows "applied with changes".
* If something fails halfway, the items already written are rolled back.
* **Undo** any apply from History. Undo refuses items that changed since, unless you choose "Undo anyway". Undo is a change set too, so you can redo.
* Files are never unserialized, so a tampered file cannot inject PHP objects.
* With Axle Read-Only Mode freezing the site, apply and undo are refused.

= WP-CLI =

The deploy one-liner:

`git pull && wp axle-config-sync apply --all --yes`

* `wp axle-config-sync status [--provider=] [--state=] [--format=]` exits with 1 when anything differs and 2 on conflicts, for CI.
* `wp axle-config-sync diff <item>`, `export [<item>...] [--all] [--dir=]`, `apply [<item>...] [--all] [--dry-run] [--allow-dangerous] [--force] [--yes]`.
* `wp axle-config-sync undo <changeset> [--force]` and `changesets`.
* `wp axle-config-sync track <option-or-pattern>` and `untrack`.
* `wp axle-config-sync drift [--check] [--format=]` exits with 1 when a setting changed on the site after its file was written. `expected list|add|remove` manages the expected list, and `drift-alerts` shows or sets the email and webhook.
* `wp axle-config-sync import wp-cfm [--bundle=] [--dry-run]` imports WP-CFM bundles.
* `wp axle-config-sync secrets check [--dir=]` exits with 1 when a secret is committed by hand.
* `wp axle-config-sync env` prints the environment this site thinks it is in.

= For developers =

Every default, list, limit and message has a filter, and every step of an apply fires an action, so an activity log can group one apply into one change set. Third-party providers (for settings in a plugin's own tables) plug in through `axle_config_sync_providers`. Abilities for AI agents on WordPress 6.9 and later, including a read-only one for drift. The full list is in [the reference](https://github.com/aryanjasala/axle-config-sync/blob/main/docs/REFERENCE.md).

= Privacy =

Change sets record the user who applied or exported, and when. The drift watch also keeps the user ID and the time of the last change to each tracked setting, so it can say who changed it. Those records go away after the retention period once the setting no longer drifts. The plugin adds suggested text to Settings > Privacy and takes part in Tools > Export Personal Data and Erase Personal Data (erasing keeps the history without the user, and clears the user from drift records). Change sets are deleted after 30 days, or beyond 50. Settings files can hold the admin email address; the preview flags email addresses and suggests an environment file for them.

== Frequently Asked Questions ==

= Where are the files? =

In `wp-content/axle-config/` by default, with an `.htaccess` deny and an empty `index.php`. nginx ignores `.htaccess`, so the screen and Site Health request a test file over HTTP. When the folder is served, or when nginx is the server and the check could not get through, they show the rule to add to the server block. On multisite the rule covers every site's folder. Better: move the folder outside the web root with `define( 'AXLE_CONFIG_SYNC_DIR', '/path/to/repo/config' );` in wp-config.php. On multisite each site gets its own `sites/<id>/` folder.

= Does it change content? =

No. Posts, pages, products, orders and users are content and are never touched. It only reads them to turn IDs into paths and back.

= Can it apply on deploy by itself? =

Not without a person or a command. Put `wp axle-config-sync apply --all --yes` in your deploy script; that is the supported way.

= A value is flagged as a secret but it is not one. =

Add the item (or `item#/json/pointer`) under "Not secrets" on the Track tab. The mark is saved to `config-sync.json`, so it is reviewed in git like everything else.

= What about WP-CFM bundles? =

Import them on the Import tab or with `wp axle-config-sync import wp-cfm --dry-run` and then without `--dry-run`. Options map one to one. Widgets, menus and roles are left out with the reason, because Config Sync keeps them as their own items: run `wp axle-config-sync export --all` on the live site for those. `wp config pull all` in a deploy script becomes `wp axle-config-sync apply --all --yes`.

= Who changed a setting, if I do not have Activity Log with Undo? =

Config Sync records the user and the time when a tracked setting changes. A change made from WP-CLI, a scheduled task or a REST request shows that source instead of a name. Changes to menus and widgets are recorded per provider, so they show who last touched any item of it.

= Does the drift email or webhook send my secrets? =

No. Values are masked the same way as in the files: a secret shows as `{"$secret":"NAME"}`. Long values are cut at 200 characters (`axle_config_sync_drift_value_length`). Both channels are off until you turn them on.

= Does it work on multisite? =

Yes, per site. Network options are not synced in this version.

= Why is the plugin activation list not synced? =

Activating plugins from a file is a big lever for anyone who can push to the repository, so this version leaves `active_plugins` alone.

== Screenshots ==

1. Status: the environment, the config directory and every tracked item with its three-way state.
2. The preview before an apply: each change before and after, a dangerous change, and the confirmation it needs.
3. One item's difference between the file and the site.
4. History: past applies with Undo.
5. Track: which providers and plugin options are synced, and the option finder.
6. Drift: settings changed on the live site, who changed them, and the choice to keep or put back.
7. Import: WP-CFM bundles, how each item maps, and what was left out.

== External services ==

This plugin makes no external calls unless you ask for one. It sends one request to its own site, to check whether the config directory is served over HTTP.

The drift webhook is the only call to another server. It is off until you enter an address under Settings > Config Sync > Drift > Alerts. When you do, the plugin sends a JSON POST to that address for each new drift. The body holds the site URL, the environment name, and for each drifted setting its ID, label, the value in the files and on the site (secrets masked, long values cut), who changed it and when. The service you point it at has its own terms and privacy policy. The drift email goes through your site's normal mail setup.

== Changelog ==

= 1.0.0 =
* First release.
