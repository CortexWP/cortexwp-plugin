# CortexWP Connector

WordPress connector plugin for CortexWP.

## Release and update flow

1. Update the `Version:` header in `cortexwp-ai-connector/cortexwp-ai-connector.php`.
2. Push to `main` or `master`.
3. GitHub Actions builds `cortexwp-ai-connector.zip` and creates or updates release `v{Version}`.
4. Installed WordPress sites check the latest GitHub release and show the plugin update when the release version is newer than their installed version.

Install the plugin from the generated release ZIP, not GitHub's source-code ZIP. The workflow injects the correct GitHub repo slug into the packaged plugin before publishing.

Use a public repository or public release asset for the normal WordPress updater flow. Private release downloads require a custom download proxy because WordPress does not pass GitHub API headers to the package URL.
