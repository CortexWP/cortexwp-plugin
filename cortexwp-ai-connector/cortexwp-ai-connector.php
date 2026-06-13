<?php
/**
 * Plugin Name: CortexWP Connector
 * Description: Securely connects a WordPress site to CortexWP.ai.
 * Version: 1.0.1
 * Author: CortexWP
 * Author URI: https://cortexwp.ai
 * Plugin URI: https://cortexwp.ai
 * Update URI: https://cortexwp.ai/cortexwp-ai-connector
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

final class CortexWP_AI_Connector {
    private const OPTION_KEY = 'cortexwp_ai_connector_key';
    private const OPTION_KEY_DOMAIN = 'cortexwp_ai_connector_key_domain';
    private const OPTION_SETTINGS = 'cortexwp_ai_connector_settings';
    private const ROUTE_NAMESPACE = 'cortexwp-ai/v1';
    private const SNIPPET_DIR = 'cortexwp-snippets';
    private const GITHUB_REPO = '';
    private const RELEASE_ASSET = 'cortexwp-ai-connector.zip';
    private const UPDATE_CACHE_KEY = 'cortexwp_ai_connector_release';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('admin_post_cortexwp_ai_save', [__CLASS__, 'save_settings']);
        add_action('admin_post_cortexwp_ai_save_snippet', [__CLASS__, 'save_snippet_from_admin']);
        add_action('admin_post_cortexwp_ai_delete_snippet', [__CLASS__, 'delete_snippet_from_admin']);
        add_action('admin_post_cortexwp_ai_toggle_snippet', [__CLASS__, 'toggle_snippet_from_admin']);
        add_action('wp_ajax_cortexwp_ai_regenerate_key', [__CLASS__, 'ajax_regenerate_key']);
        add_action('plugins_loaded', [__CLASS__, 'load_snippets'], 20);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_plugin_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_update_details'], 10, 3);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
    }

    public static function activate(): void {
        if (!get_option(self::OPTION_KEY)) {
            self::store_key(self::new_key());
            update_option(self::OPTION_KEY_DOMAIN, self::current_domain(), false);
        } elseif (!get_option(self::OPTION_KEY_DOMAIN)) {
            update_option(self::OPTION_KEY_DOMAIN, self::current_domain(), false);
        }

        if (!get_option(self::OPTION_SETTINGS)) {
            update_option(self::OPTION_SETTINGS, [
                'connection_method' => 'plugin',
            ], false);
        }

        self::ensure_snippet_dir();
    }

    private static function new_key(): string {
        return 'cwp_' . bin2hex(random_bytes(32));
    }

    private static function plugin_version(): string {
        if (!function_exists('get_file_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $headers = get_file_data(__FILE__, ['Version' => 'Version'], 'plugin');
        return (string) ($headers['Version'] ?: '0.0.0');
    }

    private static function github_repo(): string {
        if (defined('CORTEXWP_AI_CONNECTOR_GITHUB_REPO')) {
            return trim((string) CORTEXWP_AI_CONNECTOR_GITHUB_REPO);
        }

        return self::GITHUB_REPO;
    }

    private static function github_headers(): array {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'CortexWP-AI-Connector/' . self::plugin_version(),
        ];

        if (defined('CORTEXWP_AI_CONNECTOR_GITHUB_TOKEN') && CORTEXWP_AI_CONNECTOR_GITHUB_TOKEN) {
            $headers['Authorization'] = 'Bearer ' . trim((string) CORTEXWP_AI_CONNECTOR_GITHUB_TOKEN);
        }

        return $headers;
    }

    private static function latest_github_release(bool $force = false): ?array {
        $repo = self::github_repo();

        if ($repo === '' || strpos($repo, '/') === false) {
            return null;
        }

        if (!$force) {
            $cached = get_site_transient(self::UPDATE_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $repo_path = implode('/', array_map('rawurlencode', explode('/', $repo, 2)));
        $response = wp_remote_get('https://api.github.com/repos/' . $repo_path . '/releases/latest', [
            'headers' => self::github_headers(),
            'timeout' => 12,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($payload) || !empty($payload['draft']) || !empty($payload['prerelease'])) {
            return null;
        }

        $download_url = '';
        foreach ((array) ($payload['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') === self::RELEASE_ASSET && !empty($asset['browser_download_url'])) {
                $download_url = (string) $asset['browser_download_url'];
                break;
            }
        }

        if ($download_url === '') {
            return null;
        }

        $release = [
            'version' => ltrim((string) ($payload['tag_name'] ?? ''), 'vV'),
            'name' => (string) ($payload['name'] ?? 'CortexWP Connector'),
            'body' => (string) ($payload['body'] ?? ''),
            'published_at' => (string) ($payload['published_at'] ?? ''),
            'html_url' => (string) ($payload['html_url'] ?? 'https://github.com/' . $repo),
            'download_url' => $download_url,
        ];

        if ($release['version'] === '') {
            return null;
        }

        set_site_transient(self::UPDATE_CACHE_KEY, $release, 6 * HOUR_IN_SECONDS);

        return $release;
    }

    public static function check_for_plugin_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $release = self::latest_github_release();
        $plugin_file = plugin_basename(__FILE__);

        if (!$release || !version_compare($release['version'], self::plugin_version(), '>')) {
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $transient->response[$plugin_file] = (object) [
            'id' => self::github_repo(),
            'slug' => dirname($plugin_file),
            'plugin' => $plugin_file,
            'new_version' => $release['version'],
            'url' => $release['html_url'],
            'package' => $release['download_url'],
            'tested' => '6.8',
            'requires' => '6.0',
            'requires_php' => '7.4',
        ];

        return $transient;
    }

    public static function plugin_update_details($result, string $action, object $args) {
        $plugin_file = plugin_basename(__FILE__);

        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname($plugin_file)) {
            return $result;
        }

        $release = self::latest_github_release(true);

        if (!$release) {
            return $result;
        }

        return (object) [
            'name' => 'CortexWP Connector',
            'slug' => dirname($plugin_file),
            'version' => $release['version'],
            'author' => '<a href="https://cortexwp.ai">CortexWP</a>',
            'homepage' => 'https://cortexwp.ai',
            'download_link' => $release['download_url'],
            'last_updated' => $release['published_at'],
            'requires' => '6.0',
            'tested' => '6.8',
            'requires_php' => '7.4',
            'sections' => [
                'description' => 'Securely connects a WordPress site to CortexWP.ai.',
                'changelog' => $release['body'] !== '' ? nl2br(esc_html($release['body'])) : 'See the GitHub release for details.',
            ],
        ];
    }

    public static function admin_menu(): void {
        add_menu_page(
            'CortexWP',
            'CortexWP',
            'manage_options',
            'cortexwp-ai',
            [__CLASS__, 'settings_page'],
            'dashicons-superhero-alt',
            3
        );

        add_submenu_page(
            'cortexwp-ai',
            'Connection',
            'Connection',
            'manage_options',
            'cortexwp-ai',
            [__CLASS__, 'settings_page']
        );

        add_submenu_page(
            'cortexwp-ai',
            'PHP Snippets',
            'PHP Snippets',
            'manage_options',
            'cortexwp-ai-snippets',
            [__CLASS__, 'snippets_page']
        );
    }

    public static function admin_assets(string $hook): void {
        if (!in_array($hook, ['toplevel_page_cortexwp-ai', 'cortexwp_page_cortexwp-ai-snippets'], true)) {
            return;
        }

        wp_register_style('cortexwp-ai-admin', false, [], self::plugin_version());
        wp_enqueue_style('cortexwp-ai-admin');
        wp_add_inline_style('cortexwp-ai-admin', self::admin_css());

        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', self::admin_js());

        if ($hook === 'cortexwp_page_cortexwp-ai-snippets') {
            wp_enqueue_code_editor(['type' => 'application/x-httpd-php']);
            wp_enqueue_script('code-editor');
            wp_enqueue_style('code-editor');
        }
    }

    private static function admin_css(): string {
        return <<<'CSS'
.cortexwp-admin {
    --cwp-ink: #111827;
    --cwp-muted: #5b6472;
    --cwp-line: #d8dee8;
    --cwp-panel: #ffffff;
    --cwp-soft: #f6f8fb;
    --cwp-accent: #2563eb;
    --cwp-accent-dark: #1d4ed8;
    --cwp-good: #047857;
    --cwp-danger: #b42318;
    color: var(--cwp-ink);
}
.cortexwp-admin * {
    box-sizing: border-box;
}
.cortexwp-shell {
    max-width: 1180px;
    margin-top: 24px;
}
.cortexwp-hero,
.cortexwp-panel,
.cortexwp-editor-panel {
    background: var(--cwp-panel);
    border: 1px solid var(--cwp-line);
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
}
.cortexwp-hero {
    display: flex;
    justify-content: space-between;
    gap: 24px;
    align-items: center;
    padding: 28px;
}
.cortexwp-eyebrow {
    margin: 0 0 8px;
    color: var(--cwp-accent);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
}
.cortexwp-hero h1,
.cortexwp-hero h2,
.cortexwp-section-title {
    margin: 0;
    color: var(--cwp-ink);
    font-size: 26px;
    line-height: 1.2;
}
.cortexwp-hero-copy {
    max-width: 700px;
    margin: 10px 0 0;
    color: var(--cwp-muted);
    font-size: 14px;
    line-height: 1.7;
}
.cortexwp-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
    padding: 7px 12px;
    border: 1px solid var(--cwp-line);
    border-radius: 999px;
    background: var(--cwp-soft);
    color: var(--cwp-muted);
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}
.cortexwp-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(280px, .9fr);
    gap: 18px;
    margin-top: 18px;
}
.cortexwp-panel,
.cortexwp-editor-panel {
    padding: 22px;
}
.cortexwp-key-card {
    display: grid;
    gap: 16px;
}
.cortexwp-key-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 10px;
    align-items: center;
}
.cortexwp-key-input,
.cortexwp-input,
.cortexwp-textarea {
    width: 100%;
    min-height: 42px;
    border: 1px solid var(--cwp-line) !important;
    border-radius: 8px;
    background: #fff !important;
    color: var(--cwp-ink) !important;
    box-shadow: none !important;
}
.cortexwp-key-input {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 13px;
}
.cortexwp-button {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 40px;
    padding: 0 14px !important;
    border: 1px solid var(--cwp-line) !important;
    border-radius: 8px !important;
    background: #fff !important;
    color: var(--cwp-ink) !important;
    font-weight: 700 !important;
    box-shadow: none !important;
}
.cortexwp-button-primary {
    border-color: var(--cwp-accent) !important;
    background: var(--cwp-accent) !important;
    color: #fff !important;
}
.cortexwp-button-primary:hover {
    background: var(--cwp-accent-dark) !important;
    color: #fff !important;
}
.cortexwp-button-danger {
    color: var(--cwp-danger) !important;
}
.cortexwp-meta-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 16px;
}
.cortexwp-meta-item {
    padding: 14px;
    border: 1px solid var(--cwp-line);
    border-radius: 8px;
    background: var(--cwp-soft);
}
.cortexwp-meta-label {
    display: block;
    margin-bottom: 6px;
    color: var(--cwp-muted);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
}
.cortexwp-meta-value {
    display: block;
    color: var(--cwp-ink);
    font-size: 14px;
    font-weight: 700;
    word-break: break-word;
}
.cortexwp-notice {
    margin: 18px 0 0;
    padding: 12px 14px;
    border-left: 4px solid var(--cwp-danger);
    background: #fff7ed;
    color: #7c2d12;
}
.cortexwp-status {
    min-height: 22px;
    color: var(--cwp-muted);
    font-size: 13px;
}
.cortexwp-status.is-success {
    color: var(--cwp-good);
}
.cortexwp-status.is-error {
    color: var(--cwp-danger);
}
.cortexwp-snippet-toolbar {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    margin: 18px 0;
}
.cortexwp-stat-row {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 18px;
}
.cortexwp-stat {
    padding: 16px;
    border: 1px solid var(--cwp-line);
    border-radius: 8px;
    background: var(--cwp-soft);
}
.cortexwp-stat strong {
    display: block;
    font-size: 24px;
    line-height: 1;
}
.cortexwp-stat span {
    display: block;
    margin-top: 8px;
    color: var(--cwp-muted);
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
}
.cortexwp-snippet-list {
    display: grid;
    gap: 10px;
}
.cortexwp-snippet-card {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 16px;
    align-items: center;
    padding: 16px;
    border: 1px solid var(--cwp-line);
    border-radius: 10px;
    background: #fff;
}
.cortexwp-snippet-title {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
}
.cortexwp-snippet-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
    color: var(--cwp-muted);
    font-size: 12px;
}
.cortexwp-badge {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 3px 9px;
    border-radius: 999px;
    background: #eef2ff;
    color: #3730a3;
    font-size: 12px;
    font-weight: 800;
}
.cortexwp-badge-enabled {
    background: #ecfdf3;
    color: var(--cwp-good);
}
.cortexwp-badge-disabled {
    background: #fef3f2;
    color: var(--cwp-danger);
}
.cortexwp-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}
.cortexwp-actions form {
    margin: 0;
}
.cortexwp-empty {
    padding: 34px;
    border: 1px dashed var(--cwp-line);
    border-radius: 10px;
    background: var(--cwp-soft);
    color: var(--cwp-muted);
    text-align: center;
}
.cortexwp-editor-grid {
    display: grid;
    gap: 16px;
}
.cortexwp-label {
    display: block;
    margin-bottom: 7px;
    color: var(--cwp-ink);
    font-size: 13px;
    font-weight: 800;
}
.cortexwp-field-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 16px;
    align-items: end;
}
.cortexwp-toggle {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    min-height: 42px;
    padding: 0 12px;
    border: 1px solid var(--cwp-line);
    border-radius: 8px;
    background: var(--cwp-soft);
    font-weight: 700;
}
.cortexwp-textarea {
    min-height: 420px;
    padding: 12px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}
@media (max-width: 782px) {
    .cortexwp-hero,
    .cortexwp-snippet-toolbar,
    .cortexwp-snippet-card {
        display: block;
    }
    .cortexwp-grid,
    .cortexwp-key-row,
    .cortexwp-meta-grid,
    .cortexwp-stat-row,
    .cortexwp-field-row {
        grid-template-columns: 1fr;
    }
    .cortexwp-actions {
        justify-content: flex-start;
        margin-top: 14px;
    }
}
CSS;
    }

    private static function admin_js(): string {
        return <<<'JS'
(function ($) {
    function setStatus(message, type) {
        var $status = $('#cortexwp-key-status');
        $status.removeClass('is-success is-error');
        if (type) {
            $status.addClass('is-' + type);
        }
        $status.text(message || '');
    }

    function copyValue(value) {
        if (window.navigator && window.navigator.clipboard) {
            return window.navigator.clipboard.writeText(value);
        }

        var $temp = $('<textarea>');
        $('body').append($temp);
        $temp.val(value).select();
        document.execCommand('copy');
        $temp.remove();
        return $.Deferred().resolve().promise();
    }

    $(document).on('click', '#cortexwp-copy-key', function () {
        var value = $('#cortexwp-api-key').val();
        $.when(copyValue(value)).done(function () {
            setStatus('API key copied to clipboard.', 'success');
        }).fail(function () {
            setStatus('Copy failed. Select the key and copy it manually.', 'error');
        });
    });

    $(document).on('click', '#cortexwp-regenerate-key', function () {
        var $button = $(this);
        var confirmText = 'Regenerate the CortexWP API key? Any saved CortexWP connection using the old key will need to be updated.';

        if (!window.confirm(confirmText)) {
            return;
        }

        $button.prop('disabled', true).text('Regenerating...');
        setStatus('Generating a new API key...', '');

        $.post(ajaxurl, {
            action: 'cortexwp_ai_regenerate_key',
            nonce: $('#cortexwp-regenerate-nonce').val()
        }).done(function (response) {
            if (!response || !response.success) {
                setStatus(response && response.data && response.data.message ? response.data.message : 'Unable to regenerate the API key.', 'error');
                return;
            }

            $('#cortexwp-api-key').val(response.data.key);
            $('#cortexwp-key-domain').text(response.data.domain);
            $('#cortexwp-current-domain').text(response.data.current_domain);
            setStatus('New API key generated. Copy it into CortexWP to reconnect this site.', 'success');
        }).fail(function () {
            setStatus('Unable to regenerate the API key. Please try again.', 'error');
        }).always(function () {
            $button.prop('disabled', false).text('Regenerate key');
        });
    });
})(jQuery);
JS;
    }

    public static function settings_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage CortexWP.', 'cortexwp-ai'));
        }

        $key = self::connector_key();
        $key_domain = self::key_domain();
        $current_domain = self::current_domain();
        $domain_mismatch = $key_domain !== $current_domain;
        ?>
        <div class="wrap cortexwp-admin cortexwp-shell">
            <div class="cortexwp-hero">
                <div>
                    <p class="cortexwp-eyebrow">Secure connector</p>
                    <h1>CortexWP Connector</h1>
                    <p class="cortexwp-hero-copy">Use this API key to connect this WordPress site to CortexWP. The key is encrypted at rest and bound to the current site domain.</p>
                </div>
                <a class="cortexwp-pill" href="https://cortexwp.ai" target="_blank" rel="noopener noreferrer">cortexwp.ai</a>
            </div>

            <?php if ($domain_mismatch) : ?>
                <div class="cortexwp-notice">
                    <p>The connector key is bound to <?php echo esc_html($key_domain); ?>, but this site is currently <?php echo esc_html($current_domain); ?>. Regenerate the key before connecting this migrated domain.</p>
                </div>
            <?php endif; ?>

            <div class="cortexwp-grid">
                <section class="cortexwp-panel cortexwp-key-card" aria-labelledby="cortexwp-connection-key-title">
                    <div>
                        <p class="cortexwp-eyebrow">Connection key</p>
                        <h2 id="cortexwp-connection-key-title" class="cortexwp-section-title">Copy your CortexWP API key</h2>
                        <p class="cortexwp-hero-copy">Paste this key into the CortexWP app with this site's WordPress URL. Regenerate it if the key was shared, exposed, or the site moved to a new domain.</p>
                    </div>

                    <div class="cortexwp-key-row">
                        <input id="cortexwp-api-key" type="text" readonly class="cortexwp-key-input code" value="<?php echo esc_attr($key); ?>" onclick="this.select();" aria-label="CortexWP API key" />
                        <button type="button" id="cortexwp-copy-key" class="button cortexwp-button">Copy key</button>
                        <button type="button" id="cortexwp-regenerate-key" class="button cortexwp-button cortexwp-button-primary">Regenerate key</button>
                    </div>

                    <input type="hidden" id="cortexwp-regenerate-nonce" value="<?php echo esc_attr(wp_create_nonce('cortexwp_ai_regenerate_key')); ?>" />
                    <div id="cortexwp-key-status" class="cortexwp-status" role="status" aria-live="polite"></div>
                </section>

                <aside class="cortexwp-panel" aria-labelledby="cortexwp-site-details-title">
                    <p class="cortexwp-eyebrow">Site details</p>
                    <h2 id="cortexwp-site-details-title" class="cortexwp-section-title">Connector status</h2>
                    <div class="cortexwp-meta-grid">
                        <div class="cortexwp-meta-item">
                            <span class="cortexwp-meta-label">WordPress URL</span>
                            <span class="cortexwp-meta-value"><?php echo esc_html(home_url()); ?></span>
                        </div>
                        <div class="cortexwp-meta-item">
                            <span class="cortexwp-meta-label">Key domain</span>
                            <span id="cortexwp-key-domain" class="cortexwp-meta-value"><?php echo esc_html($key_domain); ?></span>
                        </div>
                        <div class="cortexwp-meta-item">
                            <span class="cortexwp-meta-label">Current domain</span>
                            <span id="cortexwp-current-domain" class="cortexwp-meta-value"><?php echo esc_html($current_domain); ?></span>
                        </div>
                        <div class="cortexwp-meta-item">
                            <span class="cortexwp-meta-label">REST endpoint</span>
                            <span class="cortexwp-meta-value"><?php echo esc_html(rest_url(self::ROUTE_NAMESPACE . '/status')); ?></span>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }

    public static function snippets_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage CortexWP snippets.', 'cortexwp-ai'));
        }

        $snippets = self::list_snippets();
        $editing_slug = isset($_GET['snippet']) ? self::snippet_slug((string) wp_unslash($_GET['snippet'])) : '';
        $editing = $editing_slug ? self::get_snippet($editing_slug) : null;
        $is_editing = is_array($editing);
        $enabled_count = count(array_filter($snippets, static fn($snippet) => !empty($snippet['enabled'])));
        $disabled_count = max(count($snippets) - $enabled_count, 0);
        ?>
        <div class="wrap cortexwp-admin cortexwp-shell">
            <div class="cortexwp-hero">
                <div>
                    <p class="cortexwp-eyebrow">Managed PHP snippets</p>
                    <h1>CortexWP PHP Snippets</h1>
                    <p class="cortexwp-hero-copy">Create, review, and control PHP snippets loaded by the CortexWP connector. Snippets saved from CortexWP and snippets created here use the same filesystem registry.</p>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cortexwp-ai-snippets&new=1')); ?>" class="button button-primary cortexwp-button cortexwp-button-primary">Add snippet</a>
            </div>

            <div class="cortexwp-stat-row">
                <div class="cortexwp-stat">
                    <strong><?php echo esc_html((string) count($snippets)); ?></strong>
                    <span>Total snippets</span>
                </div>
                <div class="cortexwp-stat">
                    <strong><?php echo esc_html((string) $enabled_count); ?></strong>
                    <span>Enabled</span>
                </div>
                <div class="cortexwp-stat">
                    <strong><?php echo esc_html((string) $disabled_count); ?></strong>
                    <span>Disabled</span>
                </div>
            </div>

            <?php if ($is_editing || isset($_GET['new'])) : ?>
                <section class="cortexwp-editor-panel" style="margin-top:18px;" aria-labelledby="cortexwp-snippet-editor-title">
                    <p class="cortexwp-eyebrow"><?php echo $is_editing ? 'Edit snippet' : 'New snippet'; ?></p>
                    <h2 id="cortexwp-snippet-editor-title" class="cortexwp-section-title"><?php echo $is_editing ? 'Edit snippet' : 'Add snippet'; ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('cortexwp_ai_save_snippet'); ?>
                        <input type="hidden" name="action" value="cortexwp_ai_save_snippet" />
                        <input type="hidden" name="snippet_slug" value="<?php echo esc_attr($editing['slug'] ?? ''); ?>" />
                        <div class="cortexwp-editor-grid" style="margin-top:16px;">
                            <div class="cortexwp-field-row">
                                <label>
                                    <span class="cortexwp-label">Snippet title</span>
                                    <input id="cortexwp_snippet_title" name="snippet_title" type="text" class="cortexwp-input" value="<?php echo esc_attr($editing['title'] ?? ''); ?>" required />
                                </label>
                                <label class="cortexwp-toggle" for="cortexwp_snippet_enabled">
                                    <input id="cortexwp_snippet_enabled" name="snippet_enabled" type="checkbox" value="1" <?php checked(($editing['enabled'] ?? true), true); ?> />
                                    Load snippet
                                </label>
                            </div>
                            <label>
                                <span class="cortexwp-label">PHP code</span>
                                <textarea id="cortexwp_snippet_code" name="snippet_code" class="cortexwp-textarea code" rows="18" required><?php echo esc_textarea($editing['code'] ?? "<?php\n"); ?></textarea>
                            </label>
                            <div class="cortexwp-actions" style="justify-content:flex-start;">
                                <button type="submit" class="button button-primary cortexwp-button cortexwp-button-primary"><?php echo $is_editing ? 'Update snippet' : 'Save snippet'; ?></button>
                                <a class="button cortexwp-button" href="<?php echo esc_url(admin_url('admin.php?page=cortexwp-ai-snippets')); ?>">Cancel</a>
                            </div>
                        </div>
                    </form>
                </section>
                <script>
                    window.addEventListener('load', function () {
                        if (window.wp && wp.codeEditor) {
                            wp.codeEditor.initialize('cortexwp_snippet_code', {});
                        }
                    });
                </script>
            <?php endif; ?>

            <div class="cortexwp-snippet-toolbar">
                <div>
                    <p class="cortexwp-eyebrow">Registry</p>
                    <h2 class="cortexwp-section-title">Tracked snippets</h2>
                </div>
                <span class="cortexwp-pill"><?php echo esc_html(self::snippet_dir()); ?></span>
            </div>

            <?php if ($snippets) : ?>
                <div class="cortexwp-snippet-list">
                    <?php foreach ($snippets as $snippet) : ?>
                        <article class="cortexwp-snippet-card">
                            <div>
                                <h3 class="cortexwp-snippet-title"><?php echo esc_html($snippet['title']); ?></h3>
                                <div class="cortexwp-snippet-meta">
                                    <span class="cortexwp-badge <?php echo $snippet['enabled'] ? 'cortexwp-badge-enabled' : 'cortexwp-badge-disabled'; ?>"><?php echo $snippet['enabled'] ? 'Enabled' : 'Disabled'; ?></span>
                                    <span class="cortexwp-badge">Source: <?php echo esc_html($snippet['source']); ?></span>
                                    <span><code><?php echo esc_html($snippet['file']); ?></code></span>
                                    <span>Modified <?php echo esc_html(get_date_from_gmt($snippet['modified'], 'M j, Y g:i a')); ?></span>
                                </div>
                            </div>
                            <div class="cortexwp-actions">
                                <a class="button cortexwp-button" href="<?php echo esc_url(admin_url('admin.php?page=cortexwp-ai-snippets&snippet=' . rawurlencode($snippet['slug']))); ?>">Edit</a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('cortexwp_ai_toggle_snippet'); ?>
                                    <input type="hidden" name="action" value="cortexwp_ai_toggle_snippet" />
                                    <input type="hidden" name="snippet" value="<?php echo esc_attr($snippet['slug']); ?>" />
                                    <input type="hidden" name="enabled" value="<?php echo $snippet['enabled'] ? '0' : '1'; ?>" />
                                    <button type="submit" class="button cortexwp-button"><?php echo $snippet['enabled'] ? 'Disable' : 'Enable'; ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this snippet?');">
                                    <?php wp_nonce_field('cortexwp_ai_delete_snippet'); ?>
                                    <input type="hidden" name="action" value="cortexwp_ai_delete_snippet" />
                                    <input type="hidden" name="snippet" value="<?php echo esc_attr($snippet['slug']); ?>" />
                                    <button type="submit" class="button cortexwp-button cortexwp-button-danger">Delete</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="cortexwp-empty">
                    <strong>No snippets yet.</strong>
                    <p>Create your first managed PHP snippet or let CortexWP add one during an approved task.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage CortexWP.', 'cortexwp-ai'));
        }

        check_admin_referer('cortexwp_ai_save');

        if (!empty($_POST['regenerate_key'])) {
            self::store_key(self::new_key());
            update_option(self::OPTION_KEY_DOMAIN, self::current_domain(), false);
        }

        update_option(self::OPTION_SETTINGS, [
            'connection_method' => 'plugin',
        ], false);

        wp_safe_redirect(admin_url('admin.php?page=cortexwp-ai&updated=1'));
        exit;
    }

    public static function ajax_regenerate_key(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to manage CortexWP.'], 403);
        }

        check_ajax_referer('cortexwp_ai_regenerate_key', 'nonce');

        $key = self::new_key();
        $domain = self::current_domain();

        self::store_key($key);
        update_option(self::OPTION_KEY_DOMAIN, $domain, false);
        update_option(self::OPTION_SETTINGS, [
            'connection_method' => 'plugin',
        ], false);

        wp_send_json_success([
            'key' => $key,
            'domain' => $domain,
            'current_domain' => $domain,
        ]);
    }

    public static function save_snippet_from_admin(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage CortexWP snippets.', 'cortexwp-ai'));
        }

        check_admin_referer('cortexwp_ai_save_snippet');

        $result = self::save_snippet(
            (string) wp_unslash($_POST['snippet_title'] ?? ''),
            (string) wp_unslash($_POST['snippet_code'] ?? ''),
            [
                'slug' => (string) wp_unslash($_POST['snippet_slug'] ?? ''),
                'enabled' => !empty($_POST['snippet_enabled']),
                'source' => 'admin',
            ]
        );

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=cortexwp-ai-snippets&updated=1'));
        exit;
    }

    public static function toggle_snippet_from_admin(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage CortexWP snippets.', 'cortexwp-ai'));
        }

        check_admin_referer('cortexwp_ai_toggle_snippet');

        $result = self::toggle_snippet(
            (string) wp_unslash($_POST['snippet'] ?? ''),
            !empty($_POST['enabled'])
        );

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=cortexwp-ai-snippets&updated=1'));
        exit;
    }

    public static function delete_snippet_from_admin(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage CortexWP snippets.', 'cortexwp-ai'));
        }

        check_admin_referer('cortexwp_ai_delete_snippet');

        $result = self::delete_snippet((string) wp_unslash($_POST['snippet'] ?? ''));

        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        wp_safe_redirect(admin_url('admin.php?page=cortexwp-ai-snippets&deleted=1'));
        exit;
    }

    public static function register_routes(): void {
        register_rest_route(self::ROUTE_NAMESPACE, '/status', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'status'],
            'permission_callback' => [__CLASS__, 'verify_request'],
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, '/action', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'action'],
            'permission_callback' => [__CLASS__, 'verify_request'],
        ]);
    }

    public static function verify_request(WP_REST_Request $request) {
        $key = self::connector_key();
        $key_domain = self::key_domain();
        $current_domain = self::current_domain();
        $timestamp = (string) $request->get_header('x-cortexwp-timestamp');
        $nonce = (string) $request->get_header('x-cortexwp-nonce');
        $provided = (string) $request->get_header('x-cortexwp-signature');

        if ($key_domain !== $current_domain) {
            return new WP_Error('cortexwp_ai_domain_changed', 'This connector key is bound to a different domain. Regenerate it in WordPress.', ['status' => 403]);
        }

        if (!$key || !$timestamp || !$nonce || !$provided || abs(time() - (int) $timestamp) > 300) {
            return new WP_Error('cortexwp_ai_forbidden', 'Invalid CortexWP authentication.', ['status' => 403]);
        }

        $nonce_key = 'cortexwp_ai_nonce_' . hash('sha256', $nonce);
        if (get_transient($nonce_key)) {
            return new WP_Error('cortexwp_ai_forbidden', 'Replay detected.', ['status' => 403]);
        }

        $path = '/' . rest_get_url_prefix() . $request->get_route();
        $body_hash = hash('sha256', (string) $request->get_body());
        $payload = implode("\n", [$request->get_method(), $path, $body_hash, $timestamp, $nonce]);
        $expected = hash_hmac('sha256', $payload, $key);

        if (!hash_equals($expected, $provided)) {
            return new WP_Error('cortexwp_ai_forbidden', 'Invalid CortexWP signature.', ['status' => 403]);
        }

        set_transient($nonce_key, 1, 5 * MINUTE_IN_SECONDS);

        return true;
    }

    public static function status(WP_REST_Request $request): WP_REST_Response {
        return rest_ensure_response([
            'site_url' => site_url(),
            'home_url' => home_url(),
            'key_domain' => self::key_domain(),
            'wordpress_path' => ABSPATH,
            'connection_method' => 'plugin',
            'webserver_user' => self::webserver_user(),
            'capabilities' => self::capabilities(),
            'sftp' => null,
        ]);
    }

    public static function action(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $action = sanitize_key($params['action'] ?? '');

        switch ($action) {
            case 'wp_cli':
                return rest_ensure_response(self::run_wp_cli((array) ($params['args'] ?? []), (int) ($params['max_output_bytes'] ?? 500000)));
            case 'shell':
                return rest_ensure_response(self::run_shell((string) ($params['command'] ?? ''), (string) ($params['cwd'] ?? ABSPATH), (int) ($params['max_output_bytes'] ?? 500000)));
            case 'read_file':
                return rest_ensure_response(self::read_file((string) ($params['path'] ?? ''), (int) ($params['lines'] ?? 120), (int) ($params['max_output_bytes'] ?? 500000)));
            case 'list_directory':
                return rest_ensure_response(self::list_directory((string) ($params['path'] ?? ABSPATH)));
            case 'list_snippets':
                return rest_ensure_response(['snippets' => self::list_snippets()]);
            case 'save_snippet':
                $result = self::save_snippet(
                    (string) ($params['title'] ?? $params['name'] ?? ''),
                    (string) ($params['code'] ?? ''),
                    [
                        'slug' => (string) ($params['slug'] ?? $params['name'] ?? ''),
                        'enabled' => array_key_exists('enabled', $params) ? (bool) $params['enabled'] : true,
                        'source' => 'ai',
                    ]
                );
                return is_wp_error($result) ? $result : rest_ensure_response($result);
            case 'toggle_snippet':
                $result = self::toggle_snippet((string) ($params['name'] ?? ''), (bool) ($params['enabled'] ?? true));
                return is_wp_error($result) ? $result : rest_ensure_response($result);
            case 'delete_snippet':
                $result = self::delete_snippet((string) ($params['name'] ?? ''));
                return is_wp_error($result) ? $result : rest_ensure_response($result);
        }

        return new WP_Error('cortexwp_ai_unknown_action', 'Unknown CortexWP action.', ['status' => 400]);
    }

    private static function capabilities(): array {
        return [
            'can_write_wp_content' => is_writable(WP_CONTENT_DIR),
            'can_write_mu_plugins' => is_dir(WPMU_PLUGIN_DIR) ? is_writable(WPMU_PLUGIN_DIR) : is_writable(WP_CONTENT_DIR),
            'can_write_cortexwp_snippets' => self::ensure_snippet_dir(),
            'exec_available' => self::exec_available(),
            'wp_cli_available' => self::exec_available() && trim((string) shell_exec('command -v wp 2>/dev/null')) !== '',
        ];
    }

    public static function load_snippets(): void {
        foreach (self::snippet_files() as $file) {
            $snippet = self::read_snippet_file($file);
            if (!empty($snippet['enabled'])) {
                require_once $file;
            }
        }
    }

    private static function webserver_user(): string {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            return is_array($user) ? (string) ($user['name'] ?? '') : '';
        }

        return get_current_user();
    }

    private static function current_domain(): string {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return strtolower(trim((string) $host, ". \t\n\r\0\x0B"));
    }

    private static function snippet_dir(): string {
        return trailingslashit(WP_CONTENT_DIR) . self::SNIPPET_DIR;
    }

    private static function ensure_snippet_dir(): bool {
        $dir = self::snippet_dir();

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        if (!is_writable($dir)) {
            return false;
        }

        self::write_snippet_directory_protection_files($dir);

        return true;
    }

    private static function write_snippet_directory_protection_files(string $dir): void {
        $files = [
            '.htaccess' => implode("\n", [
                'Options -Indexes',
                '<IfModule mod_authz_core.c>',
                'Require all denied',
                '</IfModule>',
                '<IfModule !mod_authz_core.c>',
                'Deny from all',
                '</IfModule>',
                '',
            ]),
            'index.php' => "<?php\nexit;\n",
            'web.config' => implode("\n", [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<configuration>',
                '  <system.webServer>',
                '    <security>',
                '      <authorization>',
                '        <add accessType="Deny" users="*" />',
                '      </authorization>',
                '    </security>',
                '  </system.webServer>',
                '</configuration>',
                '',
            ]),
        ];

        foreach ($files as $name => $contents) {
            $path = trailingslashit($dir) . $name;
            if (!is_file($path) || is_writable($path)) {
                file_put_contents($path, $contents);
            }
        }
    }

    private static function snippet_slug(string $name): string {
        $slug = sanitize_file_name(sanitize_title($name));
        return trim($slug, '-_');
    }

    private static function snippet_path(string $name): string {
        return trailingslashit(self::snippet_dir()) . self::snippet_slug($name) . '.php';
    }

    private static function snippet_files(): array {
        $dir = self::snippet_dir();

        if (!is_dir($dir)) {
            return [];
        }

        $files = glob(trailingslashit($dir) . '*.php') ?: [];
        sort($files);
        return array_values(array_filter($files, 'is_readable'));
    }

    private static function list_snippets(): array {
        return array_map([__CLASS__, 'read_snippet_file'], self::snippet_files());
    }

    private static function get_snippet(string $slug): ?array {
        $path = self::snippet_path($slug);
        return is_file($path) ? self::read_snippet_file($path) : null;
    }

    private static function read_snippet_file(string $file): array {
        $slug = basename($file, '.php');
        $contents = is_readable($file) ? (string) file_get_contents($file) : '';
        $meta = self::parse_snippet_metadata($contents);

        return [
            'slug' => $slug,
            'title' => $meta['title'] ?: ucwords(str_replace(['-', '_'], ' ', $slug)),
            'enabled' => $meta['enabled'] !== 'no',
            'source' => $meta['source'] ?: 'unknown',
            'code' => self::strip_snippet_header($contents),
            'file' => str_replace(trailingslashit(WP_CONTENT_DIR), 'wp-content/', $file),
            'modified' => gmdate('c', filemtime($file) ?: time()),
        ];
    }

    private static function parse_snippet_metadata(string $contents): array {
        $metadata = [
            'title' => '',
            'enabled' => '',
            'source' => '',
        ];

        foreach ($metadata as $key => $_value) {
            $label = str_replace(' ', '\\s+', ucwords(str_replace('_', ' ', $key)));
            if (preg_match('/CortexWP\\s+' . $label . ':\\s*(.+)$/mi', $contents, $matches)) {
                $metadata[$key] = trim($matches[1]);
            }
        }

        if (!$metadata['title'] && preg_match('/CortexWP\\s+Snippet:\\s*(.+)$/mi', $contents, $matches)) {
            $metadata['title'] = trim($matches[1]);
        }

        return $metadata;
    }

    private static function strip_snippet_header(string $contents): string {
        $contents = preg_replace('/^<\\?php\\s*\\/\\*\\*.*?\\*\\/\\s*/s', "<?php\n", $contents) ?? $contents;
        $contents = preg_replace('/if\\s*\\(\\s*!\\s*defined\\(\\s*[\'"]ABSPATH[\'"]\\s*\\)\\s*\\)\\s*\\{\\s*exit;?\\s*\\}\\s*/', '', $contents) ?? $contents;
        return trim($contents) ?: "<?php\n";
    }

    private static function save_snippet(string $title, string $code, array $options = []) {
        $requested_slug = trim((string) ($options['slug'] ?? ''));
        $slug = self::snippet_slug($requested_slug !== '' ? $requested_slug : $title);

        if ($slug === '') {
            return new WP_Error('cortexwp_ai_invalid_snippet', 'Snippet name must include letters or numbers.', ['status' => 400]);
        }

        if (!self::ensure_snippet_dir()) {
            return new WP_Error('cortexwp_ai_snippet_dir', 'CortexWP snippets directory is not writable.', ['status' => 500]);
        }

        $title = trim($title) ?: ucwords(str_replace(['-', '_'], ' ', $slug));
        $enabled = array_key_exists('enabled', $options) ? (bool) $options['enabled'] : true;
        $source = sanitize_key((string) ($options['source'] ?? 'ai')) ?: 'ai';
        $php = self::format_snippet_file($title, $code, $enabled, $source);
        $path = self::snippet_path($slug);

        if (!self::php_lint($php)) {
            return new WP_Error('cortexwp_ai_snippet_syntax', 'Snippet failed PHP syntax validation.', ['status' => 400]);
        }

        $written = file_put_contents($path, $php);

        if ($written === false) {
            return new WP_Error('cortexwp_ai_snippet_write', 'Unable to write snippet.', ['status' => 500]);
        }

        return [
            'saved' => true,
            'slug' => $slug,
            'title' => $title,
            'enabled' => $enabled,
            'path' => str_replace(trailingslashit(WP_CONTENT_DIR), 'wp-content/', $path),
        ];
    }

    private static function toggle_snippet(string $name, bool $enabled) {
        $snippet = self::get_snippet(self::snippet_slug($name));

        if (!$snippet) {
            return new WP_Error('cortexwp_ai_missing_snippet', 'Snippet not found.', ['status' => 404]);
        }

        return self::save_snippet($snippet['title'], $snippet['code'], [
            'slug' => $snippet['slug'],
            'enabled' => $enabled,
            'source' => $snippet['source'],
        ]);
    }

    private static function delete_snippet(string $name) {
        $slug = self::snippet_slug($name);

        if ($slug === '') {
            return new WP_Error('cortexwp_ai_invalid_snippet', 'Snippet name is required.', ['status' => 400]);
        }

        $path = self::snippet_path($slug);

        if (is_file($path) && !unlink($path)) {
            return new WP_Error('cortexwp_ai_snippet_delete', 'Unable to delete snippet.', ['status' => 500]);
        }

        return ['deleted' => true, 'slug' => $slug];
    }

    private static function format_snippet_file(string $title, string $code, bool $enabled, string $source): string {
        $body = trim($code);
        $body = strpos($body, '<?php') === 0 ? preg_replace('/^<\\?php\\s*/', '', $body, 1) : $body;
        $body = trim((string) $body);

        return "<?php\n" .
            "/**\n" .
            " * CortexWP Snippet: " . str_replace(["\r", "\n"], ' ', $title) . "\n" .
            " * CortexWP Enabled: " . ($enabled ? 'yes' : 'no') . "\n" .
            " * CortexWP Source: " . $source . "\n" .
            " * CortexWP Updated: " . gmdate('c') . "\n" .
            " */\n" .
            "if (!defined('ABSPATH')) { exit; }\n\n" .
            $body . "\n";
    }

    private static function php_lint(string $php): bool {
        if (!self::exec_available()) {
            return true;
        }

        $tmp = wp_tempnam('cortexwp-snippet.php');
        if (!$tmp) {
            return false;
        }

        file_put_contents($tmp, $php);
        $result = self::command_result('php -l ' . escapeshellarg($tmp), dirname($tmp), 20000);
        unlink($tmp);

        return (int) $result['exit_code'] === 0;
    }

    private static function connector_key(): string {
        $stored = (string) get_option(self::OPTION_KEY, '');

        if ($stored === '') {
            $key = self::new_key();
            self::store_key($key);
            update_option(self::OPTION_KEY_DOMAIN, self::current_domain(), false);
            return $key;
        }

        $decrypted = self::decrypt_secret($stored);

        if ($decrypted !== null) {
            return $decrypted;
        }

        self::store_key($stored);
        return $stored;
    }

    private static function store_key(string $key): void {
        update_option(self::OPTION_KEY, self::encrypt_secret($key), false);
    }

    private static function encryption_key(): string {
        return hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    }

    private static function encrypt_secret(string $value): string {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required to encrypt the CortexWP connector key.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', self::encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt the CortexWP connector key.');
        }

        return implode(':', [
            'v1',
            base64_encode($iv),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    private static function decrypt_secret(string $value): ?string {
        if (strpos($value, 'v1:') !== 0 || !function_exists('openssl_decrypt')) {
            return null;
        }

        $parts = explode(':', $value, 4);

        if (count($parts) !== 4) {
            return null;
        }

        [, $iv, $tag, $ciphertext] = $parts;
        $decrypted = openssl_decrypt(
            base64_decode($ciphertext, true) ?: '',
            'aes-256-gcm',
            self::encryption_key(),
            OPENSSL_RAW_DATA,
            base64_decode($iv, true) ?: '',
            base64_decode($tag, true) ?: ''
        );

        return $decrypted === false ? null : $decrypted;
    }

    private static function key_domain(): string {
        $domain = (string) get_option(self::OPTION_KEY_DOMAIN, '');

        if ($domain === '') {
            $domain = self::current_domain();
            update_option(self::OPTION_KEY_DOMAIN, $domain, false);
        }

        return strtolower(trim($domain, ". \t\n\r\0\x0B"));
    }

    private static function exec_available(): bool {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('shell_exec') && function_exists('proc_open') && !in_array('shell_exec', $disabled, true) && !in_array('proc_open', $disabled, true);
    }

    private static function command_result(string $command, ?string $cwd, int $max_output_bytes): array {
        if (!self::exec_available()) {
            return [
                'command' => $command,
                'cwd' => $cwd,
                'exit_code' => 127,
                'stdout' => '',
                'stderr' => 'shell_exec/proc_open is disabled on this server.',
                'timed_out' => false,
            ];
        }

        $descriptor = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptor, $pipes, $cwd ?: ABSPATH);

        if (!is_resource($process)) {
            return [
                'command' => $command,
                'cwd' => $cwd,
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Unable to start command.',
                'timed_out' => false,
            ];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        return [
            'command' => $command,
            'cwd' => $cwd,
            'exit_code' => $exit_code,
            'stdout' => self::truncate((string) $stdout, $max_output_bytes),
            'stderr' => self::truncate((string) $stderr, $max_output_bytes),
            'timed_out' => false,
        ];
    }

    private static function run_wp_cli(array $args, int $max_output_bytes): array {
        $safe_args = array_values(array_filter(array_map(static fn($arg) => trim((string) $arg), $args)));

        if (!$safe_args) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'WP-CLI arguments are required.'];
        }

        $command = 'wp ' . implode(' ', array_map('escapeshellarg', $safe_args)) . ' --path=' . escapeshellarg(ABSPATH);
        return self::command_result($command, ABSPATH, $max_output_bytes);
    }

    private static function run_shell(string $command, string $cwd, int $max_output_bytes): array {
        $command = trim($command);

        if ($command === '') {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Command is required.'];
        }

        return self::command_result($command, self::safe_path($cwd), $max_output_bytes);
    }

    private static function read_file(string $path, int $lines, int $max_output_bytes): array {
        $file = self::safe_path($path);

        if (!$file || !is_file($file) || !is_readable($file)) {
            return ['path' => $path, 'found' => false, 'stdout' => '', 'stderr' => 'File not found or not readable.'];
        }

        $contents = file($file, FILE_IGNORE_NEW_LINES);
        $tail = array_slice($contents ?: [], -max(1, min(1000, $lines)));

        return [
            'path' => $file,
            'found' => true,
            'lines' => count($tail),
            'stdout' => self::truncate(implode("\n", $tail), $max_output_bytes),
            'stderr' => '',
            'exit_code' => 0,
        ];
    }

    private static function list_directory(string $path): array {
        $dir = self::safe_path($path ?: ABSPATH);

        if (!$dir || !is_dir($dir) || !is_readable($dir)) {
            return ['path' => $path, 'entries' => [], 'stderr' => 'Directory not found or not readable.', 'exit_code' => 1];
        }

        $entries = [];
        foreach (array_slice(scandir($dir) ?: [], 0, 300) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            $entries[] = sprintf('%s %s %s', is_dir($full) ? 'd' : '-', filesize($full) ?: 0, $full);
        }

        return ['path' => $dir, 'entries' => $entries, 'stderr' => '', 'exit_code' => 0];
    }

    private static function safe_path(string $path): string {
        $path = trim($path);
        $path = $path === '' ? ABSPATH : $path;
        $candidate = $path[0] === '/' || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) ? $path : ABSPATH . ltrim($path, '/\\');
        $real = realpath($candidate);
        $root = realpath(ABSPATH);

        if (!$real || !$root || strpos($real, $root) !== 0) {
            return '';
        }

        return $real;
    }

    private static function truncate(string $value, int $max_bytes): string {
        $max_bytes = max(1024, min(1000000, $max_bytes));
        return strlen($value) > $max_bytes ? substr($value, -$max_bytes) : $value;
    }
}

CortexWP_AI_Connector::boot();
