<?php
/**
 * Fires when CodeOn Core is fully uninstalled (not just deactivated).
 * Removes Core's own options. Order meta (_billing_geo_*, _shipping_geo_*)
 * is intentionally preserved — past orders should remain accurate even
 * after the plugin is gone.
 *
 * @package CodeOn\Core
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$options = [
    'codeon_core_settings',
    'codeon_core_dataset_version',
    'codeon_core_activated_at',
];

foreach ($options as $option) {
    delete_option($option);
    delete_site_option($option);
}

// Drop our REST cache transients.
delete_transient('codeon_core_locations_etag');
