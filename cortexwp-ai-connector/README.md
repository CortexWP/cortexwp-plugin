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
