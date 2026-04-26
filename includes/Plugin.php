<?php
/**
 * CodeOn Core — singleton bootstrap.
 *
 * Three responsibilities, in order:
 *
 *   1. Claim the shared CodeOn admin hub via filter `codeon/hub/toplevel_config`.
 *      Runs synchronously at plugins_loaded(5) so paid CodeOn plugins booting
 *      later pick up our toplevel identity instead of the framework defaults.
 *
 *   2. Build the framework Manifest + tabs and call Bootstrap::register so
 *      Core's own submenu (Locations + Diagnostics) renders. Translations
 *      that go INTO the manifest are deferred to `init` to avoid the WP 6.7+
 *      "translation loaded too early" notice.
 *
 *   3. Wire the Locations feature on `init` (NOT `woocommerce_loaded`). WC
 *      fires `woocommerce_loaded` inside its own plugins_loaded(0) callback
 *      — earlier than our plugins_loaded(5) — so a callback we add to it
 *      here would be registered AFTER the action already fired and would
 *      never run. Switching to `init` (which fires after plugins_loaded
 *      completes) guarantees our callback fires while still letting WC's
 *      classes be available.
 *
 * @package CodeOn\Core
 */

declare(strict_types=1);

namespace CodeOn\Core;

defined('ABSPATH') || exit;

use CodeOn\Core\Hub\CoreHubBoot;
use CodeOn\Core\Locations\Boot as LocationsBoot;
use CodeOn\Core\Locations\Settings\DiagnosticsTab;
use CodeOn\Core\Locations\Settings\LocationsTab;
use CodeOn\Framework\Plugin\Bootstrap;
use CodeOn\Framework\Plugin\Manifest;
use CodeOn\Framework\Storage\FlatOptionRepository;

final class Plugin
{
    private static ?Plugin $instance = null;
    private bool $booted = false;

    public static function instance(): Plugin
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        // 1) Hub claim — does not consume any translated strings, safe to run now.
        CoreHubBoot::register();

        // 2) Defer the framework Bootstrap + Locations wiring to `init`.
        //    Both consume translated strings (manifest labels, tab labels,
        //    field labels) so they MUST run after textdomain is loaded.
        //    Priority 5 keeps us early enough that admin_menu (priority 8/10)
        //    still has our hub registration before WP renders the sidebar.
        add_action('init', [$this, 'lateBoot'], 5);

        // load_plugin_textdomain is automatic in WP 4.6+ for plugins on
        // wordpress.org and falls back to /languages/ in the plugin folder
        // for direct installs. Calling it explicitly at `init` is harmless.
        add_action('init', static function (): void {
            load_plugin_textdomain(
                'codeon-core',
                false,
                CODEON_CORE_SLUG . '/languages'
            );
        }, 1);
    }

    private bool $lateBooted = false;

    /**
     * Runs at init(5). At this point: WC is fully loaded, textdomain is
     * loaded, and `woocommerce_register_additional_checkout_field` is
     * defined (WC fires its woocommerce_blocks_loaded earlier than init
     * in modern versions). Idempotent.
     */
    public function lateBoot(): void
    {
        if ($this->lateBooted) {
            return;
        }
        $this->lateBooted = true;

        $repo = new FlatOptionRepository('codeon_core_settings');

        $manifest = (new Manifest('codeon-core', __('CodeOn Core', 'codeon-core')))
            ->version(CODEON_CORE_VERSION)
            ->capability('manage_woocommerce')
            ->dashicon('dashicons-location-alt')
            ->hub(true)
            ->hubLabel(__('Locations', 'codeon-core'))
            ->support('https://wordpress.org/support/plugin/codeon-core/');

        Bootstrap::register(
            $manifest,
            [
                new LocationsTab($repo),
                new DiagnosticsTab(),
            ],
            null
        );

        // Locations feature: needs WC. If WC isn't around the rest of the
        // plugin still works (Hub menu + Extensions tab + admin notice).
        if (class_exists('WooCommerce')) {
            LocationsBoot::register($repo);
        }
    }
}
