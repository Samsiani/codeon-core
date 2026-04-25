<?php
/**
 * CodeOn Core — singleton bootstrap.
 *
 * Two responsibilities, in order:
 *   1. Claim the shared CodeOn admin hub (filter `codeon/hub/toplevel_config`).
 *      Runs at plugins_loaded(5) so paid CodeOn plugins booting later pick up
 *      our toplevel identity instead of falling back to the framework defaults.
 *   2. Wire up the Locations feature (states, checkout fields, REST, settings)
 *      — only when WooCommerce is active.
 *
 * @package CodeOn\Core
 */

declare(strict_types=1);

namespace CodeOn\Core;

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

        add_action('init', static function (): void {
            load_plugin_textdomain(
                'codeon-core',
                false,
                dirname(CODEON_CORE_BASENAME) . '/languages'
            );
        });

        // 1) Hub claim. Idempotent filter wiring; safe even if other plugins
        //    register first.
        CoreHubBoot::register();

        // 2) Framework chrome for Core's own submenu (Locations + Diagnostics).
        //    Pass null buildStamp — Core ships from .org as plain GPL source.
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

        // 3) Locations feature — needs WooCommerce. Defer until WC has loaded
        //    so woocommerce_states + checkout filters land at the right time.
        if (class_exists('WooCommerce')) {
            add_action('woocommerce_loaded', static function () use ($repo): void {
                LocationsBoot::register($repo);
            }, 20);
        }
    }
}
