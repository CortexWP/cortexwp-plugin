<?php
/**
 * Plugin Name: CortexWP Connector
 * Description: Securely connects a WordPress site to CortexWP.ai.
 * Version: 1.2
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
    private const RELEASE_ASSET = 'cortexwp-ai-connector.zip';
    private const UPDATE_CACHE_KEY = 'cortexwp_ai_connector_release';
    private const DEFAULT_APP_URL = 'http://127.0.0.1:3000';
    private const FATAL_ERROR_ENDPOINT_PATH = '/api/wordpress/fatal-error';
    private static $reserved_memory = '';

    public static function boot(): void {
        self::$reserved_memory = str_repeat('x', 262144);
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
        register_shutdown_function([__CLASS__, 'capture_fatal_error']);
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
                'app_url' => self::cortexwp_app_url(),
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
        return 'CortexWP/cortexwp-plugin';
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

    private static function connector_settings(): array {
        $settings = get_option(self::OPTION_SETTINGS, []);
        return is_array($settings) ? $settings : [];
    }

    private static function cortexwp_app_url(): string {
        if (defined('CORTEXWP_AI_CONNECTOR_APP_URL') && CORTEXWP_AI_CONNECTOR_APP_URL) {
            return rtrim((string) CORTEXWP_AI_CONNECTOR_APP_URL, '/');
        }

        $settings = self::connector_settings();
        $app_url = isset($settings['app_url']) ? trim((string) $settings['app_url']) : '';

        return rtrim($app_url ?: self::DEFAULT_APP_URL, '/');
    }

    private static function fatal_error_endpoint(): string {
        return self::cortexwp_app_url() . self::FATAL_ERROR_ENDPOINT_PATH;
    }

    public static function capture_fatal_error(): void {
        self::$reserved_memory = '';

        $error = error_get_last();
        if (!is_array($error) || !self::should_report_error($error)) {
            return;
        }

        self::send_fatal_error(self::fatal_error_payload($error));
    }

    private static function should_report_error(array $error): bool {
        $type = (int) ($error['type'] ?? 0);

        return in_array($type, self::fatal_error_types(), true);
    }

    private static function fatal_error_types(): array {
        $types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

        if (defined('E_RECOVERABLE_ERROR')) {
            $types[] = E_RECOVERABLE_ERROR;
        }

        return array_values(array_unique(array_map('intval', $types)));
    }

    private static function error_type_label(int $type): string {
        $labels = [
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_USER_ERROR => 'E_USER_ERROR',
        ];

        if (defined('E_RECOVERABLE_ERROR')) {
            $labels[E_RECOVERABLE_ERROR] = 'E_RECOVERABLE_ERROR';
        }

        return $labels[$type] ?? 'E_FATAL';
    }

    private static function send_fatal_error(array $payload): void {
        if (!function_exists('wp_remote_post')) {
            return;
        }

        $body = wp_json_encode($payload);
        if (!is_string($body) || $body === '') {
            return;
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $domain = self::key_domain();

        wp_remote_post(self::fatal_error_endpoint(), [
            'blocking' => true,
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'CortexWP-AI-Connector/' . self::plugin_version(),
                'X-CortexWP-Domain' => $domain,
                'X-CortexWP-Nonce' => $nonce,
                'X-CortexWP-Signature' => self::fatal_error_signature($body, $timestamp, $nonce, $domain),
                'X-CortexWP-Timestamp' => $timestamp,
            ],
            'redirection' => 0,
            'timeout' => 3,
        ]);
    }

    private static function fatal_error_signature(string $body, string $timestamp, string $nonce, string $domain): string {
        $payload = implode("\n", [
            'POST',
            self::FATAL_ERROR_ENDPOINT_PATH,
            hash('sha256', $body),
            $timestamp,
            $nonce,
            $domain,
        ]);

        return hash_hmac('sha256', $payload, self::connector_key());
    }

    private static function fatal_error_payload(array $error): array {
        $type = (int) ($error['type'] ?? 0);
        $message = self::truncate_text((string) ($error['message'] ?? ''), 10000);
        $file = isset($error['file']) ? self::public_path((string) $error['file']) : '';
        $line = isset($error['line']) ? (int) $error['line'] : 0;
        $request_url = self::request_url();
        $component = self::component_from_file((string) ($error['file'] ?? ''));

        return [
            'componentName' => $component['name'],
            'componentSlug' => $component['slug'],
            'componentType' => $component['type'],
            'errorType' => $type,
            'errorTypeLabel' => self::error_type_label($type),
            'file' => $file ?: null,
            'fingerprint' => hash('sha256', implode('|', [$type, $message, $file, $line, $request_url])),
            'ipHash' => self::client_ip_hash(),
            'line' => $line ?: null,
            'message' => $message ?: 'Fatal error',
            'occurredAt' => gmdate('c'),
            'referer' => self::safe_server_value('HTTP_REFERER', 2000),
            'requestContext' => self::request_context(),
            'requestMethod' => self::safe_server_value('REQUEST_METHOD', 20),
            'requestUrl' => $request_url,
            'siteDomain' => self::key_domain(),
            'siteUrl' => home_url(),
            'stackTrace' => self::stack_trace_from_message($message),
            'userAgent' => self::safe_server_value('HTTP_USER_AGENT', 1000),
            'wpUser' => self::current_wp_user_context(),
        ];
    }

    private static function request_url(): string {
        $host = self::safe_server_value('HTTP_HOST', 255);
        $uri = self::safe_server_value('REQUEST_URI', 2500);

        if (!$host) {
            return home_url($uri ?: '/');
        }

        $scheme = is_ssl() ? 'https' : 'http';
        return self::truncate_text($scheme . '://' . $host . ($uri ?: '/'), 3000);
    }

    private static function request_context(): array {
        $context = [
            'adminPage' => isset($GLOBALS['pagenow']) ? sanitize_key((string) $GLOBALS['pagenow']) : null,
            'adminScreen' => null,
            'ajaxAction' => isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : null,
            'doingAjax' => function_exists('wp_doing_ajax') ? wp_doing_ajax() : (defined('DOING_AJAX') && DOING_AJAX),
            'doingCron' => function_exists('wp_doing_cron') ? wp_doing_cron() : (defined('DOING_CRON') && DOING_CRON),
            'isAdmin' => is_admin(),
            'queryKeys' => self::request_keys($_GET),
            'postKeys' => self::request_keys($_POST),
            'restRoute' => defined('REST_REQUEST') && REST_REQUEST ? self::safe_server_value('REQUEST_URI', 2500) : null,
            'wpCli' => defined('WP_CLI') && WP_CLI,
        ];

        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && isset($screen->id)) {
                $context['adminScreen'] = sanitize_key((string) $screen->id);
            }
        }

        return array_filter($context, static fn($value) => $value !== null && $value !== '');
    }

    private static function request_keys(array $values): array {
        $keys = array_map('sanitize_key', array_keys($values));
        return array_slice(array_values(array_filter($keys)), 0, 40);
    }

    private static function current_wp_user_context(): ?array {
        if (!function_exists('wp_get_current_user')) {
            return null;
        }

        $user = wp_get_current_user();
        if (!$user || empty($user->ID)) {
            return null;
        }

        return [
            'email' => self::truncate_text((string) $user->user_email, 320),
            'id' => (int) $user->ID,
            'login' => self::truncate_text((string) $user->user_login, 120),
            'roles' => array_values(array_map('sanitize_key', (array) $user->roles)),
        ];
    }

    private static function client_ip_hash(): ?string {
        $ip = self::safe_server_value('REMOTE_ADDR', 128);
        if (!$ip) {
            return null;
        }

        return hash_hmac('sha256', $ip, self::connector_key());
    }

    private static function component_from_file(string $file): array {
        $normalized = wp_normalize_path($file);
        $plugin_dir = defined('WP_PLUGIN_DIR') ? trailingslashit(wp_normalize_path(WP_PLUGIN_DIR)) : '';
        $mu_plugin_dir = defined('WPMU_PLUGIN_DIR') ? trailingslashit(wp_normalize_path(WPMU_PLUGIN_DIR)) : '';
        $theme_dir = trailingslashit(wp_normalize_path(get_theme_root()));
        $content_dir = trailingslashit(wp_normalize_path(WP_CONTENT_DIR));
        $abspath = trailingslashit(wp_normalize_path(ABSPATH));

        if ($plugin_dir && strpos($normalized, $plugin_dir) === 0) {
            $slug = strtok(substr($normalized, strlen($plugin_dir)), '/\\') ?: '';
            return [
                'name' => self::plugin_name_from_slug($slug),
                'slug' => $slug,
                'type' => 'plugin',
            ];
        }

        if ($mu_plugin_dir && strpos($normalized, $mu_plugin_dir) === 0) {
            $relative = substr($normalized, strlen($mu_plugin_dir));
            $slug = strtok($relative, '/\\') ?: basename($relative);
            return [
                'name' => $slug ?: 'MU plugin',
                'slug' => $slug,
                'type' => 'mu-plugin',
            ];
        }

        if ($theme_dir && strpos($normalized, $theme_dir) === 0) {
            $slug = strtok(substr($normalized, strlen($theme_dir)), '/\\') ?: '';
            $theme = $slug ? wp_get_theme($slug) : null;
            return [
                'name' => $theme && $theme->exists() ? $theme->get('Name') : ($slug ?: 'Theme'),
                'slug' => $slug,
                'type' => 'theme',
            ];
        }

        if ($content_dir && strpos($normalized, $content_dir) === 0) {
            return [
                'name' => 'wp-content',
                'slug' => 'wp-content',
                'type' => 'unknown',
            ];
        }

        if ($abspath && strpos($normalized, $abspath) === 0) {
            return [
                'name' => 'WordPress core',
                'slug' => 'wordpress-core',
                'type' => 'core',
            ];
        }

        return [
            'name' => 'Unknown source',
            'slug' => '',
            'type' => 'unknown',
        ];
    }

    private static function plugin_name_from_slug(string $slug): string {
        if (!$slug) {
            return 'Plugin';
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach (get_plugins() as $plugin_file => $plugin_data) {
            if (strtok($plugin_file, '/\\') === $slug) {
                return (string) ($plugin_data['Name'] ?? $slug);
            }
        }

        return $slug;
    }

    private static function public_path(string $path): string {
        $normalized = wp_normalize_path($path);
        $abspath = trailingslashit(wp_normalize_path(ABSPATH));

        if ($abspath && strpos($normalized, $abspath) === 0) {
            return '/' . ltrim(substr($normalized, strlen($abspath)), '/');
        }

        return $normalized;
    }

    private static function stack_trace_from_message(string $message): ?string {
        if (preg_match('/Stack trace:\s*(.+)$/s', $message, $matches)) {
            return self::truncate_text(trim($matches[1]), 50000);
        }

        return null;
    }

    private static function safe_server_value(string $key, int $max_length): string {
        if (!isset($_SERVER[$key])) {
            return '';
        }

        return self::truncate_text(sanitize_text_field(wp_unslash((string) $_SERVER[$key])), $max_length);
    }

    private static function truncate_text(string $value, int $max_length): string {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max_length);
        }

        return substr($value, 0, $max_length);
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

        update_option(self::OPTION_SETTINGS, array_merge(self::connector_settings(), [
            'connection_method' => 'plugin',
        ]), false);

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
        update_option(self::OPTION_SETTINGS, array_merge(self::connector_settings(), [
            'connection_method' => 'plugin',
        ]), false);

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
        $params = $request->get_json_params();
        if (is_array($params) && !empty($params['app_url'])) {
            self::save_app_url((string) $params['app_url']);
        }

        return rest_ensure_response([
            'site_url' => site_url(),
            'home_url' => home_url(),
            'key_domain' => self::key_domain(),
            'wordpress_path' => ABSPATH,
            'connection_method' => 'plugin',
            'fatal_error_endpoint' => self::fatal_error_endpoint(),
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
            'native_management_available' => true,
            'fatal_error_monitor' => true,
            'fatal_error_endpoint' => self::fatal_error_endpoint(),
            'wp_cli_available' => self::wp_cli_available(),
        ];
    }

    private static function save_app_url(string $value): void {
        $url = esc_url_raw(trim($value));
        if (!$url || !preg_match('/^https?:\/\//i', $url)) {
            return;
        }

        update_option(self::OPTION_SETTINGS, array_merge(self::connector_settings(), [
            'app_url' => rtrim($url, '/'),
            'connection_method' => 'plugin',
        ]), false);
    }

    public static function load_snippets(): void {
        foreach (self::snippet_files() as $file) {
            $snippet = self::read_snippet_file($file);
            if (!empty($snippet['enabled'])) {
                require_once $file;
            }
        }
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
            'index.php' => "<?php\n//Silence is good\n",
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
        return array_values(array_filter($files, static function (string $file): bool {
            return is_readable($file) && !in_array(strtolower(basename($file)), ['index.php'], true);
        }));
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
            'code' => $contents,
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
        $php = self::normalize_snippet_php($code);
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

    private static function normalize_snippet_php(string $code): string {
        $php = preg_replace('/^\xEF\xBB\xBF/', '', $code) ?? $code;

        if (!preg_match('/^\s*<\?php\b/i', $php)) {
            $php = "<?php\n" . $php;
        }

        return substr($php, -1) === "\n" ? $php : $php . "\n";
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

    private static function wp_cli_available(): bool {
        if (!self::exec_available()) {
            return false;
        }

        return trim((string) shell_exec('command -v wp 2>/dev/null')) !== '';
    }

    private static function native_command(array $args): string {
        return 'wp ' . implode(' ', array_map(static fn($arg) => (string) $arg, $args)) . ' --path=' . ABSPATH;
    }

    private static function native_result(array $args, string $stdout = '', string $stderr = '', int $exit_code = 0, int $max_output_bytes = 500000): array {
        return [
            'command' => self::native_command($args),
            'cwd' => ABSPATH,
            'exit_code' => $exit_code,
            'stdout' => self::truncate($stdout, $max_output_bytes),
            'stderr' => self::truncate($stderr, $max_output_bytes),
            'timed_out' => false,
            'runner' => 'wordpress-native',
        ];
    }

    private static function native_json_result(array $args, $value, int $max_output_bytes = 500000): array {
        return self::native_result($args, (string) wp_json_encode($value), '', 0, $max_output_bytes);
    }

    private static function native_error_result(array $args, string $message, int $max_output_bytes = 500000): array {
        return self::native_result($args, '', $message, 1, $max_output_bytes);
    }

    private static function load_update_functions(): void {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
    }

    private static function load_upgrader_functions(): void {
        self::load_update_functions();

        if (!class_exists('WP_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        if (!function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        if (!function_exists('themes_api')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }
    }

    private static function plugin_slug_from_file(string $file): string {
        return strpos($file, '/') !== false ? dirname($file) : preg_replace('/\.php$/i', '', basename($file));
    }

    private static function plugin_candidates(bool $include_special = true): array {
        self::load_update_functions();

        $items = [];

        foreach (get_plugins() as $file => $data) {
            $items[] = [
                'name' => self::plugin_slug_from_file((string) $file),
                'title' => (string) ($data['Name'] ?? $file),
                'plugin_file' => (string) $file,
                'version' => (string) ($data['Version'] ?? ''),
                'kind' => 'plugin',
                'path' => trailingslashit(WP_PLUGIN_DIR) . $file,
            ];
        }

        if ($include_special) {
            foreach (get_mu_plugins() as $file => $data) {
                $items[] = [
                    'name' => self::plugin_slug_from_file((string) $file),
                    'title' => (string) ($data['Name'] ?? $file),
                    'plugin_file' => (string) $file,
                    'version' => (string) ($data['Version'] ?? ''),
                    'kind' => 'mu-plugin',
                    'path' => trailingslashit(WPMU_PLUGIN_DIR) . $file,
                ];
            }

            foreach (get_dropins() as $file => $data) {
                $title = is_array($data) ? (string) ($data['Name'] ?? $file) : (string) ($data ?: $file);
                $items[] = [
                    'name' => self::plugin_slug_from_file((string) $file),
                    'title' => $title,
                    'plugin_file' => (string) $file,
                    'version' => is_array($data) ? (string) ($data['Version'] ?? '') : '',
                    'kind' => 'dropin',
                    'path' => trailingslashit(WP_CONTENT_DIR) . $file,
                ];
            }
        }

        return $items;
    }

    private static function resolve_plugin_target(string $target, bool $include_special = false): ?array {
        $needle = strtolower(trim($target));
        if ($needle === '') {
            return null;
        }

        foreach (self::plugin_candidates($include_special) as $plugin) {
            $file = (string) $plugin['plugin_file'];
            $path = (string) $plugin['path'];
            $aliases = array_filter([
                $plugin['name'],
                $plugin['title'],
                $file,
                preg_replace('/\.php$/i', '', $file),
                strtok($file, '/'),
                basename($file),
                preg_replace('/\.php$/i', '', basename($file)),
                $path,
            ]);

            foreach ($aliases as $alias) {
                if (strtolower((string) $alias) === $needle) {
                    return $plugin;
                }
            }
        }

        return null;
    }

    private static function plugin_updates(): array {
        self::load_update_functions();
        wp_update_plugins();
        $transient = get_site_transient('update_plugins');

        return is_object($transient) && isset($transient->response) && is_array($transient->response)
            ? $transient->response
            : [];
    }

    private static function native_plugin_metadata(array $args, int $max_output_bytes): array {
        $items = array_map(static function (array $plugin): array {
            return [
                'name' => $plugin['name'],
                'title' => $plugin['title'],
                'plugin_file' => $plugin['plugin_file'],
                'kind' => $plugin['kind'],
            ];
        }, self::plugin_candidates(true));

        return self::native_json_result($args, $items, $max_output_bytes);
    }

    private static function native_plugin_list(array $args, int $max_output_bytes): array {
        $updates = self::plugin_updates();
        $auto_updates = get_site_option('auto_update_plugins', []);
        $auto_updates = is_array($auto_updates) ? $auto_updates : [];

        $items = [];
        foreach (self::plugin_candidates(true) as $plugin) {
            $file = (string) $plugin['plugin_file'];
            $kind = (string) $plugin['kind'];
            $update = $kind === 'plugin' && isset($updates[$file]) ? $updates[$file] : null;

            if ($kind === 'mu-plugin') {
                $status = 'must-use';
            } elseif ($kind === 'dropin') {
                $status = 'dropin';
            } elseif (is_plugin_active_for_network($file)) {
                $status = 'active-network';
            } elseif (is_plugin_active($file)) {
                $status = 'active';
            } else {
                $status = 'inactive';
            }

            $items[] = [
                'name' => $plugin['name'],
                'status' => $status,
                'version' => $plugin['version'] ?: 'unknown',
                'update' => $update ? 'available' : 'none',
                'update_version' => $update && isset($update->new_version) ? (string) $update->new_version : '',
                'auto_update' => in_array($file, $auto_updates, true) ? 'on' : 'off',
            ];
        }

        return self::native_json_result($args, $items, $max_output_bytes);
    }

    private static function theme_updates(): array {
        self::load_update_functions();
        wp_update_themes();
        $transient = get_site_transient('update_themes');

        return is_object($transient) && isset($transient->response) && is_array($transient->response)
            ? $transient->response
            : [];
    }

    private static function native_theme_metadata(array $args, int $max_output_bytes): array {
        $items = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $items[] = [
                'name' => (string) $slug,
                'title' => (string) $theme->get('Name'),
            ];
        }

        return self::native_json_result($args, $items, $max_output_bytes);
    }

    private static function native_theme_list(array $args, int $max_output_bytes): array {
        $updates = self::theme_updates();
        $auto_updates = get_site_option('auto_update_themes', []);
        $auto_updates = is_array($auto_updates) ? $auto_updates : [];
        $active = get_stylesheet();
        $items = [];

        foreach (wp_get_themes() as $slug => $theme) {
            $update = isset($updates[$slug]) ? $updates[$slug] : null;
            $items[] = [
                'name' => (string) $slug,
                'status' => (string) $slug === $active ? 'active' : 'inactive',
                'version' => (string) $theme->get('Version') ?: 'unknown',
                'update' => $update ? 'available' : 'none',
                'update_version' => is_array($update) && isset($update['new_version']) ? (string) $update['new_version'] : '',
                'auto_update' => in_array((string) $slug, $auto_updates, true) ? 'on' : 'off',
            ];
        }

        return self::native_json_result($args, $items, $max_output_bytes);
    }

    private static function native_core_updates(): array {
        self::load_update_functions();
        wp_version_check([], true);
        $updates = get_core_updates();
        $items = [];

        foreach (is_array($updates) ? $updates : [] as $update) {
            if (!is_object($update)) {
                continue;
            }

            $response = (string) ($update->response ?? '');
            if ($response === '' || $response === 'latest') {
                continue;
            }

            $items[] = [
                'version' => (string) ($update->current ?? $update->version ?? ''),
                'update_type' => (string) ($update->update_type ?? ''),
                'package_url' => (string) ($update->download ?? ''),
                'locale' => (string) ($update->locale ?? get_locale()),
                'response' => $response,
            ];
        }

        return $items;
    }

    private static function upgrader_error_message($result, string $fallback): string {
        if (is_wp_error($result)) {
            return $result->get_error_message();
        }

        if ($result === false || $result === null) {
            return $fallback;
        }

        return '';
    }

    private static function native_core_update(array $args, int $max_output_bytes): array {
        self::load_upgrader_functions();
        $updates = self::native_core_updates();

        if (!$updates) {
            return self::native_result($args, 'WordPress is already up to date.', '', 0, $max_output_bytes);
        }

        $core_updates = get_core_updates();
        $selected = null;
        foreach (is_array($core_updates) ? $core_updates : [] as $update) {
            if (is_object($update) && (string) ($update->response ?? '') !== 'latest') {
                $selected = $update;
                break;
            }
        }

        if (!$selected) {
            return self::native_result($args, 'WordPress is already up to date.', '', 0, $max_output_bytes);
        }

        $upgrader = new Core_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->upgrade($selected);
        $error = self::upgrader_error_message($result, 'Unable to update WordPress core.');

        if ($error) {
            return self::native_error_result($args, $error, $max_output_bytes);
        }

        return self::native_result($args, 'WordPress core updated successfully.', '', 0, $max_output_bytes);
    }

    private static function native_core_update_db(array $args, int $max_output_bytes): array {
        if (!function_exists('wp_upgrade')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        wp_upgrade();
        return self::native_result($args, 'WordPress database checked successfully.', '', 0, $max_output_bytes);
    }

    private static function native_plugin_update(array $args, int $max_output_bytes): array {
        self::load_upgrader_functions();
        $targets = array_values(array_filter(array_slice($args, 2), static fn($arg) => strpos((string) $arg, '--') !== 0));
        $all = in_array('--all', $args, true);
        $updates = self::plugin_updates();
        $files = [];

        if ($all) {
            $files = array_keys($updates);
        } else {
            foreach ($targets as $target) {
                $plugin = self::resolve_plugin_target((string) $target, false);
                if (!$plugin) {
                    return self::native_error_result($args, 'Plugin not found: ' . (string) $target, $max_output_bytes);
                }

                $file = (string) $plugin['plugin_file'];
                if (isset($updates[$file])) {
                    $files[] = $file;
                }
            }
        }

        $files = array_values(array_unique($files));
        if (!$files) {
            return self::native_json_result($args, [], $max_output_bytes);
        }

        $before = [];
        $active_before = [];
        $network_active_before = [];
        foreach (self::plugin_candidates(false) as $plugin) {
            $file = (string) $plugin['plugin_file'];
            $before[$file] = $plugin;
            $active_before[$file] = is_plugin_active($file);
            $network_active_before[$file] = is_plugin_active_for_network($file);
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = count($files) === 1 ? [$files[0] => $upgrader->upgrade($files[0])] : $upgrader->bulk_upgrade($files);
        $error = self::upgrader_error_message($result, 'Unable to update plugin.');

        if ($error) {
            return self::native_error_result($args, $error, $max_output_bytes);
        }

        wp_clean_plugins_cache(false);

        foreach ($files as $file) {
            if (empty($active_before[$file]) && empty($network_active_before[$file])) {
                continue;
            }

            if (!file_exists(trailingslashit(WP_PLUGIN_DIR) . $file)) {
                return self::native_error_result($args, 'Plugin updated, but the plugin file is missing after update: ' . $file, $max_output_bytes);
            }

            $reactivated = activate_plugin($file, '', !empty($network_active_before[$file]), true);
            if (is_wp_error($reactivated)) {
                return self::native_error_result($args, 'Plugin updated, but CortexWP could not restore its active state: ' . $reactivated->get_error_message(), $max_output_bytes);
            }
        }

        $after = [];
        foreach (self::plugin_candidates(false) as $plugin) {
            $after[(string) $plugin['plugin_file']] = $plugin;
        }

        $rows = [];
        foreach ($files as $file) {
            $rows[] = [
                'name' => isset($before[$file]) ? $before[$file]['name'] : self::plugin_slug_from_file($file),
                'old_version' => isset($before[$file]) ? $before[$file]['version'] : '',
                'new_version' => isset($after[$file]) ? $after[$file]['version'] : '',
                'status' => 'Updated',
            ];
        }

        return self::native_json_result($args, $rows, $max_output_bytes);
    }

    private static function native_plugin_activate(array $args, int $max_output_bytes): array {
        $target = (string) ($args[2] ?? '');
        $plugin = self::resolve_plugin_target($target, false);

        if (!$plugin) {
            return self::native_error_result($args, 'Plugin not found: ' . $target, $max_output_bytes);
        }

        $result = activate_plugin((string) $plugin['plugin_file']);
        if (is_wp_error($result)) {
            return self::native_error_result($args, $result->get_error_message(), $max_output_bytes);
        }

        return self::native_result($args, 'Plugin activated.', '', 0, $max_output_bytes);
    }

    private static function native_plugin_deactivate(array $args, int $max_output_bytes): array {
        $target = (string) ($args[2] ?? '');
        $plugin = self::resolve_plugin_target($target, false);

        if (!$plugin) {
            return self::native_error_result($args, 'Plugin not found: ' . $target, $max_output_bytes);
        }

        deactivate_plugins((string) $plugin['plugin_file'], false, false);
        return self::native_result($args, 'Plugin deactivated.', '', 0, $max_output_bytes);
    }

    private static function native_plugin_delete(array $args, int $max_output_bytes): array {
        $target = (string) ($args[2] ?? '');
        $plugin = self::resolve_plugin_target($target, true);

        if (!$plugin) {
            return self::native_error_result($args, 'Plugin not found: ' . $target, $max_output_bytes);
        }

        if ($plugin['kind'] === 'plugin') {
            deactivate_plugins((string) $plugin['plugin_file'], false, false);
            $result = delete_plugins([(string) $plugin['plugin_file']]);
            if (is_wp_error($result)) {
                return self::native_error_result($args, $result->get_error_message(), $max_output_bytes);
            }

            return self::native_result($args, 'Plugin deleted.', '', 0, $max_output_bytes);
        }

        $path = (string) $plugin['path'];
        if (!is_file($path) || !is_writable($path)) {
            return self::native_error_result($args, 'The selected file is not writable.', $max_output_bytes);
        }

        if (!unlink($path)) {
            return self::native_error_result($args, 'Unable to delete the selected file.', $max_output_bytes);
        }

        return self::native_json_result($args, [
            'deleted' => true,
            'kind' => $plugin['kind'],
            'file' => $plugin['plugin_file'],
        ], $max_output_bytes);
    }

    private static function native_plugin_install(array $args, int $max_output_bytes): array {
        self::load_upgrader_functions();
        $slug = sanitize_key((string) ($args[2] ?? ''));

        if (!$slug) {
            return self::native_error_result($args, 'Plugin slug is required.', $max_output_bytes);
        }

        $api = plugins_api('plugin_information', [
            'slug' => $slug,
            'fields' => ['sections' => false],
        ]);

        if (is_wp_error($api)) {
            return self::native_error_result($args, $api->get_error_message(), $max_output_bytes);
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);
        $error = self::upgrader_error_message($result, 'Unable to install plugin.');

        if ($error) {
            return self::native_error_result($args, $error, $max_output_bytes);
        }

        wp_clean_plugins_cache(false);
        return self::native_result($args, 'Plugin installed.', '', 0, $max_output_bytes);
    }

    private static function native_plugin_auto_update(array $args, int $max_output_bytes): array {
        $mode = (string) ($args[2] ?? '');
        $target = (string) ($args[3] ?? '');
        $plugin = self::resolve_plugin_target($target, false);

        if (!$plugin) {
            return self::native_error_result($args, 'Plugin not found: ' . $target, $max_output_bytes);
        }

        $file = (string) $plugin['plugin_file'];
        $auto_updates = get_site_option('auto_update_plugins', []);
        $auto_updates = is_array($auto_updates) ? $auto_updates : [];

        if ($mode === 'enable') {
            $auto_updates[] = $file;
        } elseif ($mode === 'disable') {
            $auto_updates = array_diff($auto_updates, [$file]);
        } else {
            return self::native_error_result($args, 'Auto-update mode must be enable or disable.', $max_output_bytes);
        }

        update_site_option('auto_update_plugins', array_values(array_unique($auto_updates)));
        return self::native_result($args, 'Plugin auto-updates ' . ($mode === 'enable' ? 'enabled.' : 'disabled.'), '', 0, $max_output_bytes);
    }

    private static function native_theme_update(array $args, int $max_output_bytes): array {
        self::load_upgrader_functions();
        $targets = array_values(array_filter(array_slice($args, 2), static fn($arg) => strpos((string) $arg, '--') !== 0));
        $all = in_array('--all', $args, true);
        $updates = self::theme_updates();
        $themes = $all ? array_keys($updates) : array_values(array_filter($targets, static fn($slug) => isset($updates[(string) $slug])));

        if (!$themes) {
            return self::native_json_result($args, [], $max_output_bytes);
        }

        $active_stylesheet = get_stylesheet();
        $before = wp_get_themes();
        $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
        $result = count($themes) === 1 ? [$themes[0] => $upgrader->upgrade($themes[0])] : $upgrader->bulk_upgrade($themes);
        $error = self::upgrader_error_message($result, 'Unable to update theme.');

        if ($error) {
            return self::native_error_result($args, $error, $max_output_bytes);
        }

        wp_clean_themes_cache(false);

        if (get_stylesheet() !== $active_stylesheet && wp_get_theme($active_stylesheet)->exists()) {
            switch_theme($active_stylesheet);
        }

        $after = wp_get_themes();
        $rows = [];

        foreach ($themes as $slug) {
            $rows[] = [
                'name' => (string) $slug,
                'old_version' => isset($before[$slug]) ? (string) $before[$slug]->get('Version') : '',
                'new_version' => isset($after[$slug]) ? (string) $after[$slug]->get('Version') : '',
                'status' => 'Updated',
            ];
        }

        return self::native_json_result($args, $rows, $max_output_bytes);
    }

    private static function native_theme_activate(array $args, int $max_output_bytes): array {
        $slug = sanitize_key((string) ($args[2] ?? ''));
        if (!$slug || !wp_get_theme($slug)->exists()) {
            return self::native_error_result($args, 'Theme not found: ' . $slug, $max_output_bytes);
        }

        switch_theme($slug);
        return self::native_result($args, 'Theme activated.', '', 0, $max_output_bytes);
    }

    private static function native_theme_delete(array $args, int $max_output_bytes): array {
        $slug = sanitize_key((string) ($args[2] ?? ''));
        if (!$slug || !wp_get_theme($slug)->exists()) {
            return self::native_error_result($args, 'Theme not found: ' . $slug, $max_output_bytes);
        }

        if ($slug === get_stylesheet() || $slug === get_template()) {
            return self::native_error_result($args, 'The active theme cannot be deleted.', $max_output_bytes);
        }

        self::load_update_functions();
        $result = delete_theme($slug);
        if (is_wp_error($result)) {
            return self::native_error_result($args, $result->get_error_message(), $max_output_bytes);
        }

        return self::native_result($args, 'Theme deleted.', '', 0, $max_output_bytes);
    }

    private static function native_theme_install(array $args, int $max_output_bytes): array {
        self::load_upgrader_functions();
        $slug = sanitize_key((string) ($args[2] ?? ''));

        if (!$slug) {
            return self::native_error_result($args, 'Theme slug is required.', $max_output_bytes);
        }

        $api = themes_api('theme_information', [
            'slug' => $slug,
            'fields' => ['sections' => false],
        ]);

        if (is_wp_error($api)) {
            return self::native_error_result($args, $api->get_error_message(), $max_output_bytes);
        }

        $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->install($api->download_link);
        $error = self::upgrader_error_message($result, 'Unable to install theme.');

        if ($error) {
            return self::native_error_result($args, $error, $max_output_bytes);
        }

        wp_clean_themes_cache(false);
        return self::native_result($args, 'Theme installed.', '', 0, $max_output_bytes);
    }

    private static function native_theme_auto_update(array $args, int $max_output_bytes): array {
        $mode = (string) ($args[2] ?? '');
        $slug = sanitize_key((string) ($args[3] ?? ''));

        if (!$slug || !wp_get_theme($slug)->exists()) {
            return self::native_error_result($args, 'Theme not found: ' . $slug, $max_output_bytes);
        }

        $auto_updates = get_site_option('auto_update_themes', []);
        $auto_updates = is_array($auto_updates) ? $auto_updates : [];

        if ($mode === 'enable') {
            $auto_updates[] = $slug;
        } elseif ($mode === 'disable') {
            $auto_updates = array_diff($auto_updates, [$slug]);
        } else {
            return self::native_error_result($args, 'Auto-update mode must be enable or disable.', $max_output_bytes);
        }

        update_site_option('auto_update_themes', array_values(array_unique($auto_updates)));
        return self::native_result($args, 'Theme auto-updates ' . ($mode === 'enable' ? 'enabled.' : 'disabled.'), '', 0, $max_output_bytes);
    }

    private static function native_option_get(array $args, int $max_output_bytes): array {
        $name = (string) ($args[2] ?? '');
        if ($name === '') {
            return self::native_error_result($args, 'Option name is required.', $max_output_bytes);
        }

        $value = get_option($name);
        return self::native_result($args, is_scalar($value) ? (string) $value : (string) wp_json_encode($value), '', 0, $max_output_bytes);
    }

    private static function native_config_list(array $args, int $max_output_bytes): array {
        $rows = [
            ['name' => 'ABSPATH', 'value' => ABSPATH, 'type' => 'constant'],
            ['name' => 'WP_DEBUG', 'value' => defined('WP_DEBUG') && WP_DEBUG ? '1' : '0', 'type' => 'constant'],
            ['name' => 'WP_MEMORY_LIMIT', 'value' => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : '', 'type' => 'constant'],
            ['name' => 'WP_MAX_MEMORY_LIMIT', 'value' => defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : '', 'type' => 'constant'],
            ['name' => 'DB_CHARSET', 'value' => defined('DB_CHARSET') ? DB_CHARSET : '', 'type' => 'constant'],
        ];

        return in_array('--format=json', $args, true)
            ? self::native_json_result($args, $rows, $max_output_bytes)
            : self::native_result($args, implode("\n", array_map(static fn($row) => $row['name'] . "\t" . $row['value'], $rows)), '', 0, $max_output_bytes);
    }

    private static function native_cron_list(array $args, int $max_output_bytes): array {
        $rows = [];
        $cron = _get_cron_array();
        $now = time();

        foreach (is_array($cron) ? $cron : [] as $timestamp => $hooks) {
            foreach ((array) $hooks as $hook => $events) {
                foreach ((array) $events as $event) {
                    $rows[] = [
                        'hook' => (string) $hook,
                        'next_run_relative' => human_time_diff($now, (int) $timestamp),
                        'recurrence' => isset($event['schedule']) ? (string) $event['schedule'] : '',
                    ];
                }
            }
        }

        usort($rows, static fn($a, $b) => strcmp($a['next_run_relative'], $b['next_run_relative']));
        return self::native_json_result($args, array_slice($rows, 0, 500), $max_output_bytes);
    }

    private static function native_rewrite_list(array $args, int $max_output_bytes): array {
        global $wp_rewrite;
        $rules = $wp_rewrite instanceof WP_Rewrite ? $wp_rewrite->wp_rewrite_rules() : [];
        $rows = [];

        foreach (is_array($rules) ? $rules : [] as $match => $query) {
            $rows[] = ['match' => (string) $match, 'query' => (string) $query];
        }

        return self::native_json_result($args, array_slice($rows, 0, 1000), $max_output_bytes);
    }

    private static function native_wp_cli(array $args, int $max_output_bytes): ?array {
        $group = (string) ($args[0] ?? '');
        $action = (string) ($args[1] ?? '');

        try {
            if ($group === 'core' && $action === 'version') {
                return self::native_result($args, get_bloginfo('version'), '', 0, $max_output_bytes);
            }

            if ($group === 'core' && $action === 'check-update') {
                return self::native_json_result($args, self::native_core_updates(), $max_output_bytes);
            }

            if ($group === 'core' && $action === 'update') {
                return self::native_core_update($args, $max_output_bytes);
            }

            if ($group === 'core' && $action === 'update-db') {
                return self::native_core_update_db($args, $max_output_bytes);
            }

            if ($group === 'plugin' && $action === 'list') {
                return self::native_plugin_list($args, $max_output_bytes);
            }

            if ($group === 'plugin' && $action === 'update') {
                return self::native_plugin_update($args, $max_output_bytes);
            }

            if ($group === 'plugin' && $action === 'activate') {
                return self::native_plugin_activate($args, $max_output_bytes);
            }

            if ($group === 'plugin' && $action === 'deactivate') {
                return self::native_plugin_deactivate($args, $max_output_bytes);
            }

            if ($group === 'plugin' && $action === 'delete') {
                return self::native_plugin_delete($args, $max_output_bytes);
            }

            if ($group === 'plugin' && $action === 'install') {
                return self::native_plugin_install($args, $max_output_bytes);
            }

            if ($group === 'plugin' && $action === 'auto-updates') {
                return self::native_plugin_auto_update($args, $max_output_bytes);
            }

            if ($group === 'theme' && $action === 'list') {
                return self::native_theme_list($args, $max_output_bytes);
            }

            if ($group === 'theme' && $action === 'update') {
                return self::native_theme_update($args, $max_output_bytes);
            }

            if ($group === 'theme' && $action === 'activate') {
                return self::native_theme_activate($args, $max_output_bytes);
            }

            if ($group === 'theme' && $action === 'delete') {
                return self::native_theme_delete($args, $max_output_bytes);
            }

            if ($group === 'theme' && $action === 'install') {
                return self::native_theme_install($args, $max_output_bytes);
            }

            if ($group === 'theme' && $action === 'auto-updates') {
                return self::native_theme_auto_update($args, $max_output_bytes);
            }

            if ($group === 'option' && $action === 'get') {
                return self::native_option_get($args, $max_output_bytes);
            }

            if ($group === 'config' && $action === 'list') {
                return self::native_config_list($args, $max_output_bytes);
            }

            if ($group === 'cron' && $action === 'event' && (string) ($args[2] ?? '') === 'list') {
                return self::native_cron_list($args, $max_output_bytes);
            }

            if ($group === 'rewrite' && $action === 'list') {
                return self::native_rewrite_list($args, $max_output_bytes);
            }

            if ($group === 'eval') {
                $code = implode(' ', array_slice($args, 1));

                if (strpos($code, 'get_mu_plugins()') !== false && strpos($code, 'get_dropins()') !== false) {
                    return self::native_plugin_metadata($args, $max_output_bytes);
                }

                if (strpos($code, 'wp_get_themes()') !== false) {
                    return self::native_theme_metadata($args, $max_output_bytes);
                }
            }
        } catch (Throwable $error) {
            return self::native_error_result($args, $error->getMessage(), $max_output_bytes);
        }

        return null;
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

        $native = self::native_wp_cli($safe_args, $max_output_bytes);
        if ($native !== null) {
            return $native;
        }

        if (!self::exec_available()) {
            return [
                'command' => self::native_command($safe_args),
                'cwd' => ABSPATH,
                'exit_code' => 127,
                'stdout' => '',
                'stderr' => 'This connector action is not available without shell_exec/proc_open. Use a supported WordPress management action or reconnect with SFTP for server-level WP-CLI/shell access.',
                'timed_out' => false,
            ];
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
