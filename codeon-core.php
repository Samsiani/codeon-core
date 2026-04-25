<?php
/**
 * Plugin Name:       CodeOn Core — Georgian Locations for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/codeon-core/
 * Description:       Replaces WooCommerce's free-text City field with a real cascading Region → Municipality → Settlement picker for Georgia (4,394 settlements). Also acts as the canonical hub for the CodeOn plugin family.
 * Version:           0.1.3
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

define('CODEON_CORE_VERSION', '0.1.3');
define('CODEON_CORE_FILE', __FILE__);
define('CODEON_CORE_PATH', plugin_dir_path(__FILE__));
define('CODEON_CORE_URL', plugin_dir_url(__FILE__));
define('CODEON_CORE_BASENAME', plugin_basename(__FILE__));
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
//
// Jetpack autoloader generates `autoload_packages.php` in vendor/. When
// it's missing (e.g. someone unzipped the plugin without running
// `composer install`), fall through to the standard composer autoloader,
// then to a hand-rolled PSR-4 stub so the framework error notice still
// has a chance to render.
if (is_file(CODEON_CORE_PATH . 'vendor/autoload_packages.php')) {
    require_once CODEON_CORE_PATH . 'vendor/autoload_packages.php';
} elseif (is_file(CODEON_CORE_PATH . 'vendor/autoload.php')) {
    require_once CODEON_CORE_PATH . 'vendor/autoload.php';
} else {
    // Fallback PSR-4 for installs where Composer dependencies haven't
    // been vendored. Note: framework classes will not load via this path —
    // composer install is required for Hub features.
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
 * Until codeon-core is accepted onto WordPress.org SVN (M3 milestone),
 * WordPress has no built-in way to discover new versions. PUC bridges
 * the gap: on every WP update check, it hits the GitHub releases
 * endpoint, compares the latest tag against CODEON_CORE_VERSION, and
 * if newer, surfaces the update on Plugins → Updates exactly the way
 * WP.org-hosted plugins do. The release ZIP attached by our
 * .github/workflows/release.yml unpacks into wp-content/plugins/
 * codeon-core/ (the slug matches), so PUC's installer is happy.
 *
 * After WP.org acceptance, PUC and WP.org will both see the plugin —
 * PUC's update wins because its check runs later than WP.org's. This
 * is the documented PUC behaviour and is fine: GitHub is our canonical
 * release origin; .org is a mirror.
 */
if (class_exists(\YahnisElsts\PluginUpdateChecker\v5\PucFactory::class)) {
    $codeon_core_puc = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Samsiani/codeon-core/',
        __FILE__,
        'codeon-core'
    );
    // Tells PUC to download the release-asset ZIP (codeon-core-vX.Y.Z.zip)
    // attached to each GitHub Release, instead of the auto-generated
    // source-code archive which has the wrong folder name.
    $codeon_core_puc->getVcsApi()->enableReleaseAssets();
}

// HPOS compatibility — declared as early as possible.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Hub claim must run early — before paid plugins call Bootstrap::register
// at plugins_loaded(20). Boot the singleton on plugins_loaded(5).
add_action('plugins_loaded', static function (): void {
    \CodeOn\Core\Plugin::instance()->boot();
}, 5);

// Friendly notice when WooCommerce is missing — Locations feature is the
// majority of the plugin's value but the Hub still works without WC.
add_action('admin_notices', static function (): void {
    if (class_exists('WooCommerce')) {
        return;
    }
    if (!current_user_can('activate_plugins')) {
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
