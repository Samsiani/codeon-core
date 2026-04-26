<?php
/**
 * Hub claim: makes CodeOn Core the canonical owner of toplevel_page_codeon.
 *
 * Implements the four override points spelled out in the framework's
 * docs/HUB_ARCHITECTURE.md §6:
 *   1. codeon/hub/toplevel_config        — own the brand label/icon/dashboard
 *   2. codeon/hub/default_extensions_enabled — suppress framework's stub
 *   3. our own Extensions submenu via add_submenu_page
 *   4. codeon/admin/enqueue              — layer brand polish on every codeon page
 *
 * The framework's HubRegistry sees our dashboardCallback is callable and
 * removes its own duplicate "CodeOn → CodeOn" sub-entry automatically
 * (HubRegistry.php:273-278).
 *
 * @package CodeOn\Core
 */

declare(strict_types=1);

namespace CodeOn\Core\Hub;

defined('ABSPATH') || exit;

use CodeOn\Framework\Plugin\HubRegistry;

final class CoreHubBoot
{
    private const EXTENSIONS_SLUG = 'codeon-core-extensions';
    private static ?string $extensionsHookSuffix = null;

    public static function register(): void
    {
        // 1) Top-level identity. The framework merges our return into its
        //    defaults via array_merge so any keys we omit fall through.
        add_filter(HubRegistry::FILTER_TOPLEVEL_CONFIG, [self::class, 'topLevelConfig'], 10, 2);

        // 2) Suppress the framework's stub Extensions submenu — we ship
        //    our own richer one (extends framework ExtensionsTab).
        add_filter(HubRegistry::FILTER_DEFAULT_EXTENSIONS, '__return_false');

        // 3) Our Extensions submenu. Priority 9 so it lands BEFORE the
        //    framework's plugin submenu loop at admin_menu(8); but
        //    HubRegistry creates the parent menu at priority 8, so we
        //    register at priority 11 to land after the parent exists.
        add_action('admin_menu', [self::class, 'registerExtensionsSubmenu'], 11);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueExtensionsAssets']);

        // 4) Optional: layer Core brand assets on every codeon admin page.
        //    No-op until we ship the polish CSS in M3.

        // 5) Defensive: send aggressive no-cache headers on every codeon admin
        //    page render. Some WP hosts (CyberPanel/LSCache, Wordfence) ignore
        //    WP core's nocache_headers and serve stale HTML containing dead
        //    nonces, causing the framework's admin-post handler to wp_die with
        //    "Security check failed" on Save. WordPress core sends nocache
        //    headers but only on /wp-admin URLs that include `Cache-Control:
        //    no-cache`. Re-asserting `no-store` is a stronger hint that
        //    front-edge caches honour.
        add_action('admin_init', [self::class, 'sendNoCacheHeaders']);
    }

    /**
     * Force aggressive no-cache headers on every WP-Admin page where our
     * plugin's UI participates. Idempotent + cheap; runs only in admin.
     */
    public static function sendNoCacheHeaders(): void
    {
        if (headers_sent()) return;
        nocache_headers();
        // Stronger directive than nocache_headers() — `no-store` tells edge
        // caches (LiteSpeed/Cloudflare) not to retain the response at all,
        // which is what protects nonces from being recycled.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
        header('Pragma: no-cache', true);
    }

    /**
     * @param array{label?:string,icon?:string,position?:int,capability?:string,dashboardCallback?:?callable} $defaults
     */
    public static function topLevelConfig(array $defaults, string $group): array
    {
        if ($group !== 'codeon') {
            return $defaults;
        }
        return [
            'label'             => __('CodeOn', 'codeon-core'),
            // Inline data-URI keeps the icon shipping with the plugin
            // without a separate HTTP request. Falls back to dashicon if
            // the SVG file is missing for any reason.
            'icon'              => self::iconDataUri(),
            'position'          => 56,
            'capability'        => 'manage_woocommerce',
            'dashboardCallback' => [DashboardRenderer::class, 'render'],
        ];
    }

    public static function registerExtensionsSubmenu(): void
    {
        $hookSuffix = add_submenu_page(
            HubRegistry::TOP_LEVEL_SLUG,
            __('CodeOn Plugins', 'codeon-core'),
            __('Extensions', 'codeon-core'),
            'install_plugins',
            self::EXTENSIONS_SLUG,
            [self::class, 'renderExtensionsPage']
        );
        if (is_string($hookSuffix) && $hookSuffix !== '') {
            self::$extensionsHookSuffix = $hookSuffix;
        }
    }

    public static function renderExtensionsPage(): void
    {
        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'codeon-core'));
        }

        // Honour ?codeon_refresh=1 from the toolbar's Refresh button — same
        // contract as the framework's stub (HubRegistry::renderDefaultExtensions).
        if (isset($_GET['codeon_refresh']) && (string) $_GET['codeon_refresh'] === '1') {
            (new \CodeOn\Framework\Extensions\CatalogClient())->flush();
            wp_safe_redirect(remove_query_arg('codeon_refresh'));
            exit;
        }

        $client = new \CodeOn\Framework\Extensions\CatalogClient();
        $tab    = new ExtensionsTab($client);

        echo '<div class="wrap codeon-wrap">';
        echo '<h1 class="screen-reader-text">' .
            esc_html__('CodeOn Plugins', 'codeon-core') . '</h1>';
        $tab->render(self::EXTENSIONS_SLUG);
        echo '</div>';
    }

    /**
     * Enqueue framework Extensions assets on our Extensions submenu page.
     * The framework's HubRegistry only enqueues them when ITS stub is
     * the active page; since we suppressed the stub we have to enqueue
     * the same assets ourselves so the install modal + grid styling work.
     */
    public static function enqueueExtensionsAssets(string $hookSuffix): void
    {
        if (self::$extensionsHookSuffix === null || $hookSuffix !== self::$extensionsHookSuffix) {
            return;
        }
        $base = self::frameworkUrl();
        $ver  = self::frameworkAssetVersion();

        wp_enqueue_style('codeon-framework-admin',
            $base . 'assets/css/codeon-admin.css', [], $ver);
        wp_enqueue_script('codeon-framework-admin',
            $base . 'assets/js/codeon-admin.js', [], $ver, true);

        wp_enqueue_style('codeon-framework-extensions',
            $base . 'assets/css/codeon-extensions.css', ['codeon-framework-admin'], $ver);
        wp_enqueue_script('codeon-framework-extensions',
            $base . 'assets/js/codeon-extensions.js', [], $ver, true);

        wp_localize_script('codeon-framework-extensions', 'CodeOnFramework', [
            'i18n' => [
                'installModalTitle' => __('Install %s', 'codeon-core'),
            ],
        ]);
    }

    private static function iconDataUri(): string
    {
        $iconPath = CODEON_CORE_PATH . 'assets/icon/menu-icon.svg';
        if (is_readable($iconPath)) {
            $svg = (string) file_get_contents($iconPath);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        }
        return 'dashicons-superhero';
    }

    private static function frameworkUrl(): string
    {
        // Framework lives under codeon-core/vendor/codeon/framework/.
        // plugin_dir_url returns the URL of the calling file's directory.
        return plugin_dir_url(CODEON_CORE_FILE) . 'vendor/codeon/framework/';
    }

    private static function frameworkAssetVersion(): string
    {
        $manifest = CODEON_CORE_PATH . 'vendor/codeon/framework/composer.json';
        if (is_readable($manifest)) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (is_array($data) && !empty($data['version'])) {
                return (string) $data['version'];
            }
        }
        return CODEON_CORE_VERSION;
    }
}
