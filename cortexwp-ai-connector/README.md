# CortexWP connector source contract

The `flyingpress_performance` action is the narrow, versioned bridge between the
operations worker and an installed FlyingPress plugin. It supports only probe,
apply-config, homepage purge/preload, defer-fallback, and `purge_all` phases.
`purge_all` clears the whole FlyingPress cache after the homepage has been
tuned and verified, warms the homepage first, and queues the rest of the site.

The read-only `flyingpress_status` action reports whether FlyingPress is
installed and active, its version, and the wp-admin upload/plugins URLs. It
never reads or changes FlyingPress settings. `flyingpress_activate` activates an
already-installed FlyingPress plugin only; it never installs, updates, or
changes any other plugin or FlyingPress setting. `flyingpress_status` also
returns `license_active`/`license_status` and the FlyingPress page URL, never
the key. `flyingpress_license_activate` passes a key to FlyingPress's own
licensing client; the key is never logged or returned.

The action allowlists performance settings and leaves license, CDN, cleanup,
bloat, object-cache, credentials, and unrelated plugin settings untouched. Its
probe returns bounded cacheability, cache-file existence/timestamps, and selected
configuration state; it never returns absolute paths or secrets.

## Performance speed-up actions (engine v2, connector 2.5)

These actions back the AI speed-up on the CortexWP Performance page. Each one
is allowlisted and bounded; older connectors answer "Unknown CortexWP action"
and CortexWP falls back to the original `flyingpress_performance` flow.

- `flyingpress_config` (`read` / `patch`): reads and changes only the FlyingPress
  settings listed in `flyingpress_config_schema()` (optimization, bloat,
  database clean-up, image format, and exclusion lists). Credentials, CDN,
  Cloudflare, and license keys are never read or written. `patch` returns the
  previous values so CortexWP can undo the change.
- `performance_environment`: read-only theme, active plugins, page builders,
  WooCommerce, PHP/object cache, FlyingPress license/CDN flags, and the managed
  snippet's rules.
- `performance_images`: `resolve` homepage image URLs to attachments,
  `optimize` up to two attachments at a time with FlyingPress's own image
  optimizer (no cache purge), `status` (cheap queue counts), `full_status`,
  `optimize_all` (FlyingPress queues every image and clears/preloads the whole
  cache itself when done), and `stop_all`.
- `performance_db`: `stats` (counts) and `clean` (switches on FlyingPress's
  weekly clean-up for the chosen items, runs it now when small; never optimizes
  tables).
- `performance_snippet` (`read` / `write`): the ONE managed snippet
  `cortexwp-performance` ("CortexWP Performance"). It is regenerated from
  validated rules stored in the `cortexwp_performance_rules` option. To add a
  new performance fix, add a rule type to `performance_validate_rule()` and
  `performance_snippet_rule_code()`; never create another snippet. Values are
  embedded with `var_export` only, and jQuery can never be removed.
