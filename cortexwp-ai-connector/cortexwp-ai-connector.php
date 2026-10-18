<?php
/**
 * Plugin Name: CortexWP Connector
 * Description: Securely connects a WordPress site to CortexWP.ai.
 * Version: 1.8
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
    private const OPTION_KEY_VIEWED = 'cortexwp_ai_connector_key_viewed';
    private const OPTION_SETTINGS = 'cortexwp_ai_connector_settings';
    private const OPTION_AUTOPILOT_MANAGED = 'cortexwp_ai_autopilot_managed';
    private const OPTION_SECURITY_HARDENING = 'cortexwp_ai_security_hardening';
    private const OPTION_LOGIN_TABLE_VERSION = 'cortexwp_ai_login_table_version';
    private const OPTION_REDIRECT_RULES = 'cortexwp_ai_redirect_rules';
    private const ROUTE_NAMESPACE = 'cortexwp-ai/v1';
    private const SNIPPET_DIR = 'cortexwp-snippets';
    private const RECOVERY_BRIDGE_PREFIX = 'cortexwp-recovery-';
    private const RECOVERY_BACKUP_DIR = 'cortexwp-recovery-backups';
    private const RECOVERY_AUDIT_DIR = 'cortexwp-recovery-audit';
    private const SITE_HEALTH_CACHE_KEY = 'cortexwp_ai_site_health_cache_v2';
    private const LOGIN_TABLE_VERSION = '2';
    private const RELEASE_ASSET = 'cortexwp-ai-connector.zip';
    private const UPDATE_CACHE_KEY = 'cortexwp_ai_connector_release';
    private const DEFAULT_APP_URL = 'http://127.0.0.1:3000';
    private const CORTEXWP_SERVICE_IP = '40.233.109.6';
    private const FATAL_ERROR_ENDPOINT_PATH = '/api/wordpress/fatal-error';
    private static $reserved_memory = '';
    private static $login_rate_limited = false;
    private static $rate_limit_logged = false;

    public static function boot(): void {
        self::$reserved_memory = str_repeat('x', 262144);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('load-toplevel_page_cortexwp-ai', [__CLASS__, 'suppress_admin_notices_on_cortexwp_pages'], 999);
        add_action('load-cortexwp_page_cortexwp-ai-snippets', [__CLASS__, 'suppress_admin_notices_on_cortexwp_pages'], 999);
        add_filter('admin_body_class', [__CLASS__, 'admin_body_class']);
        add_action('admin_post_cortexwp_ai_save', [__CLASS__, 'save_settings']);
        add_action('admin_post_cortexwp_ai_save_snippet', [__CLASS__, 'save_snippet_from_admin']);
        add_action('admin_post_cortexwp_ai_delete_snippet', [__CLASS__, 'delete_snippet_from_admin']);
        add_action('admin_post_cortexwp_ai_toggle_snippet', [__CLASS__, 'toggle_snippet_from_admin']);
        add_action('wp_ajax_cortexwp_ai_regenerate_key', [__CLASS__, 'ajax_regenerate_key']);
        add_action('wp_ajax_cortexwp_ai_reveal_key', [__CLASS__, 'ajax_reveal_key']);
        add_action('plugins_loaded', [__CLASS__, 'load_snippets'], 20);
        add_action('init', [__CLASS__, 'maybe_upgrade_security_storage'], 1);
        add_action('init', [__CLASS__, 'maybe_upgrade_key_visibility'], 1);
        add_action('init', [__CLASS__, 'maybe_whitelist_service_ip'], 20);
        add_action('admin_notices', [__CLASS__, 'admin_update_policy_notice']);
        add_action('send_headers', [__CLASS__, 'send_security_headers'], 1);
        add_action('template_redirect', [__CLASS__, 'maybe_redirect_request'], 0);
        add_action('template_redirect', [__CLASS__, 'maybe_block_author_enumeration'], 1);
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('wp_login', [__CLASS__, 'log_successful_login'], 10, 2);
        add_action('wp_login_failed', [__CLASS__, 'log_failed_login'], 10, 1);
        add_filter('authenticate', [__CLASS__, 'maybe_rate_limit_login'], 20, 3);
        add_filter('login_errors', [__CLASS__, 'mask_login_errors']);
        add_filter('rest_authentication_errors', [__CLASS__, 'block_rest_user_listing']);
        add_filter('the_generator', [__CLASS__, 'hide_wordpress_generator'], 99);
        add_filter('script_loader_src', [__CLASS__, 'strip_asset_version'], 9999);
        add_filter('style_loader_src', [__CLASS__, 'strip_asset_version'], 9999);
        add_filter('xmlrpc_enabled', [__CLASS__, 'xmlrpc_enabled']);
        add_filter('wp_is_application_passwords_available', [__CLASS__, 'application_passwords_available']);
        add_filter('map_meta_cap', [__CLASS__, 'block_file_editor_caps'], 10, 4);
        add_filter('auto_update_plugin', [__CLASS__, 'disable_managed_plugin_auto_update'], 999, 2);
        add_filter('auto_update_theme', [__CLASS__, 'disable_managed_theme_auto_update'], 999, 2);
        add_filter('allow_major_auto_core_updates', [__CLASS__, 'disable_managed_major_core_updates'], 999);
        add_filter('allow_minor_auto_core_updates', [__CLASS__, 'allow_managed_minor_core_updates'], 999);
        add_filter('plugin_auto_update_setting_html', [__CLASS__, 'managed_plugin_auto_update_setting_html'], 999, 3);
        add_filter('theme_auto_update_setting_html', [__CLASS__, 'managed_theme_auto_update_setting_html'], 999, 3);
        add_filter('theme_auto_update_setting_template', [__CLASS__, 'managed_theme_auto_update_setting_template'], 999);
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_plugin_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_update_details'], 10, 3);
        register_shutdown_function([__CLASS__, 'capture_fatal_error']);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        self::maybe_ensure_recovery_bridge();
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
        self::ensure_login_events_table();
        self::ensure_recovery_bridge(true);
        add_option(self::OPTION_AUTOPILOT_MANAGED, false, '', false);
        if (get_option(self::OPTION_KEY_VIEWED, null) === null) {
            update_option(self::OPTION_KEY_VIEWED, false, false);
        }
    }

    private static function maybe_ensure_recovery_bridge(): void {
        if (self::should_check_recovery_bridge_on_boot()) {
            self::ensure_recovery_bridge(false);
        }
    }

    public static function maybe_whitelist_service_ip(): void {
        if (!class_exists('wordfence')) {
            return;
        }

        try {
            wordfence::whitelistIP(self::CORTEXWP_SERVICE_IP);
        } catch (Throwable $error) {
            error_log('CortexWP could not whitelist its service IP in Wordfence: ' . $error->getMessage());
        }
    }

    private static function autopilot_managed(): bool {
        return (bool) get_option(self::OPTION_AUTOPILOT_MANAGED, false);
    }

    public static function disable_managed_plugin_auto_update($update, $item) {
        return self::autopilot_managed() ? false : $update;
    }

    public static function disable_managed_theme_auto_update($update, $item) {
        return self::autopilot_managed() ? false : $update;
    }

    public static function disable_managed_major_core_updates($allow) {
        return self::autopilot_managed() ? false : $allow;
    }

    public static function allow_managed_minor_core_updates($allow) {
        // WordPress groups security releases with minor core updates. Keep those available.
        return self::autopilot_managed() ? true : $allow;
    }

    public static function managed_plugin_auto_update_setting_html($html, $plugin_file, $plugin_data) {
        if (!self::autopilot_managed()) {
            return $html;
        }

        return '<span class="cortexwp-managed-auto-update">Auto-updates disabled — managed by CortexWP</span>';
    }

    public static function managed_theme_auto_update_setting_html($html, $stylesheet, $theme) {
        if (!self::autopilot_managed()) {
            return $html;
        }

        return '<span class="cortexwp-managed-auto-update">Auto-updates disabled — managed by CortexWP</span>';
    }

    public static function managed_theme_auto_update_setting_template($template) {
        if (!self::autopilot_managed()) {
            return $template;
        }

        return '<div class="theme-autoupdate"><span class="cortexwp-managed-auto-update">Auto-updates disabled — managed by CortexWP</span></div>';
    }

    public static function admin_update_policy_notice(): void {
        if (!self::autopilot_managed() || !function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || !in_array((string) $screen->id, ['plugins', 'plugins-network', 'themes', 'themes-network', 'update-core'], true)) {
            return;
        }

        echo '<div class="notice notice-info is-dismissible cortexwp-update-policy-notice"><p><strong>CortexWP manages updates for this site.</strong> Automatic plugin and theme updates are disabled here. WordPress security and minor core updates remain eligible, and all other updates should be run from CortexWP for backups, visual checks, rollback, and history.</p></div>';
    }

    public static function admin_body_class(string $classes): string {
        return self::autopilot_managed() ? trim($classes . ' cortexwp-updates-managed') : $classes;
    }

    public static function suppress_admin_notices_on_cortexwp_pages(): void {
        remove_all_actions('admin_notices');
        remove_all_actions('network_admin_notices');
        remove_all_actions('all_admin_notices');
    }

    private static function should_check_recovery_bridge_on_boot(): bool {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (function_exists('is_admin') && is_admin()) {
            return true;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        return $uri !== '' && strpos($uri, '/wp-json/' . self::ROUTE_NAMESPACE . '/') !== false;
    }

    private static function new_key(): string {
        $site_url = site_url();
        $encoded_site_url = rtrim(strtr(base64_encode($site_url), '+/', '-_'), '=');

        return 'cwp_' . $encoded_site_url . '_' . bin2hex(random_bytes(32));
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

    private static function default_hardening_settings(): array {
        return [
            'hide_wp_version' => false,
            'block_user_enumeration' => false,
            'mask_login_errors' => false,
            'disable_file_editor' => false,
            'security_headers' => false,
            'rate_limit_login' => false,
            'disable_xml_rpc' => false,
            'block_rest_user_listing' => false,
            'disable_application_passwords' => false,
        ];
    }

    private static function hardening_settings(): array {
        $settings = get_option(self::OPTION_SECURITY_HARDENING, []);
        return array_merge(self::default_hardening_settings(), is_array($settings) ? $settings : []);
    }

    private static function hardening_enabled(string $key): bool {
        $settings = self::hardening_settings();
        return !empty($settings[$key]);
    }

    private static function hardening_definitions(): array {
        return [
            [
                'key' => 'hide_wp_version',
                'title' => 'Hide WordPress version details',
                'description' => 'Removes version hints from public HTML, feeds, and loaded assets so automated scanners have less information to work with.',
                'category' => 'Public surface',
            ],
            [
                'key' => 'block_user_enumeration',
                'title' => 'Block author discovery',
                'description' => 'Stops common author archive probes that bots use to collect usernames before password attacks.',
                'category' => 'Login protection',
            ],
            [
                'key' => 'block_rest_user_listing',
                'title' => 'Restrict REST user listings',
                'description' => 'Keeps unauthenticated visitors from reading WordPress user lists through the REST API.',
                'category' => 'Login protection',
            ],
            [
                'key' => 'mask_login_errors',
                'title' => 'Use generic login errors',
                'description' => 'Shows one neutral message for failed sign-ins so attackers cannot tell whether a username exists.',
                'category' => 'Login protection',
            ],
            [
                'key' => 'rate_limit_login',
                'title' => 'Slow repeated login failures',
                'description' => 'Temporarily throttles repeated failed sign-ins from the same source to reduce brute-force pressure.',
                'category' => 'Login protection',
            ],
            [
                'key' => 'disable_file_editor',
                'title' => 'Disable dashboard file editing',
                'description' => 'Removes direct plugin and theme code editing from wp-admin to limit damage from a compromised admin account.',
                'category' => 'Admin safety',
            ],
            [
                'key' => 'security_headers',
                'title' => 'Send baseline security headers',
                'description' => 'Adds practical browser headers for clickjacking, content sniffing, referrer handling, and browser permission boundaries.',
                'category' => 'Browser protection',
            ],
            [
                'key' => 'disable_xml_rpc',
                'title' => 'Disable XML-RPC',
                'description' => 'Turns off the legacy XML-RPC interface often abused for brute-force and pingback attacks. Leave it off if you rely on Jetpack or the mobile app.',
                'category' => 'Public surface',
            ],
            [
                'key' => 'disable_application_passwords',
                'title' => 'Disable application passwords',
                'description' => 'Prevents new REST API application-password access when your site does not need external password-based integrations.',
                'category' => 'API access',
            ],
        ];
    }

    private static function public_hardening_state(): array {
        $settings = self::hardening_settings();
        $options = array_map(static function (array $definition) use ($settings): array {
            return $definition + [
                'enabled' => !empty($settings[$definition['key']]),
            ];
        }, self::hardening_definitions());

        return [
            'settings' => $settings,
            'options' => $options,
            'updatedAt' => gmdate('c'),
        ];
    }

    public static function maybe_upgrade_security_storage(): void {
        if (get_option(self::OPTION_LOGIN_TABLE_VERSION) !== self::LOGIN_TABLE_VERSION) {
            self::ensure_login_events_table();
        }
    }

    public static function maybe_upgrade_key_visibility(): void {
        if (get_option(self::OPTION_KEY_VIEWED, null) !== null) {
            return;
        }

        // Before one-time reveal was introduced, the full key was rendered on
        // every connection-page load, so there was no reliable viewed state to
        // migrate. Give the existing key one reveal rather than locking the
        // admin out before they have had a chance to copy it.
        if (get_option(self::OPTION_KEY, '')) {
            update_option(self::OPTION_KEY_VIEWED, false, false);
        } else {
            update_option(self::OPTION_KEY_VIEWED, false, false);
        }
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
            'blocking' => false,
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
            'timeout' => 1,
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
        $ip = self::client_ip();
        if (!$ip) {
            return null;
        }

        return hash_hmac('sha256', $ip, self::connector_key());
    }

    public static function hide_wordpress_generator(string $value): string {
        return self::hardening_enabled('hide_wp_version') ? '' : $value;
    }

    public static function strip_asset_version(string $src): string {
        if (!self::hardening_enabled('hide_wp_version')) {
            return $src;
        }

        return remove_query_arg('ver', $src);
    }

    public static function mask_login_errors(string $error): string {
        if (!self::hardening_enabled('mask_login_errors')) {
            return $error;
        }

        if (self::$login_rate_limited) {
            return __('Too many login attempts. Please wait and try again.', 'cortexwp-ai');
        }

        return __('The login details were not accepted. Please check them and try again.', 'cortexwp-ai');
    }

    public static function xmlrpc_enabled(bool $enabled): bool {
        return self::hardening_enabled('disable_xml_rpc') ? false : $enabled;
    }

    public static function application_passwords_available(bool $available): bool {
        return self::hardening_enabled('disable_application_passwords') ? false : $available;
    }

    public static function block_file_editor_caps(array $caps, string $cap, int $user_id, array $args): array {
        if (!self::hardening_enabled('disable_file_editor')) {
            return $caps;
        }

        if (in_array($cap, ['edit_plugins', 'edit_themes', 'edit_files'], true)) {
            return ['do_not_allow'];
        }

        return $caps;
    }

    public static function send_security_headers(): void {
        if (!self::hardening_enabled('security_headers') || headers_sent()) {
            return;
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    public static function maybe_block_author_enumeration(): void {
        if (!self::hardening_enabled('block_user_enumeration') || is_admin()) {
            return;
        }

        if (isset($_GET['author']) || preg_match('#/author/[^/]+/?#i', self::safe_server_value('REQUEST_URI', 2000))) {
            wp_safe_redirect(home_url('/'), 301);
            exit;
        }
    }

    public static function block_rest_user_listing($result) {
        if (!self::hardening_enabled('block_rest_user_listing') || !defined('REST_REQUEST') || !REST_REQUEST) {
            return $result;
        }

        if (is_wp_error($result) || current_user_can('list_users')) {
            return $result;
        }

        $uri = self::safe_server_value('REQUEST_URI', 2000);
        if (preg_match('#/wp-json/wp/v2/users(?:/|\\?|$)#i', $uri)) {
            return new WP_Error('cortexwp_ai_rest_users_blocked', __('User listings are not public on this site.', 'cortexwp-ai'), ['status' => 403]);
        }

        return $result;
    }

    public static function maybe_rate_limit_login($user, string $username, string $password) {
        if (!self::hardening_enabled('rate_limit_login') || $username === '' || $password === '') {
            return $user;
        }

        $key = self::login_rate_key();
        $state = get_transient($key);
        $state = is_array($state) ? $state : ['count' => 0, 'until' => 0];
        $blocked_until = (int) ($state['until'] ?? 0);

        if ($blocked_until > time()) {
            self::$login_rate_limited = true;

            if (!self::$rate_limit_logged) {
                self::$rate_limit_logged = true;
                self::record_login_event(false, $username, null, 'rate_limited');
            }

            return new WP_Error('cortexwp_ai_login_rate_limited', __('Too many login attempts. Please wait and try again.', 'cortexwp-ai'));
        }

        return $user;
    }

    public static function log_failed_login(string $username): void {
        if (self::$rate_limit_logged) {
            return;
        }

        self::record_login_event(false, $username, null, 'failed');

        if (!self::hardening_enabled('rate_limit_login')) {
            return;
        }

        $key = self::login_rate_key();
        $state = get_transient($key);
        $state = is_array($state) ? $state : ['count' => 0, 'until' => 0];
        $count = (int) ($state['count'] ?? 0) + 1;
        $blocked_until = $count >= 5 ? time() + 15 * MINUTE_IN_SECONDS : 0;

        set_transient($key, ['count' => $count, 'until' => $blocked_until], 15 * MINUTE_IN_SECONDS);
    }

    public static function log_successful_login(string $user_login, WP_User $user): void {
        self::record_login_event(true, $user_login, $user, 'success');
        delete_transient(self::login_rate_key());
    }

    private static function user_from_login_hint(string $username) {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        if (is_email($username)) {
            $user = get_user_by('email', $username);
            if ($user instanceof WP_User) {
                return $user;
            }
        }

        $user = get_user_by('login', $username);
        return $user instanceof WP_User ? $user : null;
    }

    private static function primary_role(array $roles): string {
        $roles = array_values(array_filter(array_map('sanitize_key', $roles)));
        return $roles[0] ?? '';
    }

    private static function login_event_role_label(string $role): string {
        if ($role === '') {
            return '';
        }

        $roles = wp_roles();
        $label = $roles && isset($roles->roles[$role]['name']) ? (string) $roles->roles[$role]['name'] : '';
        return $label ?: ucwords(str_replace('_', ' ', $role));
    }

    private static function record_login_event(bool $success, string $username, ?WP_User $user, string $reason): void {
        global $wpdb;

        self::maybe_upgrade_security_storage();

        if (!$user) {
            $user = self::user_from_login_hint($username);
        }

        $roles = $user instanceof WP_User ? array_values(array_map('sanitize_key', (array) $user->roles)) : [];
        $primary_role = self::primary_role($roles);
        $login = $user instanceof WP_User ? (string) $user->user_login : sanitize_user($username, true);
        $email = $user instanceof WP_User ? (string) $user->user_email : (is_email($username) ? $username : '');
        $ip = self::client_ip();
        $now = gmdate('Y-m-d H:i:s');
        $payload = [
            'id' => 0,
            'attemptedAt' => gmdate('c'),
            'success' => $success,
            'reason' => $reason,
            'username' => self::truncate_text($login ?: $username, 190),
            'email' => self::truncate_text($email, 320),
            'userId' => $user instanceof WP_User ? (int) $user->ID : null,
            'roles' => $roles,
            'roleLabel' => self::login_event_role_label($primary_role),
            'ip' => self::truncate_text($ip, 128),
            'location' => self::client_location(),
            'userAgent' => self::safe_server_value('HTTP_USER_AGENT', 500),
            'requestUri' => self::safe_server_value('REQUEST_URI', 500),
        ];
        $encrypted_payload = self::encrypt_security_payload($payload);

        if (!is_string($encrypted_payload) || $encrypted_payload === '') {
            return;
        }

        $wpdb->insert(
            self::login_events_table(),
            [
                'attempted_at' => $now,
                'success' => $success ? 1 : 0,
                'username_hash' => self::security_lookup_hash($login ?: $username ?: $email),
                'email_hash' => self::security_lookup_hash($email),
                'role_hash' => self::security_lookup_hash($primary_role),
                'user_id' => $user instanceof WP_User ? (int) $user->ID : null,
                'ip_hash' => self::security_lookup_hash($ip),
                'payload' => $encrypted_payload,
                'created_at' => $now,
            ],
            ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    private static function login_event_roles(): array {
        $roles = wp_roles();
        if (!$roles) {
            return [];
        }

        $items = [];
        foreach ($roles->roles as $key => $role) {
            $items[] = [
                'key' => (string) $key,
                'label' => (string) ($role['name'] ?? $key),
            ];
        }

        return $items;
    }

    private static function clean_iso_date(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $time = strtotime($value);
        return $time ? gmdate('Y-m-d H:i:s', $time) : '';
    }

    private static function security_login_events(array $params): array {
        global $wpdb;

        self::maybe_upgrade_security_storage();

        $page = max(1, min(100000, (int) ($params['page'] ?? 1)));
        $per_page = max(10, min(100, (int) ($params['perPage'] ?? $params['per_page'] ?? 25)));
        $status = sanitize_key((string) ($params['status'] ?? 'all'));
        $search = sanitize_text_field((string) ($params['search'] ?? ''));
        $role = sanitize_key((string) ($params['role'] ?? ''));
        $from = self::clean_iso_date((string) ($params['from'] ?? ''));
        $to = self::clean_iso_date((string) ($params['to'] ?? ''));
        $where = ['1=1'];
        $values = [];

        if ($status === 'success') {
            $where[] = 'success = %d';
            $values[] = 1;
        } elseif ($status === 'failed') {
            $where[] = 'success = %d';
            $values[] = 0;
        }

        if ($search !== '') {
            $search_hash = self::security_lookup_hash($search);
            $where[] = '(username_hash = %s OR email_hash = %s OR ip_hash = %s)';
            $values[] = $search_hash;
            $values[] = $search_hash;
            $values[] = $search_hash;
        }

        if ($role !== '') {
            $where[] = 'role_hash = %s';
            $values[] = self::security_lookup_hash($role);
        }

        if ($from !== '') {
            $where[] = 'attempted_at >= %s';
            $values[] = $from;
        }

        if ($to !== '') {
            $where[] = 'attempted_at <= %s';
            $values[] = $to;
        }

        $where_sql = implode(' AND ', $where);
        $table = self::login_events_table();
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) ($values ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql));
        $total_pages = max(1, (int) ceil($total / $per_page));
        $page = min($page, $total_pages);
        $offset = ($page - 1) * $per_page;
        $list_values = array_merge($values, [$per_page, $offset]);
        $list_sql = "SELECT id, attempted_at, success, payload FROM {$table} WHERE {$where_sql} ORDER BY attempted_at DESC, id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_values), ARRAY_A);
        $items = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $payload = self::decrypt_security_payload((string) ($row['payload'] ?? ''));
            $payload['id'] = (int) ($row['id'] ?? 0);
            $payload['attemptedAt'] = gmdate('c', strtotime((string) ($row['attempted_at'] ?? 'now')) ?: time());
            $payload['success'] = !empty($row['success']);
            $items[] = $payload;
        }

        return [
            'items' => $items,
            'page' => $page,
            'perPage' => $per_page,
            'total' => $total,
            'totalPages' => $total_pages,
            'roles' => self::login_event_roles(),
        ];
    }

    private static function clear_security_login_events(): array {
        global $wpdb;

        self::maybe_upgrade_security_storage();
        $deleted = (int) $wpdb->query('TRUNCATE TABLE ' . self::login_events_table());

        return [
            'cleared' => true,
            'deleted' => $deleted,
        ];
    }

    private static function security_check(string $id, string $status, string $title, string $description, array $meta = []): array {
        return [
            'id' => $id,
            'status' => $status,
            'title' => $title,
            'description' => $description,
            'meta' => $meta,
        ];
    }

    private static function inactive_plugin_count(): int {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = array_flip((array) get_option('active_plugins', []));
        $inactive = 0;
        foreach (get_plugins() as $file => $plugin) {
            if (!isset($active[$file])) {
                $inactive += 1;
            }
        }

        return $inactive;
    }

    private static function inactive_theme_count(): int {
        $active = array_filter([get_stylesheet(), get_template()]);
        $inactive = 0;

        foreach (wp_get_themes() as $slug => $theme) {
            if (!in_array((string) $slug, $active, true)) {
                $inactive += 1;
            }
        }

        return $inactive;
    }

    private static function core_update_available(): bool {
        $updates = get_site_transient('update_core');
        if (!is_object($updates) || empty($updates->updates) || !is_array($updates->updates)) {
            return false;
        }

        foreach ($updates->updates as $update) {
            if (is_object($update) && ($update->response ?? '') === 'upgrade') {
                return true;
            }
        }

        return false;
    }

    private static function site_health_security_salts_valid(): bool {
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY'] as $constant) {
            if (!defined($constant)) {
                return false;
            }

            $value = trim((string) constant($constant));
            if ($value === '' || stripos($value, 'put your unique phrase here') !== false) {
                return false;
            }
        }

        return true;
    }

    private static function site_health_scan(bool $force = false): array {
        if (!$force) {
            $cached = get_transient(self::SITE_HEALTH_CACHE_KEY);
            if (is_array($cached)) {
                $cached['cacheHit'] = true;
                return $cached;
            }
        }

        $checks = [];
        $hardening = self::hardening_settings();
        $home = home_url('/');
        $site = site_url('/');
        $uses_https = strpos($home, 'https://') === 0 && strpos($site, 'https://') === 0;
        $checks[] = self::security_check(
            'ssl',
            $uses_https ? 'passed' : 'attention',
            $uses_https ? 'HTTPS is active' : 'HTTPS is not fully active',
            $uses_https
                ? 'Your public WordPress URLs are using HTTPS.'
                : 'Set both the WordPress Address and Site Address to HTTPS and confirm the certificate is valid.',
            ['homeUrl' => $home, 'siteUrl' => $site]
        );

        $wp_version = get_bloginfo('version');
        $core_update_available = self::core_update_available();
        $checks[] = self::security_check(
            'wordpress-version',
            $core_update_available ? 'attention' : 'passed',
            $core_update_available ? 'WordPress core has an update available' : 'WordPress core is up to date',
            $core_update_available
                ? 'Install the available WordPress core update after taking a backup.'
                : 'This site is running the current WordPress core release known to WordPress.',
            ['version' => $wp_version]
        );

        $php_supported = version_compare(PHP_VERSION, '8.2.0', '>=');
        $checks[] = self::security_check(
            'php-version',
            $php_supported ? 'passed' : 'attention',
            $php_supported ? 'PHP is on a modern supported branch' : 'PHP should be upgraded',
            $php_supported
                ? 'The server is running PHP ' . PHP_VERSION . '.'
                : 'Move this site to PHP 8.2 or newer so it continues receiving security fixes.',
            ['version' => PHP_VERSION]
        );

        $indexable = (int) get_option('blog_public', 1) === 1;
        $checks[] = self::security_check(
            'search-indexing',
            $indexable ? 'passed' : 'attention',
            $indexable ? 'Search indexing is allowed' : 'Search indexing is discouraged',
            $indexable
                ? 'Search engines can crawl and index the site normally.'
                : 'WordPress is asking search engines not to index this site.',
            ['blogPublic' => $indexable]
        );

        $debug_on = defined('WP_DEBUG') && WP_DEBUG;
        $checks[] = self::security_check(
            'wp-debug',
            $debug_on ? 'attention' : 'passed',
            $debug_on ? 'WP_DEBUG is enabled' : 'WP_DEBUG is off',
            $debug_on
                ? 'Turn WP_DEBUG off in production so visitors do not see sensitive errors.'
                : 'Debug output is not being shown publicly by WordPress.',
            ['enabled' => $debug_on]
        );

        $inactive_plugins = self::inactive_plugin_count();
        $checks[] = self::security_check(
            'inactive-plugins',
            $inactive_plugins > 0 ? 'attention' : 'passed',
            $inactive_plugins > 0
                ? $inactive_plugins . ' inactive plugin' . ($inactive_plugins === 1 ? '' : 's') . ' installed'
                : 'No inactive plugins installed',
            $inactive_plugins > 0
                ? 'Inactive plugins still leave code on disk. Remove anything you are not planning to use.'
                : 'Only active plugin code remains in the standard plugins directory.',
            ['count' => $inactive_plugins]
        );

        $inactive_themes = self::inactive_theme_count();
        $checks[] = self::security_check(
            'inactive-themes',
            $inactive_themes > 0 ? 'attention' : 'passed',
            $inactive_themes > 0
                ? $inactive_themes . ' inactive theme' . ($inactive_themes === 1 ? '' : 's') . ' installed'
                : 'No inactive themes installed',
            $inactive_themes > 0
                ? 'Keep only the active theme and one fallback theme if you need it.'
                : 'Unused theme code is not sitting in the themes directory.',
            ['count' => $inactive_themes]
        );

        $has_admin = (bool) get_user_by('login', 'admin');
        $checks[] = self::security_check(
            'default-admin-user',
            $has_admin ? 'attention' : 'passed',
            $has_admin ? 'A user named admin exists' : 'No default admin username found',
            $has_admin
                ? 'Bots try the username admin first. Use a unique administrator username instead.'
                : 'The most commonly targeted default username is not present.',
            ['exists' => $has_admin]
        );

        $file_editor_disabled = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT || self::hardening_enabled('disable_file_editor');
        $checks[] = self::security_check(
            'file-editor',
            $file_editor_disabled ? 'passed' : 'attention',
            $file_editor_disabled ? 'Dashboard file editing is disabled' : 'Dashboard file editing is available',
            $file_editor_disabled
                ? 'Administrators cannot edit plugin and theme files directly from wp-admin.'
                : 'Disable the built-in file editor to reduce risk if an admin account is compromised.',
            ['disabled' => $file_editor_disabled]
        );

        $login_encryption_available = function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
        $checks[] = self::security_check(
            'login-event-encryption',
            $login_encryption_available ? 'passed' : 'attention',
            $login_encryption_available ? 'Encrypted login logging is available' : 'Encrypted login logging is unavailable',
            $login_encryption_available
                ? 'The connector can encrypt login monitor records before writing them to the local WordPress table.'
                : 'Enable the PHP OpenSSL extension so login monitor records can be stored securely.',
            ['available' => $login_encryption_available]
        );

        $plugin_updates = get_site_transient('update_plugins');
        $plugin_update_response = is_object($plugin_updates) && is_array($plugin_updates->response ?? null)
            ? $plugin_updates->response
            : [];
        $checks[] = self::security_check(
            'plugin-updates',
            count($plugin_update_response) > 0 ? 'attention' : 'passed',
            count($plugin_update_response) > 0
                ? count($plugin_update_response) . ' plugin' . (count($plugin_update_response) === 1 ? '' : 's') . ' need' . (count($plugin_update_response) === 1 ? 's' : '') . ' an update'
                : 'Plugins are up to date',
            count($plugin_update_response) > 0
                ? 'Review the available plugin updates and check the Vulnerabilities page for known security advisories before applying them.'
                : 'WordPress does not currently report an available plugin update.',
            ['count' => count($plugin_update_response)]
        );

        $theme_updates = get_site_transient('update_themes');
        $theme_update_response = is_object($theme_updates) && is_array($theme_updates->response ?? null)
            ? $theme_updates->response
            : [];
        $checks[] = self::security_check(
            'theme-updates',
            count($theme_update_response) > 0 ? 'attention' : 'passed',
            count($theme_update_response) > 0
                ? count($theme_update_response) . ' theme' . (count($theme_update_response) === 1 ? '' : 's') . ' need' . (count($theme_update_response) === 1 ? 's' : '') . ' an update'
                : 'Themes are up to date',
            count($theme_update_response) > 0
                ? 'Review the available theme updates and keep an up-to-date fallback theme installed.'
                : 'WordPress does not currently report an available theme update.',
            ['count' => count($theme_update_response)]
        );

        $xmlrpc_enabled = (bool) apply_filters('xmlrpc_enabled', true);
        $checks[] = self::security_check(
            'xml-rpc',
            $xmlrpc_enabled ? 'attention' : 'passed',
            $xmlrpc_enabled ? 'XML-RPC is enabled' : 'XML-RPC is disabled',
            $xmlrpc_enabled
                ? 'XML-RPC is a common target for brute-force and pingback abuse. Disable it in Hardening if this site does not need it.'
                : 'XML-RPC requests are disabled by the active security settings.',
            ['enabled' => $xmlrpc_enabled]
        );

        $application_passwords_available = function_exists('wp_is_application_passwords_available')
            ? (bool) wp_is_application_passwords_available()
            : false;
        $checks[] = self::security_check(
            'application-passwords',
            $application_passwords_available ? 'attention' : 'passed',
            $application_passwords_available ? 'Application passwords are enabled' : 'Application passwords are unavailable',
            $application_passwords_available
                ? 'Review unused application passwords and disable this feature in Hardening if integrations do not need it.'
                : 'WordPress is not making application passwords available to remote clients.',
            ['available' => $application_passwords_available]
        );

        $users_can_register = (bool) get_option('users_can_register', 0);
        $checks[] = self::security_check(
            'user-registration',
            $users_can_register ? 'attention' : 'passed',
            $users_can_register ? 'Public user registration is enabled' : 'Public user registration is off',
            $users_can_register
                ? 'Open registration can attract spam accounts and abuse. Keep it enabled only when the site needs self-service sign-ups.'
                : 'Visitors cannot create new WordPress accounts from the default registration flow.',
            ['enabled' => $users_can_register]
        );

        $default_comment_status = (string) get_option('default_comment_status', 'open');
        $comments_open = $default_comment_status === 'open';
        $checks[] = self::security_check(
            'comment-abuse',
            $comments_open ? 'attention' : 'passed',
            $comments_open ? 'New comments are open by default' : 'New comments are closed by default',
            $comments_open
                ? 'Open comments increase spam and moderation load. Confirm that comment protection and moderation rules are in place.'
                : 'New posts do not accept comments by default.',
            ['defaultStatus' => $default_comment_status]
        );

        $debug_log_enabled = defined('WP_DEBUG_LOG') && (bool) WP_DEBUG_LOG;
        $debug_display_enabled = defined('WP_DEBUG_DISPLAY') && (bool) WP_DEBUG_DISPLAY;
        $debug_public = $debug_log_enabled || $debug_display_enabled;
        $checks[] = self::security_check(
            'debug-output',
            $debug_public ? 'attention' : 'passed',
            $debug_public ? 'WordPress debug output is enabled' : 'WordPress debug output is off',
            $debug_public
                ? 'Debug logs or displayed errors can expose paths, queries, and other sensitive details. Keep debugging off on production sites.'
                : 'Production visitors are not being shown WordPress debug output.',
            ['log' => $debug_log_enabled, 'display' => $debug_display_enabled]
        );

        $salts_valid = self::site_health_security_salts_valid();
        $checks[] = self::security_check(
            'security-salts',
            $salts_valid ? 'passed' : 'attention',
            $salts_valid ? 'WordPress security salts are configured' : 'WordPress security salts need review',
            $salts_valid
                ? 'The core authentication and nonce salts are present and non-default.'
                : 'Set unique AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, and NONCE_KEY values in wp-config.php.',
            ['configured' => $salts_valid]
        );

        $protectionChecks = [
            [
                'id' => 'author-enumeration',
                'enabled' => !empty($hardening['block_user_enumeration']),
                'title' => 'Author enumeration protection is enabled',
                'description' => 'Block author probes so attackers have less help discovering usernames for password attacks.',
                'setting' => 'block_user_enumeration',
            ],
            [
                'id' => 'rest-user-listing',
                'enabled' => !empty($hardening['block_rest_user_listing']),
                'title' => 'REST user-listing protection is enabled',
                'description' => 'Restrict unauthenticated REST user listings so public requests cannot collect account names.',
                'setting' => 'block_rest_user_listing',
            ],
            [
                'id' => 'login-rate-limit',
                'enabled' => !empty($hardening['rate_limit_login']),
                'title' => 'Login rate limiting is enabled',
                'description' => 'Slow repeated failed sign-ins to reduce brute-force pressure against the login endpoint.',
                'setting' => 'rate_limit_login',
            ],
            [
                'id' => 'security-headers',
                'enabled' => !empty($hardening['security_headers']),
                'title' => 'Baseline security headers are enabled',
                'description' => 'Send browser security headers that reduce framing, MIME-sniffing, and unnecessary capability risks.',
                'setting' => 'security_headers',
            ],
        ];

        foreach ($protectionChecks as $protection) {
            $enabled = (bool) $protection['enabled'];
            $checks[] = self::security_check(
                (string) $protection['id'],
                $enabled ? 'passed' : 'attention',
                $enabled ? (string) $protection['title'] : str_replace(' is enabled', ' is not enabled', (string) $protection['title']),
                $enabled
                    ? (string) $protection['description']
                    : (string) $protection['description'] . ' Enable the matching option in Hardening.',
                ['enabled' => $enabled, 'setting' => (string) $protection['setting']]
            );
        }

        $persistent_object_cache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $checks[] = self::security_check(
            'persistent-object-cache',
            $persistent_object_cache ? 'passed' : 'attention',
            $persistent_object_cache ? 'Persistent object cache is available' : 'No persistent object cache detected',
            $persistent_object_cache
                ? 'WordPress can reuse cached database results between requests.'
                : 'A persistent object cache can reduce database work and improve response times on busy sites.',
            ['enabled' => $persistent_object_cache]
        );

        $opcache_enabled = function_exists('opcache_get_status')
            && in_array(strtolower((string) ini_get('opcache.enable')), ['1', 'on', 'true'], true);
        $checks[] = self::security_check(
            'php-opcache',
            $opcache_enabled ? 'passed' : 'attention',
            $opcache_enabled ? 'PHP OPcache is enabled' : 'PHP OPcache is not detected',
            $opcache_enabled
                ? 'PHP can reuse compiled scripts instead of compiling them on every request.'
                : 'Enable PHP OPcache in the hosting environment to reduce PHP compilation work and improve response times.',
            ['enabled' => $opcache_enabled]
        );

        $wp_cron_disabled = defined('DISABLE_WP_CRON') && (bool) DISABLE_WP_CRON;
        $checks[] = self::security_check(
            'wp-cron',
            $wp_cron_disabled ? 'attention' : 'passed',
            $wp_cron_disabled ? 'Built-in WP-Cron is disabled' : 'Built-in WP-Cron is available',
            $wp_cron_disabled
                ? 'Confirm that a server-side cron job is configured or scheduled tasks such as updates and backups may be delayed.'
                : 'WordPress can run scheduled tasks through its built-in cron trigger.',
            ['disabled' => $wp_cron_disabled]
        );

        $attention = array_values(array_filter($checks, static fn($check) => $check['status'] === 'attention'));
        $passed = array_values(array_filter($checks, static fn($check) => $check['status'] === 'passed'));
        $checked_at = gmdate('c');
        $result = [
            'checkedAt' => $checked_at,
            'cachedUntil' => gmdate('c', time() + HOUR_IN_SECONDS),
            'cacheHit' => false,
            'summary' => [
                'attention' => count($attention),
                'passed' => count($passed),
                'total' => count($checks),
            ],
            'attention' => $attention,
            'passed' => $passed,
            'checks' => $checks,
        ];

        set_transient(self::SITE_HEALTH_CACHE_KEY, $result, HOUR_IN_SECONDS);

        return $result;
    }

    private static function update_hardening_settings(array $updates): array {
        $settings = self::hardening_settings();
        $allowed = array_keys(self::default_hardening_settings());

        foreach ($allowed as $key) {
            if (array_key_exists($key, $updates)) {
                $settings[$key] = (bool) $updates[$key];
            }
        }

        update_option(self::OPTION_SECURITY_HARDENING, $settings, false);
        delete_transient(self::SITE_HEALTH_CACHE_KEY);

        return self::public_hardening_state();
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

    private static function login_events_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'cortexwp_login_events';
    }

    private static function ensure_login_events_table(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $table = self::login_events_table();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attempted_at datetime NOT NULL,
            success tinyint(1) NOT NULL DEFAULT 0,
            username_hash char(64) NOT NULL DEFAULT '',
            email_hash char(64) NOT NULL DEFAULT '',
            role_hash char(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_hash char(64) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY attempted_at (attempted_at),
            KEY success_time (success, attempted_at),
            KEY username_time (username_hash, attempted_at),
            KEY email_time (email_hash, attempted_at),
            KEY role_time (role_hash, attempted_at),
            KEY user_time (user_id, attempted_at),
            KEY ip_time (ip_hash, attempted_at)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option(self::OPTION_LOGIN_TABLE_VERSION, self::LOGIN_TABLE_VERSION, false);
    }

    private static function security_secret_material(): string {
        $parts = [];

        if (function_exists('wp_salt')) {
            $parts[] = wp_salt('auth');
            $parts[] = wp_salt('secure_auth');
        }

        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT'] as $constant) {
            if (defined($constant)) {
                $parts[] = (string) constant($constant);
            }
        }

        $material = implode('|', array_filter($parts));
        return $material !== '' ? $material : self::connector_key();
    }

    private static function security_crypto_key(string $purpose): string {
        return hash_hmac('sha256', $purpose, self::security_secret_material(), true);
    }

    private static function security_lookup_hash(string $value): string {
        $value = strtolower(trim($value));
        return $value === '' ? '' : hash_hmac('sha256', $value, self::security_crypto_key('lookup-v1'));
    }

    private static function encrypt_security_payload(array $payload): ?string {
        $json = wp_json_encode($payload);
        if (!is_string($json)) {
            return null;
        }

        if (!function_exists('openssl_encrypt')) {
            return null;
        }

        try {
            $iv = random_bytes(12);
        } catch (Throwable $exception) {
            return null;
        }

        $tag = '';
        $encrypted = openssl_encrypt(
            $json,
            'aes-256-gcm',
            self::security_crypto_key('login-events-v1'),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if (!is_string($encrypted)) {
            return null;
        }

        return implode(':', [
            'v1',
            rtrim(strtr(base64_encode($iv), '+/', '-_'), '='),
            rtrim(strtr(base64_encode($tag), '+/', '-_'), '='),
            rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '='),
        ]);
    }

    private static function base64url_decode(string $value): string {
        $padded = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);
        return is_string($decoded) ? $decoded : '';
    }

    private static function decrypt_security_payload(string $value): array {
        $parts = explode(':', $value);
        if (count($parts) !== 4 || $parts[0] !== 'v1' || !function_exists('openssl_decrypt')) {
            return [];
        }

        [, $iv, $tag, $encrypted] = $parts;
        $json = openssl_decrypt(
            self::base64url_decode($encrypted),
            'aes-256-gcm',
            self::security_crypto_key('login-events-v1'),
            OPENSSL_RAW_DATA,
            self::base64url_decode($iv),
            self::base64url_decode($tag)
        );
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function valid_ip(string $value): string {
        $value = trim($value);
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    private static function client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP'] as $key) {
            $ip = self::valid_ip(self::safe_server_value($key, 128));
            if ($ip !== '') {
                return $ip;
            }
        }

        $forwarded = self::safe_server_value('HTTP_X_FORWARDED_FOR', 500);
        foreach (explode(',', $forwarded) as $candidate) {
            $ip = self::valid_ip($candidate);
            if ($ip !== '') {
                return $ip;
            }
        }

        return self::valid_ip(self::safe_server_value('REMOTE_ADDR', 128));
    }

    private static function client_location(): array {
        $country = strtoupper(self::safe_server_value('HTTP_CF_IPCOUNTRY', 16));
        $city = self::safe_server_value('HTTP_CF_IPCITY', 120);
        $region = self::safe_server_value('HTTP_CF_REGION', 120);

        return [
            'city' => $city ?: null,
            'region' => $region ?: null,
            'country' => preg_match('/^[A-Z]{2}$/', $country) && $country !== 'XX' ? $country : null,
        ];
    }

    private static function login_rate_key(): string {
        $ip = self::client_ip();
        return 'cortexwp_ai_login_rate_' . ($ip ? self::security_lookup_hash($ip) : 'unknown');
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
        $is_connector_page = in_array($hook, ['toplevel_page_cortexwp-ai', 'cortexwp_page_cortexwp-ai-snippets'], true);
        $is_update_screen = in_array($hook, ['plugins.php', 'themes.php', 'update-core.php'], true);

        if (!$is_connector_page && !$is_update_screen) {
            return;
        }

        wp_register_style('cortexwp-ai-admin', false, [], self::plugin_version());
        wp_enqueue_style('cortexwp-ai-admin');
        wp_add_inline_style('cortexwp-ai-admin', self::admin_css());

        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', self::admin_js());

        if ($is_connector_page && $hook === 'cortexwp_page_cortexwp-ai-snippets') {
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
    width: auto;
    max-width: 1180px;
    margin: 24px 20px 0;
}
body.toplevel_page_cortexwp-ai #wpbody-content,
body.cortexwp_page_cortexwp-ai-snippets #wpbody-content {
    overflow: visible;
}
body.toplevel_page_cortexwp-ai #wpbody-content .notice,
body.cortexwp_page_cortexwp-ai-snippets #wpbody-content .notice,
body.toplevel_page_cortexwp-ai #wpbody-content .updated,
body.cortexwp_page_cortexwp-ai-snippets #wpbody-content .updated,
body.toplevel_page_cortexwp-ai #wpbody-content .error,
body.cortexwp_page_cortexwp-ai-snippets #wpbody-content .error,
body.toplevel_page_cortexwp-ai #wpbody-content .update-nag,
body.cortexwp_page_cortexwp-ai-snippets #wpbody-content .update-nag,
body.toplevel_page_cortexwp-ai #wpbody-content #message,
body.cortexwp_page_cortexwp-ai-snippets #wpbody-content #message {
    display: none !important;
}
body.toplevel_page_cortexwp-ai #wpbody-content > .wrap.cortexwp-shell,
body.cortexwp_page_cortexwp-ai-snippets #wpbody-content > .wrap.cortexwp-shell {
    clear: both;
}
.cortexwp-managed-auto-update {
    display: inline-block;
    color: #1d4ed8;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.45;
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
.cortexwp-connection-page {
    max-width: 980px;
    margin: 42px auto 0;
}
.cortexwp-connection-card {
    overflow: hidden;
    border: 1px solid #dfe4eb;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 12px 32px rgba(15, 23, 42, .06);
}
.cortexwp-connection-heading {
    display: flex;
    align-items: center;
    min-height: 86px;
    padding: 24px 32px;
    border-bottom: 1px solid #edf0f4;
}
.cortexwp-connection-heading h1 {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 650;
    letter-spacing: -.02em;
    line-height: 1.2;
}
.cortexwp-connection-fields {
    display: grid;
    gap: 24px;
    padding: 30px 32px 34px;
}
.cortexwp-connection-field {
    display: grid;
    gap: 9px;
}
.cortexwp-connection-field .cortexwp-label {
    margin: 0;
    color: #273247;
    font-size: 13px;
    font-weight: 650;
    letter-spacing: 0;
    text-transform: none;
}
.cortexwp-connection-field .cortexwp-input,
.cortexwp-connection-field .cortexwp-key-input {
    min-height: 48px;
    padding: 0 14px;
    border-color: #cfd6e1 !important;
    border-radius: 8px;
    background: #fbfcfe !important;
    font-size: 14px;
    transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.cortexwp-connection-field .cortexwp-input:focus,
.cortexwp-connection-field .cortexwp-key-input:focus {
    border-color: #3478f6 !important;
    background: #fff !important;
    box-shadow: 0 0 0 3px rgba(52, 120, 246, .14) !important;
    outline: none;
}
.cortexwp-connection-field .cortexwp-key-input {
    color: #344054 !important;
    font-size: 13px;
    letter-spacing: .01em;
}
.cortexwp-connection-help {
    margin: 0;
    color: #667085;
    font-size: 12px;
    line-height: 1.6;
}
.cortexwp-connection-page .cortexwp-key-row {
    grid-template-columns: minmax(0, 1fr) auto auto auto;
    gap: 9px;
}
.cortexwp-connection-page .cortexwp-button {
    min-height: 48px;
    padding: 0 16px !important;
    border-color: #cfd6e1 !important;
    border-radius: 8px !important;
    font-size: 13px;
    white-space: nowrap;
}
.cortexwp-connection-page .cortexwp-button-primary {
    border-color: #2563eb !important;
    background: #2563eb !important;
}
.cortexwp-connection-page .cortexwp-button-primary:hover {
    border-color: #1d4ed8 !important;
    background: #1d4ed8 !important;
}
.cortexwp-connection-page .cortexwp-button:disabled {
    border-color: #d9dee7 !important;
    background: #f4f6f8 !important;
    color: #8a94a3 !important;
    cursor: default;
    opacity: 1;
}
.cortexwp-inline-notice {
    margin: 18px 0 0;
    padding: 12px 14px;
    border: 1px solid #b7d8c2;
    background: #edf8f0;
    color: #155c35;
    font-weight: 700;
}
.cortexwp-update-modal {
    position: fixed;
    z-index: 100000;
    inset: 0;
    display: grid;
    place-items: center;
    padding: 24px;
    background: rgba(15, 23, 42, .58);
}
.cortexwp-update-dialog {
    width: min(100%, 570px);
    overflow: hidden;
    border: 1px solid #d8dee8;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 28px 80px rgba(15, 23, 42, .28);
    color: #111827;
}
.cortexwp-update-dialog-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    padding: 28px 28px 20px;
    background: linear-gradient(135deg, #eff6ff, #ffffff 60%);
}
.cortexwp-update-dialog-kicker {
    margin: 0 0 8px;
    color: #2563eb;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
}
.cortexwp-update-dialog h2 {
    margin: 0;
    font-size: 25px;
    line-height: 1.15;
}
.cortexwp-update-dialog-copy {
    margin: 10px 0 0;
    color: #5b6472;
    font-size: 14px;
    line-height: 1.65;
}
.cortexwp-update-dialog-close {
    border: 0;
    background: transparent;
    color: #5b6472;
    cursor: pointer;
    font-size: 25px;
    line-height: 1;
}
.cortexwp-update-benefits {
    display: grid;
    gap: 12px;
    margin: 0;
    padding: 22px 28px 8px;
    list-style: none;
}
.cortexwp-update-benefits li {
    display: flex;
    gap: 11px;
    align-items: flex-start;
    color: #263241;
    font-size: 14px;
    line-height: 1.5;
}
.cortexwp-update-benefits li::before {
    content: '✓';
    display: inline-grid;
    flex: 0 0 22px;
    height: 22px;
    place-items: center;
    border-radius: 50%;
    background: #e8f7ee;
    color: #047857;
    font-weight: 900;
}
.cortexwp-update-dialog-foot {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 10px;
    padding: 22px 28px 28px;
}
.cortexwp-update-dialog-foot button {
    min-height: 40px;
    padding: 0 15px;
    border: 1px solid #d8dee8;
    border-radius: 8px;
    background: #fff;
    color: #111827;
    cursor: pointer;
    font-weight: 700;
}
.cortexwp-update-dialog-foot .cortexwp-update-cancel {
    border-color: #2563eb;
    background: #2563eb;
    color: #fff;
}
@media (max-width: 520px) {
    .cortexwp-connection-page {
        margin: 24px 12px 0;
    }
    .cortexwp-connection-heading,
    .cortexwp-connection-fields {
        padding-left: 20px;
        padding-right: 20px;
    }
    .cortexwp-connection-page .cortexwp-key-row {
        grid-template-columns: 1fr 1fr;
    }
    .cortexwp-connection-page .cortexwp-key-input {
        grid-column: 1 / -1;
    }
    .cortexwp-update-modal { padding: 14px; }
    .cortexwp-update-dialog-head,
    .cortexwp-update-benefits,
    .cortexwp-update-dialog-foot { padding-left: 20px; padding-right: 20px; }
    .cortexwp-update-dialog-foot { display: grid; }
    .cortexwp-update-dialog-foot button { width: 100%; }
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
        var $input = $('#cortexwp-api-key');
        if ($input.attr('data-revealed') !== '1') {
            setStatus('View the key once before copying it.', 'error');
            return;
        }

        var value = $input.val();
        $.when(copyValue(value)).done(function () {
            setStatus('API key copied to clipboard.', 'success');
        }).fail(function () {
            setStatus('Copy failed. Select the key and copy it manually.', 'error');
        });
    });

    function markKeyRevealed(key) {
        var $input = $('#cortexwp-api-key');
        $input.val(key).attr('data-revealed', '1');
        $('#cortexwp-view-key').prop('disabled', true).text('Viewed once');
        $('#cortexwp-copy-key').prop('disabled', false);
    }

    $(document).on('click', '#cortexwp-view-key', function () {
        var $button = $(this);

        if (!window.confirm('The complete API key will be shown once. After this, you must regenerate the key to view another one. Continue?')) {
            return;
        }

        $button.prop('disabled', true).text('Revealing...');
        setStatus('Revealing the API key once...', '');

        $.post(ajaxurl, {
            action: 'cortexwp_ai_reveal_key',
            nonce: $('#cortexwp-reveal-nonce').val()
        }).done(function (response) {
            if (!response || !response.success || !response.data || !response.data.key) {
                setStatus(response && response.data && response.data.message ? response.data.message : 'Unable to reveal the API key.', 'error');
                $button.prop('disabled', false).text('View key once');
                return;
            }

            markKeyRevealed(response.data.key);
            setStatus('API key revealed. Copy it now; it will be masked again when you leave this page.', 'success');
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                ? xhr.responseJSON.data.message
                : 'Unable to reveal the API key. Please try again.';
            setStatus(message, 'error');
            if (xhr.status !== 409) {
                $button.prop('disabled', false).text('View key once');
            } else {
                $button.prop('disabled', true).text('Viewed once');
            }
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

            markKeyRevealed(response.data.key);
            $('#cortexwp-key-domain').text(response.data.domain);
            $('#cortexwp-current-domain').text(response.data.current_domain);
            setStatus('New API key generated. Copy it into CortexWP to reconnect this site.', 'success');
        }).fail(function () {
            setStatus('Unable to regenerate the API key. Please try again.', 'error');
        }).always(function () {
            $button.prop('disabled', false).text('Regenerate key');
        });
    });

    function cortexwpManagedUpdates() {
        return document.body && document.body.classList.contains('cortexwp-updates-managed');
    }

    var cortexwpNativeUpdateCall = false;

    function cortexwpIsUpdateAction(action) {
        return action === 'update-plugin' || action === 'update-theme' || action === 'update-core' || action === 'do-core-upgrade';
    }

    function cortexwpBlockedUpdatePromise() {
        return $.Deferred().reject({ cortexwpBlocked: true }).promise();
    }

    function cortexwpInstallWordPressUpdateGuards() {
        if (!cortexwpManagedUpdates() || !window.wp || !wp.updates) {
            return false;
        }

        var updates = wp.updates;

        ['updatePlugin', 'updateTheme'].forEach(function (method) {
            if (typeof updates[method] !== 'function' || updates[method].cortexwpGuarded) {
                return;
            }

            var original = updates[method];
            var guarded = function () {
                var context = this;
                var args = arguments;

                cortexwpOpenUpdateModal(function () {
                    cortexwpNativeUpdateCall = true;
                    try {
                        original.apply(context, args);
                    } finally {
                        cortexwpNativeUpdateCall = false;
                    }
                });

                return cortexwpBlockedUpdatePromise();
            };

            guarded.cortexwpGuarded = true;
            updates[method] = guarded;
        });

        if (typeof updates.ajax === 'function' && !updates.ajax.cortexwpGuarded) {
            var originalAjax = updates.ajax;
            var guardedAjax = function (action, data) {
                if (!cortexwpNativeUpdateCall && cortexwpIsUpdateAction(action)) {
                    var context = this;
                    var args = arguments;

                    cortexwpOpenUpdateModal(function () {
                        cortexwpNativeUpdateCall = true;
                        try {
                            originalAjax.apply(context, args);
                        } finally {
                            cortexwpNativeUpdateCall = false;
                        }
                    });

                    return cortexwpBlockedUpdatePromise();
                }

                return originalAjax.apply(this, arguments);
            };

            guardedAjax.cortexwpGuarded = true;
            updates.ajax = guardedAjax;
        }

        return typeof updates.updatePlugin === 'function' || typeof updates.updateTheme === 'function';
    }

    function cortexwpWatchForWordPressUpdateGuards(attempts) {
        if (cortexwpInstallWordPressUpdateGuards() || attempts >= 100) {
            return;
        }

        window.setTimeout(function () {
            cortexwpWatchForWordPressUpdateGuards(attempts + 1);
        }, 50);
    }

    function cortexwpUpdateAnchor(element) {
        var href = String(element.getAttribute('href') || '').toLowerCase();
        return href.indexOf('update-core.php') !== -1 ||
            /update\.php[^#]*(?:upgrade-plugin|upgrade-theme|update-selected|do-core-upgrade)/.test(href) ||
            (element.className && String(element.className).indexOf('update-link') !== -1);
    }

    function cortexwpUpdateForm(form) {
        var action = String(form.getAttribute('action') || '').toLowerCase();
        var formAction = String($(form).find('input[name="action"]').val() || '').toLowerCase();
        var coreUpgrade = action.indexOf('update-core.php') !== -1 && (
            $(form).find('[name="upgrade"], [name="do-core-upgrade"], [name="update-core"]').length > 0 ||
            /update now|upgrade/.test(String($(form).find(':submit').text() || $(form).find(':submit').val() || '').toLowerCase())
        );
        return coreUpgrade ||
            (action.indexOf('update.php') !== -1 && /upgrade-plugin|upgrade-theme|update-selected|do-core-upgrade/.test(formAction));
    }

    function cortexwpOpenUpdateModal(proceed) {
        var $modal = $('#cortexwp-update-modal');
        if (!$modal.length) {
            $modal = $('<div id="cortexwp-update-modal" class="cortexwp-update-modal" role="dialog" aria-modal="true" aria-labelledby="cortexwp-update-modal-title">' +
                '<div class="cortexwp-update-dialog">' +
                    '<div class="cortexwp-update-dialog-head">' +
                        '<div><p class="cortexwp-update-dialog-kicker">A safer update path</p>' +
                        '<h2 id="cortexwp-update-modal-title">Update this on CortexWP?</h2>' +
                        '<p class="cortexwp-update-dialog-copy">WordPress updates are dangerous! Updating from <b> <a href="https://app.cortexwp.ai" target=blank>CortexWP dashboard </a> </b> adds the safety net: a clear way back if something breaks, & a visual report of what changed in your pages.</p></div>' +
                        '<button type="button" class="cortexwp-update-dialog-close" aria-label="Close">&times;</button>' +
                    '</div>' +
                    '<ul class="cortexwp-update-benefits">' +
                        '<li>Take an automatic backup before the update starts.</li>' +
                        '<li>Run visual regression checks and report page changes or breakage.</li>' +
                        '<li>Keep a useful update history with rollback options.</li>' +
                    '</ul>' +
                    '<div class="cortexwp-update-dialog-foot">' +
                        '<button type="button" class="cortexwp-update-continue">No thanks, continue here</button>' +
                        '<button type="button" class="cortexwp-update-cancel">Ok, Cancel</button>' +
                    '</div>' +
                '</div>' +
            '</div>').appendTo('body');
        }

        if ($modal.is(':visible')) {
            return;
        }

        $modal.data('cortexwp-proceed', proceed).show();
        $modal.find('.cortexwp-update-continue').trigger('focus');
    }

    $(document).on('click', '#cortexwp-update-modal .cortexwp-update-dialog-close, #cortexwp-update-modal .cortexwp-update-cancel', function () {
        $('#cortexwp-update-modal').hide().removeData('cortexwp-proceed');
    });
    $(document).on('click', '#cortexwp-update-modal .cortexwp-update-continue', function () {
        var proceed = $('#cortexwp-update-modal').data('cortexwp-proceed');
        $('#cortexwp-update-modal').hide().removeData('cortexwp-proceed');
        if (typeof proceed === 'function') proceed();
    });
    $(document).on('click', 'a[href]', function (event) {
        if (!cortexwpManagedUpdates() || $(this).closest('#cortexwp-update-modal').length || this.dataset.cortexwpAllowed === '1' || !cortexwpUpdateAnchor(this)) return;
        if (cortexwpInstallWordPressUpdateGuards() && $(this).closest('[data-plugin] .update-link, .themes-php .update-link, .update-now').length) return;
        event.preventDefault();
        var anchor = this;
        cortexwpOpenUpdateModal(function () {
            anchor.dataset.cortexwpAllowed = '1';
            anchor.click();
            window.setTimeout(function () { delete anchor.dataset.cortexwpAllowed; }, 0);
        });
    });
    $(document).on('submit', 'form', function (event) {
        if (!cortexwpManagedUpdates() || this.dataset.cortexwpAllowed === '1' || !cortexwpUpdateForm(this)) return;
        event.preventDefault();
        var form = this;
        cortexwpOpenUpdateModal(function () {
            form.dataset.cortexwpAllowed = '1';
            HTMLFormElement.prototype.submit.call(form);
            window.setTimeout(function () { delete form.dataset.cortexwpAllowed; }, 0);
        });
    });
    $(document).on('keydown', function (event) {
        if (event.key === 'Escape') $('#cortexwp-update-modal').hide().removeData('cortexwp-proceed');
    });

    $(function () {
        cortexwpWatchForWordPressUpdateGuards(0);
    });
})(jQuery);
JS;
    }

    public static function settings_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage CortexWP.', 'cortexwp-ai'));
        }

        $key = self::connector_key();
        $key_viewed = self::key_has_been_viewed();
        ?>
        <div class="wrap cortexwp-admin cortexwp-connection-page">
            <section class="cortexwp-connection-card" aria-label="CortexWP connection">
                <header class="cortexwp-connection-heading">
                    <h1>CortexWP Connector</h1>
                </header>
                <div class="cortexwp-connection-fields">
                    <div class="cortexwp-connection-field">
                        <label class="cortexwp-label" for="cortexwp-api-key">API key</label>
                        <div class="cortexwp-key-row">
                            <input id="cortexwp-api-key" type="text" readonly class="cortexwp-key-input code" value="<?php echo esc_attr(self::connector_key_prefix($key)); ?>" data-revealed="0" onclick="this.select();" aria-label="CortexWP API key, masked" />
                            <button type="button" id="cortexwp-view-key" class="button cortexwp-button" <?php disabled($key_viewed, true); ?>><?php echo $key_viewed ? 'Viewed once' : 'View key once'; ?></button>
                            <button type="button" id="cortexwp-copy-key" class="button cortexwp-button" disabled>Copy key</button>
                            <button type="button" id="cortexwp-regenerate-key" class="button cortexwp-button cortexwp-button-primary">Regenerate key</button>
                        </div>
                        <p class="cortexwp-connection-help">The complete key is only revealed once. Regenerate it if you need a new key. Add this API key in your <a href="https://app.cortexwp.ai" target="_blank"> CortexWP Dashboard </a> -> Add Site</p>
                    </div>

                    <input type="hidden" id="cortexwp-reveal-nonce" value="<?php echo esc_attr(wp_create_nonce('cortexwp_ai_reveal_key')); ?>" />
                    <input type="hidden" id="cortexwp-regenerate-nonce" value="<?php echo esc_attr(wp_create_nonce('cortexwp_ai_regenerate_key')); ?>" />
                    <div id="cortexwp-key-status" class="cortexwp-status" role="status" aria-live="polite"></div>
                </div>
            </section>
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
                    <h1>CortexWP PHP Snippets</h1>
                    <p class="cortexwp-hero-copy">Create, review, and control PHP snippets loaded by the CortexWP.</p>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=cortexwp-ai-snippets&new=1')); ?>" class="button button-primary cortexwp-button cortexwp-button-primary">Add snippet</a>
            </div>

            <?php if (isset($_GET['updated'])) : ?>
                <div class="cortexwp-inline-notice cortexwp-inline-notice-success" role="status">Snippet saved.</div>
            <?php elseif (isset($_GET['deleted'])) : ?>
                <div class="cortexwp-inline-notice cortexwp-inline-notice-success" role="status">Snippet deleted.</div>
            <?php endif; ?>

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
                    <h2 class="cortexwp-section-title">PHP Snippets</h2>
                </div>
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
                    <p>Create your first managed PHP snippet or let CortexWP add one.</p>
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
            $had_recovery_bridge = self::has_any_recovery_bridge();
            self::store_key(self::new_key());
            update_option(self::OPTION_KEY_VIEWED, false, false);
            update_option(self::OPTION_KEY_DOMAIN, self::current_domain(), false);
            if ($had_recovery_bridge) {
                self::ensure_recovery_bridge(true);
            }
        }

        update_option(self::OPTION_SETTINGS, array_merge(self::connector_settings(), [
            'connection_method' => 'plugin',
        ]), false);

        wp_safe_redirect(admin_url('admin.php?page=cortexwp-ai&updated=1'));
        exit;
    }

    public static function ajax_reveal_key(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to manage CortexWP.'], 403);
        }

        check_ajax_referer('cortexwp_ai_reveal_key', 'nonce');

        if (self::key_has_been_viewed()) {
            wp_send_json_error([
                'message' => 'This API key has already been revealed. Regenerate the key to view a new one.',
            ], 409);
        }

        $key = self::connector_key();
        if ($key === '') {
            wp_send_json_error(['message' => 'The connector key is unavailable. Regenerate it and try again.'], 500);
        }

        // Mark the credential as viewed before returning it. A page refresh can
        // never expose the same secret again through the WordPress UI.
        if (self::key_has_been_viewed()) {
            wp_send_json_error([
                'message' => 'This API key has already been revealed. Regenerate the key to view a new one.',
            ], 409);
        }

        self::mark_key_viewed_once();

        nocache_headers();

        wp_send_json_success([
            'key' => $key,
            'prefix' => self::connector_key_prefix($key),
        ]);
    }

    public static function ajax_regenerate_key(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to manage CortexWP.'], 403);
        }

        check_ajax_referer('cortexwp_ai_regenerate_key', 'nonce');

        $key = self::new_key();
        $domain = self::current_domain();
        $had_recovery_bridge = self::has_any_recovery_bridge();

        self::store_key($key);
        // The regenerated key is returned once in this response so the admin
        // can copy it into CortexWP. It is masked again on the next page load.
        update_option(self::OPTION_KEY_VIEWED, true, false);
        update_option(self::OPTION_KEY_DOMAIN, $domain, false);
        update_option(self::OPTION_SETTINGS, array_merge(self::connector_settings(), [
            'connection_method' => 'plugin',
        ]), false);
        if ($had_recovery_bridge) {
            self::ensure_recovery_bridge(true);
        }

        nocache_headers();
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
            case 'management_snapshot':
                return rest_ensure_response(self::management_snapshot());
            case 'site_refresh_snapshot':
                return rest_ensure_response(self::site_refresh_snapshot());
            case 'wp_cli':
                return rest_ensure_response(self::run_wp_cli((array) ($params['args'] ?? []), (int) ($params['max_output_bytes'] ?? 500000)));
            case 'shell':
                return rest_ensure_response(self::run_shell((string) ($params['command'] ?? ''), (string) ($params['cwd'] ?? ABSPATH), (int) ($params['max_output_bytes'] ?? 500000)));
            case 'read_file':
                return rest_ensure_response(self::read_file((string) ($params['path'] ?? ''), (int) ($params['lines'] ?? 120), (int) ($params['max_output_bytes'] ?? 500000)));
            case 'list_directory':
                return rest_ensure_response(self::list_directory((string) ($params['path'] ?? ABSPATH)));
            case 'php_environment':
                return rest_ensure_response(self::php_environment());
            case 'patch_file':
                return rest_ensure_response(self::patch_file(
                    (string) ($params['path'] ?? ''),
                    (string) ($params['search'] ?? ''),
                    (string) ($params['replace'] ?? ''),
                    (int) ($params['max_replacements'] ?? 1)
                ));
            case 'restore_file_backup':
                return rest_ensure_response(self::restore_file_backup(
                    (string) ($params['backup_path'] ?? ''),
                    (string) ($params['target_path'] ?? '')
                ));
            case 'component_backup_upload':
                $result = self::component_backup_upload(
                    (string) ($params['type'] ?? ''),
                    (string) ($params['target'] ?? ''),
                    (string) ($params['upload_url'] ?? '')
                );
                return is_wp_error($result) ? $result : rest_ensure_response($result);
            case 'component_backup_restore':
                $result = self::component_backup_restore(
                    (string) ($params['type'] ?? ''),
                    (string) ($params['target'] ?? ''),
                    (string) ($params['download_url'] ?? ''),
                    (string) ($params['sha256'] ?? ''),
                    (string) ($params['source_path'] ?? '')
                );
                return is_wp_error($result) ? $result : rest_ensure_response($result);
            case 'recovery_bridge_install':
                return rest_ensure_response(self::ensure_recovery_bridge(true));
            case 'recovery_bridge_status':
                return rest_ensure_response(self::recovery_bridge_status());
            case 'recovery_audit_log':
                return rest_ensure_response(self::recovery_audit_log((int) ($params['limit'] ?? 50)));
            case 'sync_redirect_rules':
                return rest_ensure_response(self::sync_redirect_rules((array) ($params['rules'] ?? [])));
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
            case 'security_site_health':
                return rest_ensure_response(self::site_health_scan((bool) ($params['refresh'] ?? false)));
            case 'security_snapshot':
                return rest_ensure_response(self::security_snapshot((bool) ($params['refresh'] ?? false)));
            case 'security_hardening_get':
                return rest_ensure_response(self::public_hardening_state());
            case 'security_hardening_update':
                return rest_ensure_response(self::update_hardening_settings((array) ($params['settings'] ?? [])));
            case 'security_login_events':
                return rest_ensure_response(self::security_login_events((array) ($params['filters'] ?? $params)));
            case 'security_login_events_clear':
                return rest_ensure_response(self::clear_security_login_events());
            case 'autopilot_update_policy':
                $enabled = !empty($params['enabled']);
                update_option(self::OPTION_AUTOPILOT_MANAGED, $enabled, false);
                if ($enabled) {
                    // Clear existing per-item selections so the native updater cannot
                    // continue running a previously enabled plugin/theme auto-update.
                    update_site_option('auto_update_plugins', []);
                    update_site_option('auto_update_themes', []);
                }

                return rest_ensure_response([
                    'managed' => $enabled,
                    'plugin_auto_updates' => [],
                    'theme_auto_updates' => [],
                    'core_security_updates_allowed' => $enabled,
                ]);
        }

        return new WP_Error('cortexwp_ai_unknown_action', 'Unknown CortexWP action.', ['status' => 400]);
    }

    private static function normalize_component_type(string $type): string {
        $type = sanitize_key($type);
        return in_array($type, ['plugin', 'theme'], true) ? $type : '';
    }

    private static function resolve_theme_target(string $target): ?array {
        $needle = strtolower(trim($target));
        if ($needle === '') {
            return null;
        }

        foreach (wp_get_themes() as $slug => $theme) {
            $path = $theme->get_stylesheet_directory();
            $aliases = array_filter([
                (string) $slug,
                (string) $theme->get('Name'),
                (string) $theme->get_stylesheet(),
                (string) $theme->get_template(),
                (string) $path,
            ]);

            foreach ($aliases as $alias) {
                if (strtolower((string) $alias) === $needle) {
                    return [
                        'name' => (string) $slug,
                        'title' => (string) $theme->get('Name'),
                        'version' => (string) $theme->get('Version'),
                        'source_path' => (string) $path,
                        'kind' => 'theme',
                    ];
                }
            }
        }

        return null;
    }

    private static function component_source_from_target(string $type, string $target) {
        $type = self::normalize_component_type($type);

        if ($type === '') {
            return new WP_Error('cortexwp_ai_component_type', 'Component type must be plugin or theme.', ['status' => 400]);
        }

        if ($type === 'plugin') {
            $plugin = self::resolve_plugin_target($target, false);
            if (!$plugin || (string) ($plugin['kind'] ?? '') !== 'plugin') {
                return new WP_Error('cortexwp_ai_component_not_found', 'Plugin not found for backup.', ['status' => 404]);
            }

            $file = (string) $plugin['plugin_file'];
            $source = strpos($file, '/') !== false
                ? trailingslashit(WP_PLUGIN_DIR) . dirname($file)
                : trailingslashit(WP_PLUGIN_DIR) . $file;

            return [
                'type' => 'plugin',
                'name' => (string) $plugin['name'],
                'title' => (string) $plugin['title'],
                'plugin_file' => $file,
                'version' => (string) $plugin['version'],
                'source_path' => $source,
                'root_path' => WP_PLUGIN_DIR,
            ];
        }

        $theme = self::resolve_theme_target($target);
        if (!$theme) {
            return new WP_Error('cortexwp_ai_component_not_found', 'Theme not found for backup.', ['status' => 404]);
        }

        return [
            'type' => 'theme',
            'name' => (string) $theme['name'],
            'title' => (string) $theme['title'],
            'version' => (string) $theme['version'],
            'source_path' => (string) $theme['source_path'],
            'root_path' => get_theme_root((string) $theme['name']),
        ];
    }

    private static function component_source_from_backup_path(string $type, string $target, string $source_path) {
        $resolved = self::component_source_from_target($type, $target);
        if (!is_wp_error($resolved)) {
            return $resolved;
        }

        $type = self::normalize_component_type($type);
        $source_path = trim($source_path);
        if ($type === '' || $source_path === '') {
            return $resolved;
        }

        $root = $type === 'plugin'
            ? realpath(WP_PLUGIN_DIR)
            : realpath(get_theme_root());
        $parent = realpath(dirname($source_path));
        $base = basename($source_path);

        if (!$root || !$parent || $base === '' || $base === '.' || $base === '..') {
            return $resolved;
        }

        if (!self::path_is_within_or_equal($parent, $root)) {
            return $resolved;
        }

        return [
            'type' => $type,
            'name' => $base,
            'title' => $base,
            'version' => '',
            'source_path' => trailingslashit($parent) . $base,
            'root_path' => $root,
        ];
    }

    private static function path_is_within_or_equal(string $path, string $directory): bool {
        $real_path = realpath($path);
        $real_directory = realpath($directory);

        if (!$real_path || !$real_directory) {
            return false;
        }

        $normalized_path = self::normalized_real_path($real_path);
        $normalized_directory = self::normalized_real_path($real_directory);

        return $normalized_path === $normalized_directory
            || strpos($normalized_path, trailingslashit($normalized_directory)) === 0;
    }

    private static function assert_component_source(array $component) {
        $source = realpath((string) ($component['source_path'] ?? ''));
        $root = realpath((string) ($component['root_path'] ?? ''));

        if (!$source || !$root || !file_exists($source) || !is_readable($source)) {
            return new WP_Error('cortexwp_ai_component_source', 'Component source was not found or is not readable.', ['status' => 404]);
        }

        if (!self::path_is_within($source, $root)) {
            return new WP_Error('cortexwp_ai_component_scope', 'Component source is outside the allowed WordPress directory.', ['status' => 403]);
        }

        return $source;
    }

    private static function assert_component_restore_target(array $component) {
        $source_path = (string) ($component['source_path'] ?? '');
        $root = realpath((string) ($component['root_path'] ?? ''));
        $parent = realpath(dirname($source_path));
        $base = basename($source_path);

        if (!$root || !$parent || $base === '' || $base === '.' || $base === '..') {
            return new WP_Error('cortexwp_ai_component_target', 'Component restore target is invalid.', ['status' => 400]);
        }

        if (!self::path_is_within_or_equal($parent, $root)) {
            return new WP_Error('cortexwp_ai_component_scope', 'Component restore target is outside the allowed WordPress directory.', ['status' => 403]);
        }

        $target = trailingslashit($parent) . $base;
        if (file_exists($target)) {
            $real_target = realpath($target);
            if (!$real_target || !self::path_is_within_or_equal($real_target, $root)) {
                return new WP_Error('cortexwp_ai_component_scope', 'Component restore target is outside the allowed WordPress directory.', ['status' => 403]);
            }

            return $real_target;
        }

        return $target;
    }

    private static function component_temp_dir(string $prefix) {
        $dir = trailingslashit(WP_CONTENT_DIR) . 'cortexwp-update-archives';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('cortexwp_ai_archive_dir', 'Unable to create the component archive directory.', ['status' => 500]);
        }

        if (!is_writable($dir)) {
            return new WP_Error('cortexwp_ai_archive_dir', 'The component archive directory is not writable.', ['status' => 500]);
        }

        $tmp = trailingslashit($dir) . sanitize_file_name($prefix) . '-' . wp_generate_uuid4();
        if (!wp_mkdir_p($tmp)) {
            return new WP_Error('cortexwp_ai_archive_dir', 'Unable to create a temporary archive directory.', ['status' => 500]);
        }

        return $tmp;
    }

    private static function ensure_pclzip() {
        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        return class_exists('PclZip');
    }

    private static function create_component_zip(string $source, string $tmp_dir) {
        if (!self::ensure_pclzip()) {
            return new WP_Error('cortexwp_ai_zip_unavailable', 'WordPress PclZip is not available.', ['status' => 500]);
        }

        $archive_path = trailingslashit($tmp_dir) . 'component.zip';
        $archive = new PclZip($archive_path);
        $result = $archive->create($source, PCLZIP_OPT_REMOVE_PATH, dirname($source));

        if ($result === 0 || !is_file($archive_path)) {
            return new WP_Error('cortexwp_ai_zip_failed', 'Unable to create the component archive: ' . $archive->errorInfo(true), ['status' => 500]);
        }

        return $archive_path;
    }

    private static function upload_file_to_signed_url(string $file, string $url) {
        if (!preg_match('/^https:\/\//i', $url)) {
            return new WP_Error('cortexwp_ai_upload_url', 'Backup upload URL must use HTTPS.', ['status' => 400]);
        }

        if (!is_file($file) || !is_readable($file)) {
            return new WP_Error('cortexwp_ai_upload_file', 'Backup archive is not readable.', ['status' => 500]);
        }

        if (function_exists('curl_init')) {
            $handle = fopen($file, 'rb');
            if (!$handle) {
                return new WP_Error('cortexwp_ai_upload_file', 'Unable to open backup archive for upload.', ['status' => 500]);
            }

            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($curl, CURLOPT_UPLOAD, true);
            curl_setopt($curl, CURLOPT_INFILE, $handle);
            curl_setopt($curl, CURLOPT_INFILESIZE, filesize($file));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 600);
            $body = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            fclose($handle);

            if ($body === false || $code < 200 || $code >= 300) {
                return new WP_Error('cortexwp_ai_upload_failed', trim($error . ' HTTP ' . $code . ' ' . (string) $body), ['status' => 502]);
            }

            return true;
        }

        $body = file_get_contents($file);
        if ($body === false) {
            return new WP_Error('cortexwp_ai_upload_file', 'Unable to read backup archive for upload.', ['status' => 500]);
        }

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'timeout' => 600,
            'body' => $body,
            'headers' => [
                'Content-Length' => (string) strlen($body),
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('cortexwp_ai_upload_failed', 'R2 upload failed with HTTP ' . $code . '.', ['status' => 502]);
        }

        return true;
    }

    private static function delete_tree(string $path): bool {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }

        if (!is_dir($path)) {
            return true;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item_path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item_path);
            } else {
                @unlink($item_path);
            }
        }

        return @rmdir($path);
    }

    private static function archive_top_level_name(string $archive_path) {
        if (!self::ensure_pclzip()) {
            return new WP_Error('cortexwp_ai_zip_unavailable', 'WordPress PclZip is not available.', ['status' => 500]);
        }

        $archive = new PclZip($archive_path);
        $entries = $archive->listContent();
        if (!is_array($entries) || !$entries) {
            return new WP_Error('cortexwp_ai_zip_invalid', 'Backup archive is empty or invalid.', ['status' => 400]);
        }

        $top = [];
        foreach ($entries as $entry) {
            $raw_name = str_replace('\\', '/', (string) ($entry['filename'] ?? ''));
            if ($raw_name === '' || preg_match('#(^/|^[A-Za-z]:/|(^|/)\.\.(?:/|$))#', $raw_name)) {
                return new WP_Error('cortexwp_ai_zip_invalid', 'Backup archive contains unsafe paths.', ['status' => 400]);
            }

            $name = trim($raw_name, '/');
            if ($name === '' || $name === '.' || strpos($name, './') === 0 || strpos($name, '/./') !== false) {
                return new WP_Error('cortexwp_ai_zip_invalid', 'Backup archive contains unsafe paths.', ['status' => 400]);
            }

            $top_name = explode('/', $name)[0] ?? '';
            if ($top_name !== '') {
                $top[$top_name] = true;
            }
        }

        if (count($top) !== 1) {
            return new WP_Error('cortexwp_ai_zip_invalid', 'Backup archive must contain exactly one top-level item.', ['status' => 400]);
        }

        return (string) array_key_first($top);
    }

    private static function extract_component_zip(string $archive_path, string $tmp_dir) {
        $top = self::archive_top_level_name($archive_path);
        if (is_wp_error($top)) {
            return $top;
        }

        $extract_dir = trailingslashit($tmp_dir) . 'extract';
        if (!wp_mkdir_p($extract_dir)) {
            return new WP_Error('cortexwp_ai_extract_dir', 'Unable to create the restore extraction directory.', ['status' => 500]);
        }

        $archive = new PclZip($archive_path);
        $result = $archive->extract(PCLZIP_OPT_PATH, $extract_dir);
        if ($result === 0) {
            return new WP_Error('cortexwp_ai_extract_failed', 'Unable to extract backup archive: ' . $archive->errorInfo(true), ['status' => 500]);
        }

        $restored = trailingslashit($extract_dir) . $top;
        if (!file_exists($restored)) {
            return new WP_Error('cortexwp_ai_extract_failed', 'Backup archive did not extract the expected component.', ['status' => 500]);
        }

        return $restored;
    }

    private static function component_backup_upload(string $type, string $target, string $upload_url) {
        $component = self::component_source_from_target($type, $target);
        if (is_wp_error($component)) {
            return $component;
        }

        $source = self::assert_component_source($component);
        if (is_wp_error($source)) {
            return $source;
        }

        $tmp = self::component_temp_dir('backup');
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        try {
            $archive = self::create_component_zip($source, $tmp);
            if (is_wp_error($archive)) {
                return $archive;
            }

            $uploaded = self::upload_file_to_signed_url($archive, $upload_url);
            if (is_wp_error($uploaded)) {
                return $uploaded;
            }

            $details = [
                'archive_format' => 'zip',
                'name' => (string) $component['name'],
                'title' => (string) $component['title'],
                'plugin_file' => (string) ($component['plugin_file'] ?? ''),
                'type' => (string) $component['type'],
                'version' => (string) $component['version'],
                'source_path' => $source,
                'source_public_path' => self::public_site_path($source),
                'size_bytes' => filesize($archive) ?: 0,
                'sha256' => hash_file('sha256', $archive) ?: '',
                'runner' => 'wordpress-native',
            ];
            self::append_recovery_audit('component_backup_upload', [
                'type' => $details['type'],
                'name' => $details['name'],
                'source_path' => $details['source_public_path'],
                'size_bytes' => $details['size_bytes'],
            ]);

            return $details;
        } finally {
            self::delete_tree($tmp);
        }
    }

    private static function component_backup_restore(string $type, string $target, string $download_url, string $sha256 = '', string $source_path = '') {
        if (!preg_match('/^https:\/\//i', $download_url)) {
            return new WP_Error('cortexwp_ai_download_url', 'Backup download URL must use HTTPS.', ['status' => 400]);
        }

        $component = self::component_source_from_backup_path($type, $target, $source_path);
        if (is_wp_error($component)) {
            return $component;
        }

        $target_path = self::assert_component_restore_target($component);
        if (is_wp_error($target_path)) {
            return $target_path;
        }

        if (!is_writable(dirname($target_path)) || (file_exists($target_path) && !is_writable($target_path))) {
            return new WP_Error('cortexwp_ai_restore_writable', 'Component target is not writable.', ['status' => 500]);
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = self::component_temp_dir('restore');
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        try {
            $downloaded = download_url($download_url, 600);
            if (is_wp_error($downloaded)) {
                return $downloaded;
            }

            $archive = trailingslashit($tmp) . 'component.zip';
            if (!@rename($downloaded, $archive)) {
                @unlink($downloaded);
                return new WP_Error('cortexwp_ai_download_failed', 'Unable to store downloaded backup archive.', ['status' => 500]);
            }

            $sha256 = trim(strtolower($sha256));
            if ($sha256 !== '') {
                $actual = strtolower((string) hash_file('sha256', $archive));
                if (!hash_equals($sha256, $actual)) {
                    return new WP_Error('cortexwp_ai_checksum', 'Backup checksum mismatch.', ['status' => 400]);
                }
            }

            $restored = self::extract_component_zip($archive, $tmp);
            if (is_wp_error($restored)) {
                return $restored;
            }

            $current = trailingslashit($tmp) . 'current';
            if (file_exists($target_path) && !@rename($target_path, $current)) {
                return new WP_Error('cortexwp_ai_restore_move', 'Unable to move the current component before restore.', ['status' => 500]);
            }

            if (!@rename($restored, $target_path)) {
                if (file_exists($current)) {
                    @rename($current, $target_path);
                }

                return new WP_Error('cortexwp_ai_restore_move', 'Unable to move restored component into place.', ['status' => 500]);
            }

            if (file_exists($current)) {
                self::delete_tree($current);
            }

            $details = [
                'restored' => true,
                'archive_format' => 'zip',
                'name' => (string) $component['name'],
                'title' => (string) $component['title'],
                'type' => (string) $component['type'],
                'target_path' => $target_path,
                'target_public_path' => self::public_site_path($target_path),
                'sha256' => hash_file('sha256', $archive) ?: '',
                'runner' => 'wordpress-native',
            ];
            self::append_recovery_audit('component_backup_restore', [
                'type' => $details['type'],
                'name' => $details['name'],
                'target_path' => $details['target_public_path'],
            ]);

            return $details;
        } finally {
            self::delete_tree($tmp);
        }
    }

    private static function capabilities(): array {
        return [
            'can_write_wp_content' => is_writable(WP_CONTENT_DIR),
            'can_write_mu_plugins' => is_dir(WPMU_PLUGIN_DIR) ? is_writable(WPMU_PLUGIN_DIR) : is_writable(WP_CONTENT_DIR),
            'can_write_cortexwp_snippets' => self::ensure_snippet_dir(),
            'exec_available' => self::exec_available(),
            'native_management_available' => true,
            'management_snapshot' => true,
            'site_refresh_snapshot' => true,
            'fatal_error_monitor' => true,
            'fatal_error_endpoint' => self::fatal_error_endpoint(),
            'redirect_manager' => true,
            'redirect_rule_count' => count(self::redirect_rules()),
            'php_environment' => true,
            'safe_file_patch' => true,
            'security_site_health' => true,
            'security_snapshot' => true,
            'security_hardening' => true,
            'security_login_monitor' => true,
            'component_backups' => true,
            'updates_managed_by_cortexwp' => self::autopilot_managed(),
            'core_security_updates_allowed' => self::autopilot_managed(),
            'recovery_bridge' => self::recovery_bridge_status(),
            'wp_cli_available' => self::wp_cli_available(),
        ];
    }

    private static function redirect_rules(): array {
        $rules = get_option(self::OPTION_REDIRECT_RULES, []);

        return is_array($rules) ? $rules : [];
    }

    private static function clean_redirect_source_path(string $value): string {
        $value = trim(wp_unslash($value));
        if ($value === '') {
            return '';
        }

        $parts = wp_parse_url($value);
        if (is_array($parts) && isset($parts['path'])) {
            $path = '/' . ltrim((string) $parts['path'], '/');
            $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . (string) $parts['query'] : '';
            return $path . $query;
        }

        return '/' . ltrim(preg_replace('/[\r\n]+/', '', $value) ?: '', '/');
    }

    private static function clean_redirect_destination(string $value): string {
        $value = trim(wp_unslash($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[\r\n]+/', '', $value) ?: '';
        if (strpos($value, '/') === 0 && strpos($value, '//') !== 0) {
            return $value;
        }

        $url = esc_url_raw($value);
        if (!$url || !preg_match('/^https?:\/\//i', $url)) {
            return '';
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !self::is_valid_redirect_hostname($host)) {
            return '';
        }

        return $url;
    }

    private static function is_valid_redirect_hostname(string $host): bool {
        $host = strtolower(trim($host));
        if ($host === '' || substr($host, -1) === '.') {
            return false;
        }

        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (strpos($host, ':') !== false) {
            return true;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $label)) {
                return false;
            }
        }

        $tld = end($labels);
        return is_string($tld) && (bool) preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/i', $tld);
    }

    private static function clean_redirect_rules(array $rules): array {
        $clean = [];

        foreach (array_slice($rules, 0, 1000) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $source = self::clean_redirect_source_path((string) ($rule['sourcePath'] ?? $rule['source_path'] ?? ''));
            $destination = self::clean_redirect_destination((string) ($rule['destinationUrl'] ?? $rule['destination_url'] ?? ''));
            $status = (int) ($rule['statusCode'] ?? $rule['status_code'] ?? 301);

            if ($source === '' || $destination === '' || !in_array($status, [301, 302, 307, 308], true)) {
                continue;
            }

            $clean[] = [
                'id' => sanitize_text_field((string) ($rule['id'] ?? '')),
                'sourcePath' => $source,
                'destinationUrl' => $destination,
                'statusCode' => $status,
                'enabled' => array_key_exists('enabled', $rule) ? (bool) $rule['enabled'] : true,
                'updatedAt' => sanitize_text_field((string) ($rule['updatedAt'] ?? '')),
            ];
        }

        return array_values($clean);
    }

    private static function sync_redirect_rules(array $rules): array {
        $clean = self::clean_redirect_rules($rules);

        update_option(self::OPTION_REDIRECT_RULES, $clean, false);

        return [
            'synced' => true,
            'rules' => count($clean),
        ];
    }

    private static function current_redirect_source_path(): string {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $parts = wp_parse_url($request_uri);
        $path = is_array($parts) && isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '/';
        $query = is_array($parts) && isset($parts['query']) && $parts['query'] !== '' ? '?' . (string) $parts['query'] : '';

        return $path . $query;
    }

    private static function redirect_destination_matches_request(string $destination, string $request_path): bool {
        if (strpos($destination, '/') === 0 && strpos($destination, '//') !== 0) {
            return $destination === $request_path;
        }

        $destination_parts = wp_parse_url($destination);
        if (!is_array($destination_parts) || empty($destination_parts['path'])) {
            return false;
        }

        $path = '/' . ltrim((string) $destination_parts['path'], '/');
        $query = isset($destination_parts['query']) && $destination_parts['query'] !== '' ? '?' . (string) $destination_parts['query'] : '';

        return $path . $query === $request_path;
    }

    public static function maybe_redirect_request(): void {
        if (
            is_admin() ||
            wp_doing_ajax() ||
            wp_doing_cron() ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (defined('WP_CLI') && WP_CLI)
        ) {
            return;
        }

        $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $request_path = self::current_redirect_source_path();
        $request_path_without_query = strtok($request_path, '?') ?: $request_path;

        foreach (self::redirect_rules() as $rule) {
            if (!is_array($rule) || empty($rule['enabled'])) {
                continue;
            }

            $source = (string) ($rule['sourcePath'] ?? '');
            if ($source !== $request_path && $source !== $request_path_without_query) {
                continue;
            }

            $destination = (string) ($rule['destinationUrl'] ?? '');
            if ($destination === '' || self::redirect_destination_matches_request($destination, $request_path)) {
                continue;
            }

            header('X-Redirected-By: CortexWP');
            wp_redirect($destination, (int) ($rule['statusCode'] ?? 301), 'CortexWP');
            exit;
        }
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
                try {
                    require_once $file;
                } catch (Throwable $error) {
                    // A broken snippet must not prevent the connector from loading.
                    error_log('CortexWP snippet failed to load (' . basename($file) . '): ' . $error->getMessage());
                }
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
        $real_dir = realpath($dir);
        if (!$real_dir) {
            return [];
        }

        return array_values(array_filter($files, static function (string $file) use ($real_dir): bool {
            return is_readable($file)
                && !is_link($file)
                && self::path_is_within($file, $real_dir)
                && !in_array(strtolower(basename($file)), ['index.php'], true);
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

        $title = sanitize_text_field(trim($title)) ?: ucwords(str_replace(['-', '_'], ' ', $slug));
        $enabled = array_key_exists('enabled', $options) ? (bool) $options['enabled'] : true;
        $source = sanitize_key((string) ($options['source'] ?? 'ai')) ?: 'ai';

        if ($source === 'ai' && !self::ai_snippet_code_is_safe($code)) {
            return new WP_Error(
                'cortexwp_ai_unsafe_snippet',
                'This snippet contains unsupported file-management code.',
                ['status' => 400]
            );
        }

        $php = self::normalize_snippet_php($code);
        $php = preg_replace('/^\s*(?:\/\/|#)\s*CortexWP\s+(?:Snippet|Enabled|Source):.*\R?/mi', '', $php) ?? $php;
        $metadata = implode("\n", [
            '// CortexWP Snippet: ' . $title,
            '// CortexWP Enabled: ' . ($enabled ? 'yes' : 'no'),
            '// CortexWP Source: ' . $source,
            '',
        ]);
        $php = preg_replace_callback('/\A<\?php\s*/i', static function (array $matches) use ($metadata): string {
            return $matches[0] . $metadata;
        }, $php, 1) ?? $php;
        $path = self::snippet_path($slug);

        if (is_link($path)) {
            return new WP_Error('cortexwp_ai_snippet_symlink', 'Snippet path must not be a symbolic link.', ['status' => 400]);
        }

        if (!self::php_lint($php)) {
            return new WP_Error('cortexwp_ai_snippet_syntax', 'Snippet failed PHP syntax validation.', ['status' => 400]);
        }

        $written = file_put_contents($path, $php, LOCK_EX);

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

    private static function ai_snippet_code_is_safe(string $code): bool {
        if (!preg_match('/^\s*<\?php\b/i', $code)) {
            return false;
        }

        if (!preg_match('/defined\s*\(\s*[\'\"]ABSPATH[\'\"]\s*\).*?\bexit\s*;/is', $code)) {
            return false;
        }

        return !preg_match(
            '/\b(?:unlink|wp_delete_file|rmdir|rename|copy|file_put_contents|fopen|fwrite|chmod|chown|touch)\s*\(/i',
            $code
        );
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

        if (is_link($path)) {
            return new WP_Error('cortexwp_ai_snippet_symlink', 'Snippet path must not be a symbolic link.', ['status' => 400]);
        }

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

    private static function normalized_real_path(string $path): string {
        return rtrim(wp_normalize_path($path), '/');
    }

    private static function path_is_within(string $path, string $directory): bool {
        $real_path = realpath($path);
        $real_directory = realpath($directory);

        if (!$real_path || !$real_directory) {
            return false;
        }

        $normalized_path = self::normalized_real_path($real_path);
        $normalized_directory = trailingslashit(self::normalized_real_path($real_directory));

        return strpos($normalized_path, $normalized_directory) === 0;
    }

    private static function public_site_path(string $path): string {
        $root = realpath(ABSPATH);
        $real = realpath($path);

        if (!$root || !$real) {
            return $path;
        }

        $normalized_root = trailingslashit(self::normalized_real_path($root));
        $normalized_real = self::normalized_real_path($real);

        if (strpos($normalized_real, $normalized_root) === 0) {
            return substr($normalized_real, strlen($normalized_root));
        }

        return $normalized_real;
    }

    private static function allowed_dropin_files(): array {
        return [
            'advanced-cache.php',
            'object-cache.php',
            'db.php',
            'db-error.php',
            'install.php',
            'maintenance.php',
            'sunrise.php',
            'blog-deleted.php',
            'blog-inactive.php',
            'blog-suspended.php',
        ];
    }

    private static function is_mutable_php_file(string $file): bool {
        $real_file = realpath($file);

        if (!$real_file || !is_file($real_file) || strtolower(pathinfo($real_file, PATHINFO_EXTENSION)) !== 'php') {
            return false;
        }

        $allowed_directories = array_filter([
            defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
            function_exists('get_theme_root') ? get_theme_root() : trailingslashit(WP_CONTENT_DIR) . 'themes',
            defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : trailingslashit(WP_CONTENT_DIR) . 'mu-plugins',
        ]);

        foreach ($allowed_directories as $directory) {
            if (is_dir($directory) && self::path_is_within($real_file, $directory)) {
                return true;
            }
        }

        $content_dir = realpath(WP_CONTENT_DIR);
        if ($content_dir && dirname($real_file) === $content_dir) {
            return in_array(strtolower(basename($real_file)), self::allowed_dropin_files(), true);
        }

        return false;
    }

    private static function recovery_backup_dir(): string {
        return trailingslashit(WP_CONTENT_DIR) . self::RECOVERY_BACKUP_DIR;
    }

    private static function ensure_recovery_backup_dir(): bool {
        $dir = self::recovery_backup_dir();

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        if (!is_writable($dir)) {
            return false;
        }

        self::write_snippet_directory_protection_files($dir);

        return true;
    }

    private static function backup_file_before_patch(string $file, string $reason = 'patch') {
        if (!self::ensure_recovery_backup_dir()) {
            return new WP_Error('cortexwp_ai_backup_dir', 'CortexWP recovery backup directory is not writable.', ['status' => 500]);
        }

        $hash = substr(hash('sha256', $file . '|' . microtime(true)), 0, 12);
        $backup_name = gmdate('Ymd-His') . '-' . $hash . '-' . sanitize_file_name(basename($file));
        $backup_path = trailingslashit(self::recovery_backup_dir()) . $backup_name;

        if (!copy($file, $backup_path)) {
            return new WP_Error('cortexwp_ai_backup_failed', 'Unable to create a backup before patching.', ['status' => 500]);
        }

        file_put_contents($backup_path . '.json', wp_json_encode([
            'target_path' => $file,
            'target_public_path' => self::public_site_path($file),
            'backup_path' => $backup_path,
            'created_at' => gmdate('c'),
            'reason' => $reason,
        ], JSON_PRETTY_PRINT));

        return $backup_path;
    }

    private static function replace_limited(string $contents, string $search, string $replace, int $limit): string {
        $result = '';
        $offset = 0;
        $replacements = 0;
        $search_length = strlen($search);

        while ($replacements < $limit) {
            $position = strpos($contents, $search, $offset);
            if ($position === false) {
                break;
            }

            $result .= substr($contents, $offset, $position - $offset) . $replace;
            $offset = $position + $search_length;
            $replacements++;
        }

        return $result . substr($contents, $offset);
    }

    private static function patch_file(string $path, string $search, string $replace, int $max_replacements) {
        $file = self::safe_path($path);

        if (!$file || !is_file($file) || !is_readable($file) || !is_writable($file)) {
            return new WP_Error('cortexwp_ai_patch_unavailable', 'File not found, not readable, or not writable.', ['status' => 404]);
        }

        if (!self::is_mutable_php_file($file)) {
            return new WP_Error('cortexwp_ai_patch_denied', 'Only PHP files inside wp-content plugins, themes, mu-plugins, and known drop-ins can be patched through connector recovery.', ['status' => 403]);
        }

        if ($search === '') {
            return new WP_Error('cortexwp_ai_patch_search_required', 'Exact search text is required.', ['status' => 400]);
        }

        $contents = (string) file_get_contents($file);
        $matches = substr_count($contents, $search);

        if ($matches === 0) {
            return new WP_Error('cortexwp_ai_patch_no_match', 'The exact search text was not found in the target file.', ['status' => 400]);
        }

        $limit = max(1, min(20, $max_replacements));
        if ($matches > $limit) {
            return new WP_Error('cortexwp_ai_patch_too_many_matches', 'The exact search text matched more times than allowed. Use a more specific search string.', ['status' => 400]);
        }

        $backup = self::backup_file_before_patch($file, 'patch_file');
        if (is_wp_error($backup)) {
            return $backup;
        }

        $updated = self::replace_limited($contents, $search, $replace, $limit);
        $written = file_put_contents($file, $updated, LOCK_EX);

        if ($written === false) {
            return new WP_Error('cortexwp_ai_patch_write_failed', 'Unable to write the patched file.', ['status' => 500]);
        }

        self::append_recovery_audit('patch_file', [
            'path' => self::public_site_path($file),
            'backup_path' => self::public_site_path($backup),
            'replacements' => $matches,
            'runner' => 'wordpress-rest',
        ], 'succeeded');

        return [
            'patched' => true,
            'path' => $file,
            'public_path' => self::public_site_path($file),
            'backup_path' => $backup,
            'backup_public_path' => self::public_site_path($backup),
            'replacements' => $matches,
            'syntax_validation' => self::exec_available() ? 'available_for_snippets_only' : 'not_available_without_process_execution',
        ];
    }

    private static function restore_file_backup(string $backup_path, string $target_path = '') {
        $backup = self::safe_path($backup_path);
        $backup_dir = realpath(self::recovery_backup_dir());

        if (!$backup || !$backup_dir || !self::path_is_within($backup, $backup_dir) || !is_file($backup) || !is_readable($backup)) {
            return new WP_Error('cortexwp_ai_backup_not_found', 'Backup file was not found or is not readable.', ['status' => 404]);
        }

        $metadata_path = $backup . '.json';
        $metadata = is_readable($metadata_path) ? json_decode((string) file_get_contents($metadata_path), true) : [];
        $target = $target_path !== ''
            ? self::safe_path($target_path)
            : (is_array($metadata) ? self::safe_path((string) ($metadata['target_path'] ?? '')) : '');

        if ($target === '') {
            $target = is_array($metadata) ? self::safe_path((string) ($metadata['target_public_path'] ?? '')) : '';
        }

        if (!$target || !is_file($target) || !is_writable($target)) {
            return new WP_Error('cortexwp_ai_restore_target', 'Restore target was not found or is not writable.', ['status' => 404]);
        }

        if (!self::is_mutable_php_file($target)) {
            return new WP_Error('cortexwp_ai_restore_denied', 'Only PHP files inside wp-content plugins, themes, mu-plugins, and known drop-ins can be restored through connector recovery.', ['status' => 403]);
        }

        $current_backup = self::backup_file_before_patch($target, 'pre_restore');
        if (is_wp_error($current_backup)) {
            return $current_backup;
        }

        if (!copy($backup, $target)) {
            return new WP_Error('cortexwp_ai_restore_failed', 'Unable to restore the backup file.', ['status' => 500]);
        }

        self::append_recovery_audit('restore_file_backup', [
            'target_path' => self::public_site_path($target),
            'backup_path' => self::public_site_path($backup),
            'pre_restore_backup_path' => self::public_site_path($current_backup),
            'runner' => 'wordpress-rest',
        ], 'succeeded');

        return [
            'restored' => true,
            'target_path' => $target,
            'target_public_path' => self::public_site_path($target),
            'backup_path' => $backup,
            'pre_restore_backup_path' => $current_backup,
        ];
    }

    private static function php_environment(): array {
        $disabled = array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))));
        $extensions = get_loaded_extensions();
        sort($extensions);

        return [
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os' => PHP_OS_FAMILY,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'disabled_functions' => $disabled,
                'extensions' => $extensions,
            ],
            'wordpress' => [
                'version' => get_bloginfo('version'),
                'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : '',
                'debug' => defined('WP_DEBUG') ? (bool) WP_DEBUG : false,
                'script_debug' => defined('SCRIPT_DEBUG') ? (bool) SCRIPT_DEBUG : false,
                'site_url' => site_url(),
                'home_url' => home_url(),
            ],
            'paths' => [
                'ABSPATH' => ABSPATH,
                'WP_CONTENT_DIR' => WP_CONTENT_DIR,
                'WP_PLUGIN_DIR' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
                'WPMU_PLUGIN_DIR' => defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : '',
                'theme_root' => function_exists('get_theme_root') ? get_theme_root() : '',
                'temp_dir' => function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir(),
            ],
            'writable' => [
                'root' => is_writable(ABSPATH),
                'wp_content' => is_writable(WP_CONTENT_DIR),
                'plugins' => defined('WP_PLUGIN_DIR') && is_writable(WP_PLUGIN_DIR),
                'themes' => function_exists('get_theme_root') && is_writable(get_theme_root()),
                'mu_plugins' => defined('WPMU_PLUGIN_DIR') ? (is_dir(WPMU_PLUGIN_DIR) ? is_writable(WPMU_PLUGIN_DIR) : is_writable(WP_CONTENT_DIR)) : false,
                'recovery_backups' => is_dir(self::recovery_backup_dir()) ? is_writable(self::recovery_backup_dir()) : is_writable(WP_CONTENT_DIR),
            ],
            'capabilities' => [
                'exec_available' => self::exec_available(),
                'wp_cli_available' => self::wp_cli_available(),
                'native_management_available' => true,
                'safe_file_patch' => true,
                'recovery_bridge' => self::recovery_bridge_status(),
            ],
        ];
    }

    private static function recovery_secret(): string {
        $key = self::connector_key();

        return $key === '' ? '' : hash_hmac('sha256', 'cortexwp-recovery-bridge-secret-v1', $key);
    }

    private static function recovery_bridge_file_name(?string $key = null): string {
        $connector_key = $key ?? self::connector_key();
        if ($connector_key === '') {
            return self::RECOVERY_BRIDGE_PREFIX . 'unavailable.php';
        }

        $suffix = substr(hash_hmac('sha256', 'cortexwp-recovery-bridge-path-v1', $connector_key), 0, 16);

        return self::RECOVERY_BRIDGE_PREFIX . $suffix . '.php';
    }

    private static function recovery_bridge_path(): string {
        return trailingslashit(ABSPATH) . self::recovery_bridge_file_name();
    }

    private static function recovery_bridge_url(): string {
        return trailingslashit(site_url()) . self::recovery_bridge_file_name();
    }

    private static function has_any_recovery_bridge(): bool {
        return (bool) glob(trailingslashit(ABSPATH) . self::RECOVERY_BRIDGE_PREFIX . '*.php');
    }

    private static function remove_stale_recovery_bridges(): void {
        $current = self::normalized_real_path(self::recovery_bridge_path());

        foreach (glob(trailingslashit(ABSPATH) . self::RECOVERY_BRIDGE_PREFIX . '*.php') ?: [] as $file) {
            if (self::normalized_real_path($file) !== $current && is_file($file) && is_writable($file)) {
                unlink($file);
            }
        }
    }

    private static function recovery_audit_dir(): string {
        return trailingslashit(WP_CONTENT_DIR) . self::RECOVERY_AUDIT_DIR;
    }

    private static function ensure_recovery_audit_dir(): bool {
        $dir = self::recovery_audit_dir();

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        if (!is_writable($dir)) {
            return false;
        }

        self::write_snippet_directory_protection_files($dir);

        return true;
    }

    private static function append_recovery_audit(string $action, array $details = [], string $status = 'succeeded'): void {
        if (!self::ensure_recovery_audit_dir()) {
            return;
        }

        $entry = [
            'time' => gmdate('c'),
            'action' => sanitize_key($action),
            'status' => $status,
            'details' => $details,
        ];
        $path = trailingslashit(self::recovery_audit_dir()) . gmdate('Y-m-d') . '.log';

        file_put_contents($path, wp_json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function recovery_audit_log(int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        $dir = self::recovery_audit_dir();

        if (!is_dir($dir)) {
            return ['entries' => []];
        }

        $files = glob(trailingslashit($dir) . '*.log') ?: [];
        rsort($files);
        $entries = [];

        foreach ($files as $file) {
            $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            foreach (array_reverse($lines ?: []) as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $entries[] = $decoded;
                }

                if (count($entries) >= $limit) {
                    break 2;
                }
            }
        }

        return ['entries' => $entries];
    }

    private static function recovery_bridge_status(): array {
        $path = self::recovery_bridge_path();

        return [
            'installed' => is_file($path) && is_readable($path),
            'root_writable' => is_writable(ABSPATH),
            'modified' => is_file($path) ? gmdate('c', filemtime($path) ?: time()) : null,
            'audit_entries' => self::recovery_audit_log(5)['entries'],
        ];
    }

    private static function ensure_recovery_bridge(bool $force = false): array {
        if (self::connector_key() === '') {
            return array_merge(self::recovery_bridge_status(), [
                'installed' => false,
                'error' => 'The connector key could not be decrypted yet, so the recovery bridge was not installed.',
            ]);
        }

        $path = self::recovery_bridge_path();

        if (!$force) {
            if (is_file($path) && is_readable($path)) {
                return self::recovery_bridge_status();
            }
        }

        if (!is_writable(ABSPATH) && !(is_file($path) && is_writable($path))) {
            return array_merge(self::recovery_bridge_status(), [
                'installed' => false,
                'error' => 'WordPress root is not writable, so the recovery bridge could not be installed.',
            ]);
        }

        $written = file_put_contents($path, self::recovery_bridge_php(), LOCK_EX);

        if ($written === false) {
            return array_merge(self::recovery_bridge_status(), [
                'installed' => false,
                'error' => 'Unable to write the CortexWP recovery bridge file.',
            ]);
        }

        self::remove_stale_recovery_bridges();
        self::append_recovery_audit('recovery_bridge_install', [
            'path' => self::public_site_path($path),
            'access' => 'signed_post_only',
        ], 'succeeded');

        return self::recovery_bridge_status();
    }

    private static function recovery_bridge_php(): string {
        $secret_export = var_export(self::recovery_secret(), true);
        $version_export = var_export(self::plugin_version(), true);
        $bridge_file_export = var_export(self::recovery_bridge_file_name(), true);

        $template = <<<'PHP'
<?php
declare(strict_types=1);

define('CORTEXWP_RECOVERY_SECRET', %%SECRET%%);
define('CORTEXWP_RECOVERY_VERSION', %%VERSION%%);
define('CORTEXWP_RECOVERY_FILE', %%BRIDGE_FILE%%);
define('CORTEXWP_RECOVERY_ROOT', __DIR__);
define('CORTEXWP_RECOVERY_MAX_BODY_BYTES', 262144);
define('CORTEXWP_RECOVERY_MAX_OUTPUT_BYTES', 500000);

function cwp_not_found(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo 'Not Found';
    exit;
}

function cwp_response($payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

function cwp_fail(string $message, int $status = 400): void {
    cwp_response(['error' => $message], $status);
}

function cwp_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : '';
}

function cwp_has_recovery_headers(): bool {
    return cwp_header('x-cortexwp-timestamp') !== ''
        && cwp_header('x-cortexwp-nonce') !== ''
        && cwp_header('x-cortexwp-signature') !== '';
}

if (
    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST'
    || !cwp_has_recovery_headers()
    || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > CORTEXWP_RECOVERY_MAX_BODY_BYTES
) {
    cwp_not_found();
}

function cwp_protect_dir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }

    $files = [
        '.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
        'index.php' => "<?php\n// Silence is good\n",
        'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n",
    ];

    foreach ($files as $name => $contents) {
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || is_writable($path)) {
            @file_put_contents($path, $contents, LOCK_EX);
        }
    }
}

function cwp_dir(string $name): string {
    return CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . $name;
}

function cwp_ensure_dir(string $name): string {
    $dir = cwp_dir($name);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        cwp_fail('Recovery storage directory is not writable.', 500);
    }

    if (!is_writable($dir)) {
        cwp_fail('Recovery storage directory is not writable.', 500);
    }

    cwp_protect_dir($dir);
    return $dir;
}

function cwp_audit(string $action, array $details = [], string $status = 'succeeded'): void {
    $dir = cwp_ensure_dir('cortexwp-recovery-audit');
    $entry = [
        'time' => gmdate('c'),
        'action' => preg_replace('/[^a-z0-9_]/i', '', $action),
        'status' => $status,
        'request_id' => cwp_header('x-cortexwp-nonce') ? substr(hash('sha256', cwp_header('x-cortexwp-nonce')), 0, 16) : null,
        'ip_hash' => isset($_SERVER['REMOTE_ADDR']) ? substr(hash_hmac('sha256', (string) $_SERVER['REMOTE_ADDR'], CORTEXWP_RECOVERY_SECRET), 0, 16) : null,
        'details' => $details,
    ];

    @file_put_contents(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . gmdate('Y-m-d') . '.log', json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}

function cwp_audit_log(int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    $dir = cwp_dir('cortexwp-recovery-audit');

    if (!is_dir($dir)) {
        return ['entries' => []];
    }

    $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.log') ?: [];
    rsort($files);
    $entries = [];

    foreach ($files as $file) {
        $lines = is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        foreach (array_reverse($lines ?: []) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }

            if (count($entries) >= $limit) {
                break 2;
            }
        }
    }

    return ['entries' => $entries];
}

function cwp_verify(string $body): void {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        cwp_not_found();
    }

    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? strlen($body)) > CORTEXWP_RECOVERY_MAX_BODY_BYTES) {
        cwp_not_found();
    }

    $timestamp = cwp_header('x-cortexwp-timestamp');
    $nonce = cwp_header('x-cortexwp-nonce');
    $provided = cwp_header('x-cortexwp-signature');

    if ($timestamp === '' || $nonce === '' || $provided === '' || abs(time() - (int) $timestamp) > 300) {
        cwp_not_found();
    }

    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $path = $path ?: '/' . CORTEXWP_RECOVERY_FILE;
    $expected = hash_hmac('sha256', implode("\n", [
        'POST',
        $path,
        hash('sha256', $body),
        $timestamp,
        $nonce,
    ]), CORTEXWP_RECOVERY_SECRET);

    if (!hash_equals($expected, $provided)) {
        cwp_audit('auth_failed', ['reason' => 'signature'], 'failed');
        cwp_not_found();
    }

    $nonce_dir = cwp_ensure_dir('cortexwp-recovery-nonces');
    foreach (glob(rtrim($nonce_dir, '/\\') . DIRECTORY_SEPARATOR . '*.nonce') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 600) {
            @unlink($file);
        }
    }

    $nonce_file = rtrim($nonce_dir, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $nonce) . '.nonce';
    if (is_file($nonce_file)) {
        cwp_audit('auth_failed', ['reason' => 'replay'], 'failed');
        cwp_fail('Replay detected.', 403);
    }

    if (@file_put_contents($nonce_file, gmdate('c'), LOCK_EX) === false) {
        cwp_fail('Recovery nonce could not be stored.', 500);
    }
}

function cwp_norm(string $path): string {
    return rtrim(str_replace('\\', '/', $path), '/');
}

function cwp_safe_name(string $name): string {
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    return trim((string) $name, '.-') ?: 'file.php';
}

function cwp_safe_path(string $path): string {
    $path = trim($path);
    $path = $path === '' ? CORTEXWP_RECOVERY_ROOT : $path;
    $absolute = preg_match('/^[A-Za-z]:[\/\\\\]/', $path) || strpos($path, '/') === 0 || strpos($path, '\\') === 0;
    $candidate = $absolute ? $path : CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    $real = realpath($candidate);
    $root = realpath(CORTEXWP_RECOVERY_ROOT);

    if (!$real || !$root) {
        return '';
    }

    $normalized_real = cwp_norm($real);
    $normalized_root = cwp_norm($root);
    $root_prefix = rtrim($normalized_root, '/') . '/';

    if ($normalized_real !== $normalized_root && strpos($normalized_real, $root_prefix) !== 0) {
        return '';
    }

    return $real;
}

function cwp_within(string $path, string $directory): bool {
    $real_path = realpath($path);
    $real_directory = realpath($directory);

    if (!$real_path || !$real_directory) {
        return false;
    }

    return strpos(cwp_norm($real_path), rtrim(cwp_norm($real_directory), '/') . '/') === 0;
}

function cwp_public_path(string $path): string {
    $real = realpath($path);
    $root = realpath(CORTEXWP_RECOVERY_ROOT);

    if (!$real || !$root) {
        return $path;
    }

    $normalized_real = cwp_norm($real);
    $normalized_root = rtrim(cwp_norm($root), '/') . '/';

    if (strpos($normalized_real, $normalized_root) === 0) {
        return substr($normalized_real, strlen($normalized_root));
    }

    return $normalized_real;
}

function cwp_allowed_dropins(): array {
    return [
        'advanced-cache.php',
        'object-cache.php',
        'db.php',
        'db-error.php',
        'install.php',
        'maintenance.php',
        'sunrise.php',
        'blog-deleted.php',
        'blog-inactive.php',
        'blog-suspended.php',
    ];
}

function cwp_is_recoverable_php_file(string $file): bool {
    $real = realpath($file);

    if (!$real || !is_file($real) || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }

    $content = CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content';
    foreach (['plugins', 'themes', 'mu-plugins'] as $name) {
        $dir = $content . DIRECTORY_SEPARATOR . $name;
        if (is_dir($dir) && cwp_within($real, $dir)) {
            return true;
        }
    }

    $real_content = realpath($content);
    if ($real_content && dirname($real) === $real_content) {
        return in_array(strtolower(basename($real)), cwp_allowed_dropins(), true);
    }

    return false;
}

function cwp_is_listable_directory(string $dir): bool {
    $real = realpath($dir);
    if (!$real || !is_dir($real)) {
        return false;
    }

    $content = realpath(CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content');
    if ($content && cwp_norm($real) === cwp_norm($content)) {
        return true;
    }

    foreach (['plugins', 'themes', 'mu-plugins'] as $name) {
        $allowed = CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . $name;
        if (is_dir($allowed) && (cwp_norm($real) === cwp_norm(realpath($allowed) ?: '') || cwp_within($real, $allowed))) {
            return true;
        }
    }

    return false;
}

function cwp_is_readable_recovery_file(string $file): bool {
    if (cwp_is_recoverable_php_file($file)) {
        return true;
    }

    $debug_log = CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'debug.log';
    return realpath($file) && realpath($debug_log) && cwp_norm(realpath($file)) === cwp_norm(realpath($debug_log));
}

function cwp_backup_dir(): string {
    return cwp_ensure_dir('cortexwp-recovery-backups');
}

function cwp_backup_file(string $file, string $reason): string {
    $dir = cwp_backup_dir();
    $backup = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . gmdate('Ymd-His') . '-' . substr(hash('sha256', $file . '|' . microtime(true)), 0, 12) . '-' . cwp_safe_name(basename($file));

    if (!@copy($file, $backup)) {
        cwp_fail('Unable to create a backup before writing.', 500);
    }

    @file_put_contents($backup . '.json', json_encode([
        'target_path' => $file,
        'target_public_path' => cwp_public_path($file),
        'backup_path' => $backup,
        'created_at' => gmdate('c'),
        'reason' => $reason,
    ], JSON_PRETTY_PRINT), LOCK_EX);

    return $backup;
}

function cwp_replace_limited(string $contents, string $search, string $replace, int $limit): string {
    $result = '';
    $offset = 0;
    $count = 0;
    $length = strlen($search);

    while ($count < $limit) {
        $position = strpos($contents, $search, $offset);
        if ($position === false) {
            break;
        }

        $result .= substr($contents, $offset, $position - $offset) . $replace;
        $offset = $position + $length;
        $count++;
    }

    return $result . substr($contents, $offset);
}

function cwp_read_file(array $input): array {
    $file = cwp_safe_path((string) ($input['path'] ?? ''));
    $lines = max(1, min(1000, (int) ($input['lines'] ?? 160)));
    $max_bytes = max(1024, min(CORTEXWP_RECOVERY_MAX_OUTPUT_BYTES, (int) ($input['max_output_bytes'] ?? CORTEXWP_RECOVERY_MAX_OUTPUT_BYTES)));

    if (!$file || !is_file($file) || !is_readable($file) || !cwp_is_readable_recovery_file($file)) {
        return ['path' => (string) ($input['path'] ?? ''), 'found' => false, 'stdout' => '', 'stderr' => 'File not found, not readable, or outside recovery scope.', 'exit_code' => 1];
    }

    $contents = file($file, FILE_IGNORE_NEW_LINES);
    $tail = array_slice($contents ?: [], -$lines);
    cwp_audit('read_file', ['path' => cwp_public_path($file), 'lines' => count($tail)], 'succeeded');

    return [
        'path' => $file,
        'public_path' => cwp_public_path($file),
        'found' => true,
        'lines' => count($tail),
        'stdout' => substr(implode("\n", $tail), 0, $max_bytes),
        'stderr' => '',
        'exit_code' => 0,
        'runner' => 'cortexwp-recovery-bridge',
    ];
}

function cwp_list_directory(array $input): array {
    $dir = cwp_safe_path((string) ($input['path'] ?? (CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content')));

    if (!$dir || !is_dir($dir) || !is_readable($dir) || !cwp_is_listable_directory($dir)) {
        return ['path' => (string) ($input['path'] ?? ''), 'entries' => [], 'stderr' => 'Directory not found, not readable, or outside recovery scope.', 'exit_code' => 1];
    }

    $entries = [];
    foreach (array_slice(scandir($dir) ?: [], 0, 300) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full = $dir . DIRECTORY_SEPARATOR . $entry;
        $entries[] = sprintf('%s %s %s', is_dir($full) ? 'd' : '-', filesize($full) ?: 0, $full);
    }

    cwp_audit('list_directory', ['path' => cwp_public_path($dir), 'entries' => count($entries)], 'succeeded');
    return ['path' => $dir, 'public_path' => cwp_public_path($dir), 'entries' => $entries, 'stderr' => '', 'exit_code' => 0, 'runner' => 'cortexwp-recovery-bridge'];
}

function cwp_patch_file(array $input): array {
    $file = cwp_safe_path((string) ($input['path'] ?? ''));
    $search = (string) ($input['search'] ?? '');
    $replace = (string) ($input['replace'] ?? '');

    if (!$file || !is_file($file) || !is_readable($file) || !is_writable($file)) {
        cwp_fail('File not found, not readable, or not writable.', 404);
    }

    if (!cwp_is_recoverable_php_file($file)) {
        cwp_fail('Only PHP files inside wp-content plugins, themes, mu-plugins, and known drop-ins can be patched through connector recovery.', 403);
    }

    if ($search === '' || strlen($search) > 100000 || strlen($replace) > 100000) {
        cwp_fail('Exact search and replacement text are required and must be reasonably sized.', 400);
    }

    $contents = (string) file_get_contents($file);
    $matches = substr_count($contents, $search);
    if ($matches === 0) {
        cwp_fail('The exact search text was not found in the target file.', 400);
    }

    $limit = max(1, min(20, (int) ($input['max_replacements'] ?? 1)));
    if ($matches > $limit) {
        cwp_fail('The exact search text matched more times than allowed. Use a more specific search string.', 400);
    }

    $backup = cwp_backup_file($file, 'patch_file');
    $updated = cwp_replace_limited($contents, $search, $replace, $limit);

    if (@file_put_contents($file, $updated, LOCK_EX) === false) {
        cwp_fail('Unable to write the patched file.', 500);
    }

    $details = [
        'path' => cwp_public_path($file),
        'backup_path' => cwp_public_path($backup),
        'replacements' => $matches,
        'runner' => 'cortexwp-recovery-bridge',
    ];
    cwp_audit('patch_file', $details, 'succeeded');

    return array_merge(['patched' => true], $details);
}

function cwp_restore_file_backup(array $input): array {
    $backup = cwp_safe_path((string) ($input['backup_path'] ?? ''));
    $backup_dir = realpath(cwp_dir('cortexwp-recovery-backups'));

    if (!$backup || !$backup_dir || !cwp_within($backup, $backup_dir) || !is_file($backup) || !is_readable($backup)) {
        cwp_fail('Backup file was not found or is not readable.', 404);
    }

    $metadata = [];
    if (is_readable($backup . '.json')) {
        $decoded = json_decode((string) file_get_contents($backup . '.json'), true);
        $metadata = is_array($decoded) ? $decoded : [];
    }

    $target = trim((string) ($input['target_path'] ?? ''));
    $target = $target !== '' ? cwp_safe_path($target) : cwp_safe_path((string) ($metadata['target_path'] ?? ''));

    if (!$target || !is_file($target) || !is_writable($target) || !cwp_is_recoverable_php_file($target)) {
        cwp_fail('Restore target was not found, not writable, or outside recovery scope.', 404);
    }

    $pre_restore_backup = cwp_backup_file($target, 'pre_restore');
    if (!@copy($backup, $target)) {
        cwp_fail('Unable to restore the backup file.', 500);
    }

    $details = [
        'target_path' => cwp_public_path($target),
        'backup_path' => cwp_public_path($backup),
        'pre_restore_backup_path' => cwp_public_path($pre_restore_backup),
        'runner' => 'cortexwp-recovery-bridge',
    ];
    cwp_audit('restore_file_backup', $details, 'succeeded');

    return array_merge(['restored' => true], $details);
}

function cwp_environment(): array {
    $disabled = array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))));

    return [
        'recovery_bridge' => [
            'version' => CORTEXWP_RECOVERY_VERSION,
            'file' => CORTEXWP_RECOVERY_FILE,
            'root' => CORTEXWP_RECOVERY_ROOT,
            'runner' => 'cortexwp-recovery-bridge',
        ],
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'disabled_functions' => $disabled,
        ],
        'writable' => [
            'wp_content' => is_writable(CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content'),
            'plugins' => is_writable(CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins'),
            'themes' => is_writable(CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'themes'),
            'backup_dir' => is_dir(cwp_dir('cortexwp-recovery-backups')) ? is_writable(cwp_dir('cortexwp-recovery-backups')) : is_writable(CORTEXWP_RECOVERY_ROOT . DIRECTORY_SEPARATOR . 'wp-content'),
        ],
        'scope' => [
            'read' => 'wp-content plugin/theme/mu-plugin/drop-in PHP files and wp-content/debug.log only',
            'write' => 'exact search/replace on recoverable PHP files only',
            'shell' => false,
            'database' => false,
            'eval' => false,
        ],
    ];
}

$body = (string) file_get_contents('php://input');
cwp_verify($body);

$input = json_decode($body, true);
if (!is_array($input)) {
    cwp_fail('Invalid JSON body.', 400);
}

$action = preg_replace('/[^a-z0-9_]/i', '', (string) ($input['action'] ?? ''));

try {
    switch ($action) {
        case 'status':
        case 'php_environment':
            cwp_response(cwp_environment());
            break;
        case 'read_file':
            cwp_response(cwp_read_file($input));
            break;
        case 'list_directory':
            cwp_response(cwp_list_directory($input));
            break;
        case 'patch_file':
            cwp_response(cwp_patch_file($input));
            break;
        case 'restore_file_backup':
            cwp_response(cwp_restore_file_backup($input));
            break;
        case 'recovery_audit_log':
            cwp_response(cwp_audit_log((int) ($input['limit'] ?? 50)));
            break;
        default:
            cwp_fail('Unknown CortexWP recovery action.', 400);
    }
} catch (Throwable $error) {
    cwp_audit($action ?: 'unknown', ['error' => $error->getMessage()], 'failed');
    cwp_fail($error->getMessage(), 500);
}
PHP;

        return str_replace(
            ['%%SECRET%%', '%%VERSION%%', '%%BRIDGE_FILE%%'],
            [$secret_export, $version_export, $bridge_file_export],
            $template
        );
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

        if (strpos($stored, 'v1:') === 0) {
            return '';
        }

        self::store_key($stored);
        return $stored;
    }

    private static function key_has_been_viewed(): bool {
        return (bool) get_option(self::OPTION_KEY_VIEWED, false);
    }

    private static function mark_key_viewed_once(): bool {
        global $wpdb;

        add_option(self::OPTION_KEY_VIEWED, false, '', false);

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
            '1',
            self::OPTION_KEY_VIEWED
        ));

        return $updated === 1;
    }

    private static function connector_key_prefix(string $key): string {
        if ($key === '') {
            return 'Unavailable';
        }

        return self::truncate_text($key, 12) . '••••••••••••••••••••';
    }

    private static function store_key(string $key): void {
        update_option(self::OPTION_KEY, self::encrypt_secret($key), false);
    }

    private static function salt_constant(string $name): string {
        if (!defined($name)) {
            return '';
        }

        $value = (string) constant($name);

        return trim($value) === 'put your unique phrase here' ? '' : $value;
    }

    private static function fallback_salt(string $scheme): string {
        $constants = [
            'auth' => ['AUTH_KEY', 'AUTH_SALT'],
            'secure_auth' => ['SECURE_AUTH_KEY', 'SECURE_AUTH_SALT'],
        ][$scheme] ?? ['AUTH_KEY', 'AUTH_SALT'];
        $material = '';

        foreach ($constants as $constant) {
            $material .= self::salt_constant($constant);
        }

        if ($material === '') {
            $material = self::salt_constant('SECRET_KEY') . self::salt_constant('SECRET_SALT');
        }

        return $material;
    }

    private static function encryption_key_materials(): array {
        $materials = [];

        if (function_exists('wp_salt')) {
            $materials[] = wp_salt('auth') . wp_salt('secure_auth');
        }

        $fallback = self::fallback_salt('auth') . self::fallback_salt('secure_auth');
        if ($fallback !== '') {
            $materials[] = $fallback;
        }

        return array_values(array_unique(array_filter($materials)));
    }

    private static function encryption_key(?string $material = null): string {
        if ($material === null) {
            $materials = self::encryption_key_materials();
            $material = $materials[0] ?? (defined('ABSPATH') ? ABSPATH : __FILE__);
        }

        return hash('sha256', $material, true);
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
        foreach (self::encryption_key_materials() as $material) {
            $decrypted = openssl_decrypt(
                base64_decode($ciphertext, true) ?: '',
                'aes-256-gcm',
                self::encryption_key($material),
                OPENSSL_RAW_DATA,
                base64_decode($iv, true) ?: '',
                base64_decode($tag, true) ?: ''
            );

            if ($decrypted !== false) {
                return $decrypted;
            }
        }

        return null;
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
                'title' => $plugin['title'],
                'plugin_file' => $plugin['plugin_file'],
                'kind' => $kind,
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
        $active_template = get_template();
        $items = [];

        foreach (wp_get_themes() as $slug => $theme) {
            $update = isset($updates[$slug]) ? $updates[$slug] : null;
            $items[] = [
                'name' => (string) $slug,
                'title' => (string) $theme->get('Name'),
                'stylesheet' => (string) $theme->get_stylesheet(),
                'template' => (string) $theme->get_template(),
                'status' => (string) $slug === $active ? 'active' : 'inactive',
                'is_active_parent' => (string) $slug === $active_template,
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

    private static function native_json_stdout(array $result): array {
        $decoded = json_decode((string) ($result['stdout'] ?? '[]'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function management_plugin_item(array $row): array {
        $kind = (string) ($row['kind'] ?? 'plugin');
        $status = (string) ($row['status'] ?? 'unknown');
        $update_version = trim((string) ($row['update_version'] ?? ''));
        $update = (string) ($row['update'] ?? 'none');
        $is_must_use = $kind === 'mu-plugin' || $status === 'must-use';
        $is_dropin = $kind === 'dropin' || $status === 'dropin';

        return [
            'name' => (string) ($row['name'] ?? ''),
            'title' => (string) ($row['title'] ?? $row['name'] ?? ''),
            'pluginFile' => (string) ($row['plugin_file'] ?? ''),
            'kind' => in_array($kind, ['plugin', 'mu-plugin', 'dropin'], true) ? $kind : 'plugin',
            'status' => $status,
            'version' => (string) (($row['version'] ?? '') ?: 'unknown'),
            'update' => $update,
            'updateVersion' => $update_version !== '' ? $update_version : null,
            'autoUpdate' => (string) ($row['auto_update'] ?? 'off'),
            'needsUpdate' => strtolower($update) === 'available' || $update_version !== '',
            'isActive' => $status === 'active' || $status === 'active-network' || $is_must_use,
            'isMustUse' => $is_must_use,
            'isDropIn' => $is_dropin,
            'isActualPlugin' => !$is_must_use && !$is_dropin,
        ];
    }

    private static function management_theme_item(array $row): array {
        $update_version = trim((string) ($row['update_version'] ?? ''));
        $update = (string) ($row['update'] ?? 'none');
        $status = (string) ($row['status'] ?? 'unknown');

        return [
            'name' => (string) ($row['name'] ?? ''),
            'title' => (string) ($row['title'] ?? $row['name'] ?? ''),
            'stylesheet' => (string) ($row['stylesheet'] ?? $row['name'] ?? ''),
            'template' => (string) ($row['template'] ?? ''),
            'status' => $status,
            'version' => (string) (($row['version'] ?? '') ?: 'unknown'),
            'update' => $update,
            'updateVersion' => $update_version !== '' ? $update_version : null,
            'autoUpdate' => (string) ($row['auto_update'] ?? 'off'),
            'needsUpdate' => strtolower($update) === 'available' || $update_version !== '',
            'isActive' => $status === 'active',
            'isActiveParent' => !empty($row['is_active_parent']),
        ];
    }

    private static function management_counts(array $plugins, array $themes, bool $core_has_update): array {
        $plugin_active = 0;
        $plugin_updates = 0;
        $plugin_auto_updates = 0;
        $theme_active = 0;
        $theme_updates = 0;
        $theme_auto_updates = 0;

        foreach ($plugins as $plugin) {
            if (!empty($plugin['isActive'])) {
                $plugin_active++;
            }
            if (!empty($plugin['needsUpdate'])) {
                $plugin_updates++;
            }
            if (($plugin['autoUpdate'] ?? '') === 'on') {
                $plugin_auto_updates++;
            }
        }

        foreach ($themes as $theme) {
            if (!empty($theme['isActive'])) {
                $theme_active++;
            }
            if (!empty($theme['needsUpdate'])) {
                $theme_updates++;
            }
            if (($theme['autoUpdate'] ?? '') === 'on') {
                $theme_auto_updates++;
            }
        }

        return [
            'pluginTotal' => count($plugins),
            'pluginActive' => $plugin_active,
            'pluginInactive' => max(0, count($plugins) - $plugin_active),
            'pluginUpdates' => $plugin_updates,
            'pluginAutoUpdates' => $plugin_auto_updates,
            'themeTotal' => count($themes),
            'themeActive' => $theme_active,
            'themeUpdates' => $theme_updates,
            'themeAutoUpdates' => $theme_auto_updates,
            'totalUpdates' => $plugin_updates + $theme_updates + ($core_has_update ? 1 : 0),
        ];
    }

    private static function management_snapshot(): array {
        $core_updates = self::native_core_updates();
        $core_update = $core_updates[0] ?? null;
        $plugins = [];
        $themes = [];

        foreach (self::native_json_stdout(self::native_plugin_list(['plugin', 'list'], 500000)) as $row) {
            if (is_array($row)) {
                $item = self::management_plugin_item($row);
                if ($item['name'] !== '') {
                    $plugins[] = $item;
                }
            }
        }

        foreach (self::native_json_stdout(self::native_theme_list(['theme', 'list'], 500000)) as $row) {
            if (is_array($row)) {
                $item = self::management_theme_item($row);
                if ($item['name'] !== '') {
                    $themes[] = $item;
                }
            }
        }

        $core = [
            'currentVersion' => get_bloginfo('version'),
            'hasUpdate' => is_array($core_update),
        ];

        if (is_array($core_update)) {
            $core['availableVersion'] = (string) ($core_update['version'] ?? '');
            $core['updateType'] = (string) ($core_update['update_type'] ?? '');
            $core['packageUrl'] = (string) ($core_update['package_url'] ?? '');
            $core['locale'] = (string) ($core_update['locale'] ?? get_locale());
            $core['response'] = (string) ($core_update['response'] ?? '');
        }

        return [
            'fetchedAt' => gmdate('c'),
            'connectionMethod' => 'plugin',
            'core' => $core,
            'plugins' => $plugins,
            'themes' => $themes,
            'counts' => self::management_counts($plugins, $themes, is_array($core_update)),
        ];
    }

    private static function security_snapshot(bool $force): array {
        return [
            'health' => self::site_health_scan($force),
            'hardening' => self::public_hardening_state(),
            'refreshedAt' => gmdate('c'),
        ];
    }

    private static function site_refresh_snapshot(): array {
        return [
            'management' => self::management_snapshot(),
            'security' => self::security_snapshot(true),
            'refreshedAt' => gmdate('c'),
        ];
    }

    private static function upgrader_error_message($result, string $fallback, string $target = ''): string {
        if (is_wp_error($result)) {
            $messages = $result->get_error_messages();
            $message = implode('; ', array_values(array_unique(array_filter(array_map('strval', $messages)))));
            $codes = array_values(array_unique(array_filter(array_map('strval', $result->get_error_codes()))));
            if (!empty($codes)) {
                $message = '[' . implode(', ', $codes) . '] ' . $message;
            }
            return $target !== ''
                ? 'Update failed for ' . $target . ': ' . ($message !== '' ? $message : $fallback)
                : ($message !== '' ? $message : $fallback);
        }

        if (is_array($result)) {
            $errors = [];
            foreach ($result as $key => $item) {
                if (is_wp_error($item)) {
                    $label = is_string($key) && $key !== '' ? $key : $target;
                    $errors[] = self::upgrader_error_message($item, $fallback, $label);
                } elseif ($item === false || $item === null) {
                    $label = is_string($key) && $key !== '' ? $key : $target;
                    $errors[] = ($label !== '' ? 'Update failed for ' . $label . ': ' : '') . $fallback;
                }
            }

            $errors = array_values(array_unique(array_filter($errors)));
            return implode('; ', $errors);
        }

        if ($result === false || $result === null) {
            return $target !== '' ? 'Update failed for ' . $target . ': ' . $fallback : $fallback;
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
                'plugin_file' => (string) $file,
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

        if (!$real || !$root) {
            return '';
        }

        $normalized_real = self::normalized_real_path($real);
        $normalized_root = self::normalized_real_path($root);
        $root_prefix = trailingslashit($normalized_root);

        if ($normalized_real !== $normalized_root && strpos($normalized_real, $root_prefix) !== 0) {
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
