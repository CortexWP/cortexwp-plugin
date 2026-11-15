# CortexWP connector source contract

The `flyingpress_performance` action is the narrow, versioned bridge between the
operations worker and an installed FlyingPress plugin. It supports only probe,
apply-config, purge/preload, and defer-fallback phases for the homepage.

The action allowlists performance settings and leaves license, CDN, cleanup,
bloat, object-cache, credentials, and unrelated plugin settings untouched. Its
probe returns bounded cacheability, cache-file existence/timestamps, and selected
configuration state; it never returns absolute paths or secrets.
