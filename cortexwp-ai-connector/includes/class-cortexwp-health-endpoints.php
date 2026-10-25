<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only health endpoints intended for an external uptime monitor.
 *
 * The endpoints deliberately return plain text and never expose diagnostics.
 * Direct-origin requests are restricted to the CortexWP egress IP. For
 * Cloudflare and other reverse proxies, CortexWP automatically sends a
 * per-site token derived from the existing connector key.
 */
final class CortexWP_Health_Endpoints {
    private const ROUTE_NAMESPACE = 'cortexwp-ai/v1';
    private const SERVICE_IP = '40.233.109.6';
    private const DISK_THRESHOLD_CONSTANT = 'CORTEXWP_UPTIME_DISK_THRESHOLD_MB';
    private const DEFAULT_DISK_THRESHOLD_MB = 500;

    public static function register_routes(): void {
        foreach (['database', 'disk', 'external-http', 'cron'] as $check) {
            register_rest_route(self::ROUTE_NAMESPACE, '/health/' . $check, [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [__CLASS__, 'handle_' . str_replace('-', '_', $check)],
                'permission_callback' => [__CLASS__, 'authorize'],
            ]);
        }
    }

    public static function authorize(WP_REST_Request $request) {
        $provided = (string) $request->get_param('token');
        $ip = self::client_ip();
        $health_token = class_exists('CortexWP_AI_Connector')
            ? CortexWP_AI_Connector::uptime_health_token()
            : '';

        if ($health_token !== '' && $provided !== '' && hash_equals($health_token, $provided)) {
            return true;
        }

        if ($ip === self::SERVICE_IP) {
            return true;
        }

        return new WP_Error('cortexwp_health_forbidden', 'Health check not authorized.', ['status' => 403]);
    }

    public static function handle_database(): WP_REST_Response {
        global $wpdb;
        $ok = (string) $wpdb->get_var('SELECT 1') === '1';
        return self::response($ok, $ok ? 'ok' : 'database unavailable');
    }

    public static function handle_disk(): WP_REST_Response {
        $threshold_mb = defined(self::DISK_THRESHOLD_CONSTANT)
            ? (int) constant(self::DISK_THRESHOLD_CONSTANT)
            : self::DEFAULT_DISK_THRESHOLD_MB;
        $free = disk_free_space(WP_CONTENT_DIR);
        $ok = $free === false || $free >= max(1, $threshold_mb) * MB_IN_BYTES;
        return self::response($ok, $ok ? 'ok' : 'disk space below threshold');
    }

    public static function handle_external_http(): WP_REST_Response {
        $response = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/', [
            'timeout' => 5,
            'sslverify' => true,
            'headers' => ['Cache-Control' => 'no-cache'],
        ]);
        $ok = !is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200;
        return self::response($ok, $ok ? 'ok' : 'external dependency unavailable');
    }

    public static function handle_cron(): WP_REST_Response {
        $crons = _get_cron_array();
        if (empty($crons)) {
            return self::response(true, 'ok');
        }
        $timestamps = array_keys($crons);
        $oldest_timestamp = (int) $timestamps[0];
        $ok = $oldest_timestamp >= time() - (10 * MINUTE_IN_SECONDS);
        return self::response($ok, $ok ? 'ok' : 'cron backlog is stale');
    }

    private static function response(bool $ok, string $message): WP_REST_Response {
        $response = new WP_REST_Response($message, $ok ? 200 : 503);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('Cache-Control', 'no-store');
        $response->header('CDN-Cache-Control', 'no-store');
        $response->header('Cloudflare-CDN-Cache-Control', 'no-store');
        return $response;
    }

    private static function client_ip(): string {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }
}
