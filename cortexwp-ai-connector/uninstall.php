<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$table = $wpdb->prefix . 'cortexwp_ai_login_events';
$wpdb->query('DROP TABLE IF EXISTS ' . esc_sql($table));

$option_names = [
    'cortexwp_ai_connector_key',
    'cortexwp_ai_connector_key_domain',
    'cortexwp_ai_connector_key_viewed',
    'cortexwp_ai_connector_settings',
    'cortexwp_ai_autopilot_managed',
    'cortexwp_ai_security_hardening',
    'cortexwp_ai_login_table_version',
    'cortexwp_ai_redirect_rules',
    'cortexwp_ai_site_health_cache_v2',
];

foreach ($option_names as $option_name) {
    delete_option($option_name);
    delete_site_option($option_name);
}

$like = $wpdb->esc_like('cortexwp_ai_') . '%';
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $like
));

if (isset($wpdb->sitemeta)) {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
        $like
    ));
}

function cortexwp_uninstall_remove_tree(string $path): void {
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            cortexwp_uninstall_remove_tree($child);
        } elseif (is_file($child) || is_link($child)) {
            @unlink($child);
        }
    }

    @rmdir($path);
}

foreach ([
    WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cortexwp-snippets',
    WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cortexwp-recovery-backups',
    WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cortexwp-recovery-audit',
    WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cortexwp-recovery-nonces',
] as $directory) {
    cortexwp_uninstall_remove_tree($directory);
}

foreach (glob(rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR . 'cortexwp-recovery-*.php') ?: [] as $bridge) {
    if (preg_match('/^cortexwp-recovery-[a-f0-9]{16}\.php$/i', basename($bridge))) {
        @unlink($bridge);
    }
}
