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

- Default: `wp-content/axle-config/`, with an `.htaccess` deny and an empty `index.php`.
- `define( 'AXLE_CONFIG_SYNC_DIR', '/srv/repo/config' );` in wp-config.php moves it, ideally outside the web root.
- `axle_config_sync_directory` filters the path. On multisite each site gets `sites/<blog_id>/` (`axle_config_sync_site_directory`).

nginx ignores `.htaccess`. The screen and Site Health request a probe file over HTTP (cached for an hour) and show this rule when it is served:

```nginx
location ^~ /wp-content/axle-config/ {
	deny all;
	return 404;
}
```

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

Each apply stores a change set (table `{prefix}axle_config_sync_changesets`) with every item's value before and after. If a write throws, the items already written are restored and the change set is marked rolled back. Undo restores the before values only when nothing changed since; otherwise it lists the items and "Undo anyway" (`--force`) overwrites them. Undo is a change set too. Change sets are kept 30 days or 50 sets.

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

Permission: the `axle_config_sync_capability` capability.

## REST

`axle-config-sync/v1`: `GET /status`, `POST /apply` (`items`, `allow_dangerous`, `force`, `inactive_theme`, `changeset`), `POST /undo/<id>` (`force`). Same capability.

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
| `wp axle-config-sync env` | The environment and where it came from |

## Site Health

- Critical: the config directory is served over HTTP.
- Critical: a file holds a secret in plain text.
- Recommended: items conflict.

## Data and privacy

- `axle_config_sync_settings` (autoloaded, small): a copy of the tracking rules.
- `axle_config_sync_state` (not autoloaded): hashes per item, last export and apply.
- `axle_config_sync_db_version`, the transient `axle_config_sync_reachable`.
- Table `{prefix}axle_config_sync_changesets`: id, created_gmt, user_id, via, environment, items_count, status, undo_of, payload.
- Cron `axle_config_sync_purge`, daily.

The privacy exporter lists a user's change sets; the eraser sets their user to 0 and keeps the history. Uninstall removes the options, the table, the cron event and transients. It never deletes the config directory: that is your data in git.

## Not in 1.0

- Network options (`wp_sitemeta`) on multisite.
- The active plugins list.
- Content, templates, template parts and global styles (Block Theme Ops).
- Settings in custom tables (WooCommerce tax rates, form plugins), which third-party providers can add.
- Menus with thousands of items are written in one request, not in batches.
