# Reference

## The config directory

One JSON file per item, meant to be committed to git:

| File | Holds |
| --- | --- |
| `config-sync.json` | Tracking rules: providers on or off, tracked plugin options, owners, "not a secret" marks |
| `manifest.json` | Source environment, WordPress version, active theme, versions of the plugins that own tracked options |
| `options/<name>.json` | One option |
| `theme-mods/<stylesheet>.json` | Theme mods of a theme (without menu locations, sidebars and Additional CSS) |
| `widgets/<sidebar>.json` | The widgets of one sidebar, in order |
| `menus/<slug>.json` | A classic menu with its items |
| `menu-locations.json` | Theme location => menu |
| `navigation/<slug>.json` | A block navigation menu (`wp_navigation`) |
| `roles/<role>.json` | A role and its capabilities |
| `env/<environment>.json` | Values for one environment only |

Each item file:

```json
{
    "schema": 1,
    "provider": "options",
    "id": "options/page_on_front",
    "owner": "core",
    "value": { "$ref": "post", "path": "about", "type": "page" }
}
```

Where it lives:

- Default: `wp-content/axle-config/`, with an `.htaccess` deny and an empty `index.php`. Config Sync also checks whether the web server really refuses the files (see below).
- `define( 'AXLE_CONFIG_SYNC_DIR', '/srv/repo/config' );` in wp-config.php moves it, ideally outside the web root.
- `axle_config_sync_directory` filters the path. On multisite each site gets `sites/<blog_id>/` (`axle_config_sync_site_directory`).

nginx ignores `.htaccess`. The screen and Site Health request a probe file (`reachability-check.json`, the same type as the real files) over HTTP, cache the answer for an hour, and show the result:

| Result | Screen | Site Health |
| --- | --- | --- |
| Refused | Protected | Good |
| Served | Error, with the rule for your server | Critical |
| Not answered, server is nginx | Warning with the rule, because nginx ignores the `.htaccess` that was written | Recommended |
| Not answered, other servers | Notice with the rule | Good, with a note |
| Directory outside the web root | Nothing to check | Good |

Add this to the `server` block of the site config, then reload nginx and use "Check again" (or `wp transient delete axle_config_sync_reachable`). On multisite the rule covers the base folder, so every site's files are protected:

```nginx
location ^~ /wp-content/axle-config/ {
	deny all;
	return 404;
}
```

The server is read from `SERVER_SOFTWARE`. Behind a proxy that hides it, name it with the `axle_config_sync_web_server` filter.

## Value encoding

JSON already tells integers from numeric strings, and booleans and `null` from everything else. On top of that:

- `stdClass` becomes `{"$object":"stdClass","value":{...}}`. An object of any other class is refused as "unsupported value" and the item is not exported.
- A string that is not valid UTF-8 becomes `{"$binary":"<base64>"}`.
- An array that uses a tag key itself is wrapped in `{"$array":{...}}`.
- Map keys are sorted and output is pretty-printed, so git diffs stay small.

Files are never passed to `unserialize()` or `maybe_unserialize()`. A serialized PHP object in a file is written to the site as a plain string.

## Three-way status

After every export and apply, `axle_config_sync_state` (not autoloaded) stores two hashes per item: the file side and the site side at that moment. Comparing with the hashes now:

| State | Meaning |
| --- | --- |
| In sync | File and site hold the same value |
| Changed in files | Only the file moved since the last sync (or there was no sync yet) |
| Changed on site | Only the site moved |
| Conflict | Both moved. Apply needs force |
| Only in files | The item does not exist on the site; apply creates it |
| Only on site | No file yet; export writes one |
| Blocked | Apply cannot write it: a reference does not resolve, a secret has no value and no live value to keep, the file is broken, or a theme mod is for an inactive theme |

A value a sanitize callback changes on the way in is "applied with changes" and then counts as in sync.

## Drift watch

A setting has drifted when its site side moved after the last export or apply: the states "Changed on site" and "Conflict". A file that changed in git is not drift. The next deploy from the files would overwrite a drifted setting, which is the point of watching.

- A check runs daily by WP-Cron (`axle_config_sync_drift_check`, schedule `axle_config_sync_drift_schedule`), from **Settings > Config Sync > Drift**, with `wp axle-config-sync drift --check`, or through the filters below. It stores the drifted items and the hash of each value, so a value that changes again counts as new drift.
- New drift raises an admin notice for people who can apply (dismiss it per user, until something else drifts), a Site Health test, and, when you turn them on, an email and a webhook. Each new drift is sent once. Email and webhook are off by default.
- The email and the webhook carry the item, the value in the files and on the site (secrets masked as `{"$secret":"NAME"}`, long values cut), and who changed it. The webhook gets a JSON `POST` from `wp_safe_remote_post`, so it must be a public http or https URL. This is the only call to another server the plugin makes, and only when you set a webhook.
- A change to a setting that is covered by the tracking rules records the user ID, the time and where it came from (admin, WP-CLI, cron, REST) in `axle_config_sync_drift_actors`. The hook leaves at once for every other option. The plugin's own apply, undo and export are never recorded. When Activity Log with Undo is active and has an entry for the option, its user and time are used instead. Changes to menus, navigation and widgets are recorded per provider; a change that happened before the item was last synced is ignored.
- **Write to files** accepts the live value: it runs the normal export for the item and the state moves on. When the config directory is not writable, the screen offers the file to download or copy instead. **Put back from files** is the normal apply: a preview, a change set and undo.
- The **expected list** holds items a site may change on purpose (an item ID, or a pattern such as `options/woocommerce_*`). They are never reported. Add to it from the screen, `wp axle-config-sync expected add`, or the `axle_config_sync_drift_expected` filter.

```sh
wp axle-config-sync drift                      # table, exit 1 when anything drifted
wp axle-config-sync drift --format=json        # or ids, count, csv, yaml
wp axle-config-sync drift --check              # the daily job, by hand
wp axle-config-sync expected add options/blogdescription
wp axle-config-sync drift-alerts --email=on --email-to=ops@example.com --webhook=https://hooks.example.com/abc
```

A deploy script can stop before it overwrites a live change:

```sh
wp axle-config-sync drift --format=ids && git pull && wp axle-config-sync apply --all --yes
```

## Moving from WP-CFM

WP-CFM was closed on WordPress.org in January 2026. Its bundles are JSON files in `wp-content/config` (`<bundle>.json`, or `blog<ID>-<bundle>.json` on multisite) that map option names to the raw option values as the database holds them, plus a `.label` key. The bundle list (name, label and option names) is also kept as JSON in the `wpcfm_settings` option. The **Import** tab, `wp axle-config-sync import wp-cfm` and the `import-wp-cfm` ability read both, with or without WP-CFM active.

What the import does:

1. Shows a preview that maps each WP-CFM item to a Config Sync item and says whether its file is new, replaced or already the same. Items that cannot be mapped are listed with the reason.
2. Writes Config Sync files. Plugin options are added to the tracking rules so status lists them.
3. Changes nothing else. The site is not touched, WP-CFM's files and settings are left alone, and you review the new files in git.

How items map:

| WP-CFM item | Config Sync item |
| --- | --- |
| An option such as `blogname` or `acme_settings` | `options/<name>` |
| `theme_mods_<stylesheet>` | `theme-mods/<stylesheet>` (menu locations, sidebars and Additional CSS are not part of it) |
| `siteurl`, `home`, salts and keys, `cron`, `active_plugins`, transients | Left out: never synced |
| `sidebars_widgets`, `widget_*`, `nav_menu_options`, `*user_roles` | Left out: Config Sync keeps widgets, menus and roles as their own items. Run `wp axle-config-sync export --all` on the live site |

Serialized values are decoded with `unserialize( ..., [ 'allowed_classes' => false ] )` after a check that finds no object, and a value that holds a PHP object is left out with a reason. A bundle that exists only in the database (never pushed to a file) takes the live value on this site. An option in two bundles is taken once. IDs in values (a page ID, for example) are turned into portable references against this site, so import on the site the bundle was made on, or check the references in the preview.

The commands side by side:

| WP-CFM | Config Sync |
| --- | --- |
| `wp config bundles` | `wp axle-config-sync status` and `wp axle-config-sync import wp-cfm --dry-run` |
| `wp config diff all` | `wp axle-config-sync diff <item>` |
| `wp config push all` | `wp axle-config-sync export --all` |
| `wp config pull all` | `wp axle-config-sync apply --all --yes` |

Your deploy script moves from `wp config pull all` to `git pull && wp axle-config-sync apply --all --yes`. Unlike a pull, an apply shows a preview, refuses dangerous changes without `--allow-dangerous`, stores a change set, and can be undone.

## Portable references

| Kind | In the file |
| --- | --- |
| Post or page | `{"$ref":"post","type":"page","path":"about/team"}` (a GUID when there is no slug) |
| Term | `{"$ref":"term","taxonomy":"category","slug":"news"}` |
| Attachment | `{"$ref":"attachment","file":"2026/10/logo.png"}` |
| Menu | `{"$ref":"menu","slug":"primary"}` |

Known ID fields: `page_on_front`, `page_for_posts`, `wp_page_for_privacy_policy`, `site_icon`, `default_category`, theme mods `custom_logo` and `header_image_data.attachment_id`, menu locations, menu item objects and parents, `widget_nav_menu` menus, media widget attachments, and navigation link and submenu `id` attributes. Add your own:

```php
add_filter( 'axle_config_sync_reference_fields', function ( array $fields ) {
	$fields['options/acme_settings'] = array( 'landing_page' => 'post', 'logo' => 'attachment', 'topics.*' => 'term:category' );
	return $fields;
} );
```

A reference that does not resolve blocks its item with a plain message, such as `Page "about/team" does not exist on this site.`

## URL placeholders

Every string holding this site's home or WordPress URL (http or https, plain or JSON-escaped slashes) is written with `{{home_url}}` or `{{site_url}}`, and expanded to the target's URL on apply. Serialized strings are left alone. Add placeholders with `axle_config_sync_url_placeholders`:

```php
add_filter( 'axle_config_sync_url_placeholders', function ( array $map ) {
	$map['{{cdn_url}}'] = 'https://cdn.example.com';
	return $map;
} );
```

## Secrets

A string is a secret when its item and key path has one of the words `key`, `secret`, `token`, `password`, `passwd`, `api`, `license`, `licence`, `private`, `auth`, `credential`, `webhook` (as whole words, 8 characters or more, no spaces), or when it looks random (32 characters or more, letters and digits, high entropy). It is written as `{"$secret":"ACME_API_KEY"}`.

Apply resolves `NAME` from, in order:

1. the constant `AXLE_SECRET_NAME`
2. `getenv( 'AXLE_SECRET_NAME' )`
3. the `axle_config_sync_secret` filter

When nothing sets it, the live value is kept and the screen says so. Mark false positives on the Track tab ("Not secrets", as `item` or `item#/pointer`); the marks are saved in `config-sync.json`.

Export refuses to write a file that still has a plain secret (for example one an `axle_config_sync_export_value` filter put back). `wp axle-config-sync secrets check` scans the folder for CI. No ability, REST route or CLI output shows a secret's value.

## Environments

The environment is `AXLE_CONFIG_SYNC_ENV` when defined, else `wp_get_environment_type()` (the same source Staging Guard uses), filtered by `axle_config_sync_environment`. `env/<environment>.json` sets values for that environment:

```json
{
    "schema": 1,
    "values": {
        "options/admin_email": { "": "ops@example.com" },
        "options/acme_settings": { "/mode": "live" }
    }
}
```

Keys are item IDs, then JSON pointers (`""` for the whole value). Export keeps the shared file's value at those pointers, so one environment's values never land in the shared file.

## Dangerous changes

These need "I have read this" on the screen, `--allow-dangerous` on the CLI, or `allow_dangerous: true` for the ability:

- `users_can_register`, `default_role`, `admin_email`, `permalink_structure`
- `blog_public` going from 1 to 0 on production
- a role gaining `manage_options`, `promote_users`, `edit_users`, `unfiltered_html`, `activate_plugins` or `install_plugins`, or a role being removed

## Apply and undo

Apply order: roles, options, theme mods, menus, navigation, widgets (`axle_config_sync_apply_order`), so menu references resolve before widgets point at them. Values are written through `update_option`, `set_theme_mod`, `wp_update_nav_menu_item`, `wp_insert_post` and `WP_Roles`, so sanitize callbacks and plugin hooks run.

Each apply stores a change set (table `{prefix}axle_config_sync_changesets`) with every item's value before and after. If a write throws, the items already written are restored and the change set is marked rolled back. When an item cannot be restored either, the error names it and the change set that keeps its earlier value, instead of passing over it. An undo that fails halfway puts back what it already wrote, so the site is not left half undone. Undo restores the before values only when nothing changed since; otherwise it lists the items and "Undo anyway" (`--force`) overwrites them. Undo is a change set too. Change sets are kept 30 days or 50 sets.

The screen applies up to 200 items in one request (`axle_config_sync_apply_batch_size`); above that it sends chunks to the REST route, continuing one change set.

With Axle Read-Only Mode freezing the site, apply and undo are refused on the screen, the CLI, the REST route and the abilities (which are annotated destructive).

## Hooks

### Filters

| Filter | Arguments | Default |
| --- | --- | --- |
| `axle_config_sync_capability` | `string $capability` | `manage_options` |
| `axle_config_sync_directory` | `string $path` | `wp-content/axle-config` or `AXLE_CONFIG_SYNC_DIR` |
| `axle_config_sync_site_directory` | `string $folder, int $blog_id` | `sites/<blog_id>` |
| `axle_config_sync_probe_url` | `string $url` | URL of the probe file |
| `axle_config_sync_web_server` | `string $server` | nginx, apache, litespeed, iis or unknown, from `SERVER_SOFTWARE` |
| `axle_config_sync_environment` | `string $name, string $source` | `AXLE_CONFIG_SYNC_ENV` or `wp_get_environment_type()` |
| `axle_config_sync_providers` | `array $providers` | name => `Provider` |
| `axle_config_sync_tracked_options` | `string[] $patterns` | from `config-sync.json` |
| `axle_config_sync_ignored_options` | `string[] $patterns` | transients, caches, counters, timestamps |
| `axle_config_sync_never_sync` | `string[] $patterns` | URLs, versions, cron, salts, keys; salts stay blocked |
| `axle_config_sync_secret_key_patterns` | `string[] $words` | key, secret, token, ... |
| `axle_config_sync_is_secret` | `bool $secret, string $id, string $pointer, mixed $value` | |
| `axle_config_sync_secret` | `string\|null $value, string $name` | constant, then environment |
| `axle_config_sync_reference_fields` | `array $fields` | item => path => type |
| `axle_config_sync_resolve_reference` | `int\|null $id, array $ref` | |
| `axle_config_sync_url_placeholders` | `array $map` | `{{home_url}}`, `{{site_url}}` |
| `axle_config_sync_dangerous_items` | `string[] $items` | see above |
| `axle_config_sync_dangerous_capabilities` | `string[] $caps` | see above |
| `axle_config_sync_is_dangerous` | `bool $dangerous, string $id, mixed $before, mixed $after` | |
| `axle_config_sync_export_value` | `mixed $value, string $id, mixed $live` | portable value |
| `axle_config_sync_apply_value` | `mixed $value, string $id, mixed $live` | resolved value |
| `axle_config_sync_apply_order` | `string[] $providers` | roles, options, theme-mods, menus, navigation, widgets |
| `axle_config_sync_apply_batch_size` | `int $size` | 200 (1 to 1000) |
| `axle_config_sync_retention_days` | `int $days` | 30 (1 to 3650) |
| `axle_config_sync_retention_count` | `int $count` | 50 (1 to 10000) |
| `axle_config_sync_file_json_flags` | `int $flags` | pretty, unescaped slashes and Unicode |
| `axle_config_sync_messages` | `array $messages` | every user-facing message |
| `axle_config_sync_drift_schedule` | `string $recurrence` | `daily` |
| `axle_config_sync_drift_states` | `string[] $states` | `changed_on_site`, `conflict` |
| `axle_config_sync_drift_expected` | `string[] $items` | the saved expected list |
| `axle_config_sync_drift_items` | `array $items` | the drifted items, before a check stores or shows them |
| `axle_config_sync_drift_actor` | `array\|null $who, string $id` | the recorded user, or Activity Log with Undo's |
| `axle_config_sync_drift_actor_limit` | `int $limit` | 300 (10 to 5000) |
| `axle_config_sync_drift_value_length` | `int $length` | 200 (20 to 5000) |
| `axle_config_sync_drift_email` | `array $mail, array $new` | `to`, `subject`, `message` |
| `axle_config_sync_drift_webhook_payload` | `array $payload, array $new` | event, site, environment, items |
| `axle_config_sync_drift_webhook_timeout` | `int $seconds` | 3 (1 to 3) |
| `axle_config_sync_drift_notice` | `bool $show` | true while there is drift and the notice is not dismissed |
| `axle_config_sync_wpcfm_directory` | `string $path` | WP-CFM's folder, or `wp-content/config` |
| `axle_config_sync_wpcfm_map` | `array $map, string $key` | `id` and `reason` for a WP-CFM key |
| `axle_config_sync_wpcfm_max_items` | `int $max` | 2000 (1 to 20000) |
| `axle_config_sync_wpcfm_max_file_size` | `int $bytes` | 5 MB (1 KB to 50 MB) |

Example: let editors with `manage_network_options` use it on multisite.

```php
add_filter( 'axle_config_sync_capability', fn () => 'manage_network_options' );
```

### Actions

| Action | Arguments |
| --- | --- |
| `axle_config_sync_exported` | `array $items, string $directory` |
| `axle_config_sync_apply_started` | `int $changeset_id, string[] $items` |
| `axle_config_sync_item_applied` | `string $id, mixed $before, mixed $after` |
| `axle_config_sync_item_skipped` | `string $id, string $reason` |
| `axle_config_sync_apply_finished` | `int $changeset_id` |
| `axle_config_sync_apply_failed` | `int $changeset_id, WP_Error $error` |
| `axle_config_sync_rolled_back` | `int $changeset_id` |
| `axle_config_sync_undone` | `int $new_changeset_id, int $undone_changeset_id` |
| `axle_config_sync_conflict_detected` | `string[] $items` |
| `axle_config_sync_secret_unresolved` | `string $id, string $name` |
| `axle_config_sync_drift_check_started` | `string $via` |
| `axle_config_sync_drift_checked` | `array $items, array $new, string $via` |
| `axle_config_sync_drift_detected` | `array $new, array $items` |
| `axle_config_sync_drift_notified` | `string $channel, array $new` |
| `axle_config_sync_drift_kept` | `string[] $items` |
| `axle_config_sync_drift_expected_changed` | `string[] $items` |
| `axle_config_sync_wpcfm_imported` | `array $written, string[] $bundles, string[] $tracked` |

Example: tell your team's chat about new drift.

```php
add_action( 'axle_config_sync_drift_detected', function ( array $new ) {
	my_chat_post( 'Settings changed on production: ' . implode( ', ', array_keys( $new ) ) );
} );
```

Example: group an apply in your own log.

```php
add_action( 'axle_config_sync_apply_started', function ( $changeset, $items ) {
	my_log_start_group( 'config-sync-' . $changeset, $items );
}, 10, 2 );
```

### JavaScript (`wp.hooks`)

| Hook | Type | Arguments |
| --- | --- | --- |
| `axleConfigSync.diffRenderer` | filter | `html\|null, { item, before, after, element }`: return HTML to replace a diff |
| `axleConfigSync.confirmMessage` | filter | `message, { items }` |
| `axleConfigSync.previewWarnings` | filter | `warnings[], { item, element }` |
| `axleConfigSync.applied` | action | `{ changeset }` |
| `axleConfigSync.undone` | action | `{ changeset }` |

```js
wp.hooks.addFilter( 'axleConfigSync.confirmMessage', 'my-plugin', ( message, { items } ) => `${ message }\n\nDeploy window is open?` );
```

## Providers

A provider implements `AryanX\Axle\ConfigSync\Providers\Provider`: `items()`, `exists()`, `read()`, `write()`, `references()`, `resolve()`, `gate()`, `label()`, `danger()`, `owner()`, `summary()`, plus `file()` and `id_for_file()`. Extend `Providers\Base` for one-file-per-item behaviour, and add it through `axle_config_sync_providers`.

## Abilities (WordPress 6.9+)

| Ability | Annotations | Input |
| --- | --- | --- |
| `axle-config-sync/get-status` | readonly | `provider`, `state` |
| `axle-config-sync/get-item-diff` | readonly | `item` |
| `axle-config-sync/list-changesets` | readonly | `limit` |
| `axle-config-sync/export-config` | idempotent | `items`, `all` |
| `axle-config-sync/preview-apply` | readonly | `items`, `all`, `allow_dangerous`, `force`, `inactive_theme` |
| `axle-config-sync/apply-config` | destructive | same as preview |
| `axle-config-sync/undo-apply` | destructive | `changeset`, `force` |
| `axle-config-sync/get-drift` | readonly | none |
| `axle-config-sync/preview-wp-cfm-import` | readonly | `bundles` |
| `axle-config-sync/import-wp-cfm` | idempotent | `bundles` |

Permission: the `axle_config_sync_capability` capability.

## REST

`axle-config-sync/v1`: `GET /status`, `GET /drift`, `POST /apply` (`items`, `allow_dangerous`, `force`, `inactive_theme`, `changeset`), `POST /undo/<id>` (`force`). Same capability.

## WP-CLI

| Command | What it does |
| --- | --- |
| `wp axle-config-sync status [--provider=] [--state=] [--format=]` | Every item and its state. Exit 1 when anything differs, 2 on conflicts |
| `wp axle-config-sync diff <item>` | File and site values, line by line |
| `wp axle-config-sync export [<item>...] [--all] [--dir=]` | Write files |
| `wp axle-config-sync apply [<item>...] [--all] [--dry-run] [--allow-dangerous] [--force] [--inactive-theme] [--yes]` | Apply with a change set |
| `wp axle-config-sync undo <changeset> [--force]` | Undo |
| `wp axle-config-sync changesets [--format=]` | Past applies |
| `wp axle-config-sync track <option-or-pattern> [--owner=]` / `untrack` | Tracking rules |
| `wp axle-config-sync secrets check [--dir=]` | Exit 1 when a file holds a secret |
| `wp axle-config-sync secrets mark <item[#/pointer]>` | Mark a value as not a secret ("Not secrets" on the Track tab) |
| `wp axle-config-sync providers [<provider> on\|off] [--format=]` | Show the providers, or turn one on or off (the checkboxes on the Track tab) |
| `wp axle-config-sync env` | The environment and where it came from |
| `wp axle-config-sync drift [--check] [--format=]` | Settings changed on the site after their files were written. Exit 1 when any. `--check` runs the full check and sends alerts |
| `wp axle-config-sync expected list\|add\|remove [<item>...]` | The expected list |
| `wp axle-config-sync drift-alerts [--email=on\|off] [--email-to=] [--webhook=]` | Show or set the email and webhook |
| `wp axle-config-sync import wp-cfm [--bundle=] [--dry-run] [--format=]` | Import WP-CFM bundles as Config Sync files |

## Site Health

- Critical: the config directory is served over HTTP.
- Critical: a file holds a secret in plain text.
- Recommended: items conflict.
- Recommended: settings drifted from the files (from the last check).
- Recommended: the directory could not be checked and the server is nginx.

## Data and privacy

- `axle_config_sync_settings` (autoloaded, small): a copy of the tracking rules.
- `axle_config_sync_state` (not autoloaded): hashes per item, last export and apply.
- `axle_config_sync_drift` (autoloaded, small: an ID and a hash per drifted item): the drifted items of the last check, when it ran, and the last email or webhook error. It is read on admin pages for the notice, so it is autoloaded.
- `axle_config_sync_drift_actors` (not autoloaded): who changed a tracked setting last, and when. A user ID and a time per item, 300 at most. Records of items that no longer drift are removed after the retention period.
- `axle_config_sync_settings` also holds the expected list and the alert settings under `drift`.
- User meta `axle_config_sync_drift_seen` and `axle_config_sync_steps_dismissed`: what a user dismissed.
- `axle_config_sync_db_version`, the transient `axle_config_sync_reachable`.
- Table `{prefix}axle_config_sync_changesets`: id, created_gmt, user_id, via, environment, items_count, status, undo_of, payload.
- Cron `axle_config_sync_purge` and `axle_config_sync_drift_check`, both daily.

The privacy exporter lists a user's change sets and drift records; the eraser sets their user to 0 and keeps the history. If you turn on the drift email or webhook, the changed settings (secrets masked) go to the address or server you set. Uninstall removes the options, the user meta, the table, the cron events and transients. It never deletes the config directory: that is your data in git.

## Not in 1.0

- Network options (`wp_sitemeta`) on multisite, and WP-CFM network bundles.
- The active plugins list.
- Content, templates, template parts and global styles (Block Theme Ops).
- Settings in custom tables (WooCommerce tax rates, form plugins), which third-party providers can add.
- Menus with thousands of items are written in one request, not in batches.
