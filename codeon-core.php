<?php
/**
 * Plugin Name:       CodeOn Core — Georgian Locations for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/codeon-core/
 * Description:       Replaces WooCommerce's free-text City field with a real cascading Region → Municipality → Settlement picker for Georgia (4,394 settlements). Also acts as the canonical hub for the CodeOn plugin family.
 * Version:           0.1.7
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            CodeOn (Samsiani)
 * Author URI:        https://codeon.ge
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       codeon-core
 * Domain Path:       /languages
 *
 * @package CodeOn\Core
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Re-entry guard. Without it, an unfortunate combination (Jetpack
// Autoloader's plugin discovery, manual require_once from another
// plugin) can cause this file to be required more than once per
// request — re-running define() on already-defined constants would
// emit notices and re-running add_action would register duplicate
// hooks. Bail silently on the second include.
if (defined('CODEON_CORE_VERSION')) {
    return;
}

define('CODEON_CORE_VERSION', '0.1.7');
define('CODEON_CORE_FILE', __FILE__);
define('CODEON_CORE_PATH', plugin_dir_path(__FILE__));
define('CODEON_CORE_URL', plugin_dir_url(__FILE__));
define('CODEON_CORE_BASENAME', plugin_basename(__FILE__));
define('CODEON_CORE_SLUG', dirname(CODEON_CORE_BASENAME));     // dynamic — works even if folder is renamed
define('CODEON_CORE_MIN_PHP', '8.1');
define('CODEON_CORE_MIN_WP', '6.2');
define('CODEON_CORE_MIN_WC', '8.3');

// Per-plugin BUILD_ID. Core ships from .org as plain GPL source so the
// constant stays a static literal instead of a watermark scatter site.
define('CODEON_CORE_BUILD_ID', CODEON_CORE_VERSION);

// Composer autoloader via Jetpack Autoloader — required so co-installed
// CodeOn plugins (which each vendor codeon/framework) all share the
// HIGHEST framework version present at runtime, instead of the
// alphabetically-first plugin's version winning the registration race.
if (is_file(CODEON_CORE_PATH . 'vendor/autoload_packages.php')) {
    require_once CODEON_CORE_PATH . 'vendor/autoload_packages.php';
} elseif (is_file(CODEON_CORE_PATH . 'vendor/autoload.php')) {
    require_once CODEON_CORE_PATH . 'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'CodeOn\\Core\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = CODEON_CORE_PATH . 'includes/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

register_activation_hook(__FILE__, [\CodeOn\Core\Activator::class, 'activate']);

/*
 * Plugin Update Checker — points at GitHub releases.
 *
 * The third arg is the plugin's slug for matching against the WP active
 * plugins list. We derive it from CODEON_CORE_SLUG (= the actual folder
 * name) so PUC works whether the merchant unzipped a release ZIP
 * (`codeon-core/`) or a GitHub source ZIP (`codeon-core-main/`).
 */
if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
    $codeon_core_puc = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Samsiani/codeon-core/',
        __FILE__,
        CODEON_CORE_SLUG
    );
    $codeon_core_puc->getVcsApi()->enableReleaseAssets();
}

// HPOS compatibility — declared as early as possible.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Hub claim must run early — before paid plugins call Bootstrap::register.
// Priority 5 is fine here because the only thing that needs to happen
// EARLY is the hub claim filter; WC-dependent registration moves to the
// `init` hook inside Plugin::boot() so it runs AFTER WC has fired
// `woocommerce_loaded` (which WC fires at plugins_loaded(0), before
// our priority 5 — so binding to woocommerce_loaded here would miss it).
add_action('plugins_loaded', static function (): void {
    \CodeOn\Core\Plugin::instance()->boot();
}, 5);

// Friendly notice when WooCommerce is missing.
add_action('admin_notices', static function (): void {
    if (class_exists('WooCommerce') || !current_user_can('activate_plugins')) {
        return;
    }
    echo '<div class="notice notice-warning"><p>';
    echo esc_html(sprintf(
        /* translators: %s: WooCommerce */
        __('CodeOn Core needs %s to be active for the Georgian Locations feature. The CodeOn hub menu still works without it.', 'codeon-core'),
        'WooCommerce'
    ));
    echo '</p></div>';
});
