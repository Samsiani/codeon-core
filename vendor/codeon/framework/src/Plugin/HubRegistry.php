<?php

declare(strict_types=1);

namespace CodeOn\Framework\Plugin;

use CodeOn\Framework\Admin\Page;
use CodeOn\Framework\Extensions\CatalogClient;
use CodeOn\Framework\Extensions\ExtensionsTab;
use CodeOn\Framework\Extensions\InstallController;
use CodeOn\Framework\License\LicenseClient;
use CodeOn\Framework\Logging\Logger;

/**
 * Singleton coordinator that merges every hub-mode plugin's admin
 * page into a single top-level "CodeOn" menu.
 *
 * Why a registry: WordPress hooks `admin_menu` once per request.
 * If three CodeOn plugins each call `add_menu_page()` we get three
 * top-level menus. Instead we let plugins register against this
 * class during `plugins_loaded`, then drain everything in a single
 * `admin_menu` callback that creates the top-level once and
 * `add_submenu_page()`s each plugin under it.
 *
 * Top-level identity (icon, label, position, dashboard callback) is
 * resolved through the `codeon/hub/toplevel_config` filter so the
 * future free CodeOn Core plugin can claim ownership without a
 * framework code change. When Core is absent the framework's
 * defaults stand: label "CodeOn", `dashicons-admin-generic`,
 * position 58, and the dashboard route redirects to the first
 * registered submenu.
 *
 * The registry is process-global by design — every consumer loads
 * the same vendored framework, so a single static map across
 * Bootstrap calls is exactly what we want.
 */
final class HubRegistry
{
    public const TOP_LEVEL_SLUG = 'codeon';
    public const EXTENSIONS_SLUG = 'codeon-extensions';
    public const FILTER_TOPLEVEL_CONFIG = 'codeon/hub/toplevel_config';
    /** Filter: return false to suppress the framework's default Extensions submenu. */
    public const FILTER_DEFAULT_EXTENSIONS = 'codeon/hub/default_extensions_enabled';

    /**
     * @var array<string, array{manifest: Manifest, page: Page}>
     *      Keyed by manifest->slug so duplicate registrations no-op.
     */
    private static array $registered = [];

    /** @var bool Has the admin_menu hook already been wired up? */
    private static bool $hookInstalled = false;

    /** @var array<string, true> Hub-group → registered flag. */
    private static array $topLevelRegistered = [];

    public static function register(Manifest $manifest, Page $page): void
    {
        if ($manifest->slug === self::TOP_LEVEL_SLUG) {
            // The top-level slug is reserved; a plugin can't claim it
            // as its own submenu slug or it collides with the parent
            // page WordPress generates automatically.
            error_log(sprintf(
                '[CodeOn] HubRegistry: plugin slug "%s" collides with the reserved top-level slug; refusing to register.',
                $manifest->slug
            ));
            return;
        }

        if (isset(self::$registered[$manifest->slug])) {
            // Same plugin double-registered (likely a plugins_loaded
            // hook firing twice). Last-writer wins on the page object;
            // suppresses log noise.
            self::$registered[$manifest->slug] = [
                'manifest' => $manifest,
                'page'     => $page,
            ];
            return;
        }

        self::$registered[$manifest->slug] = [
            'manifest' => $manifest,
            'page'     => $page,
        ];

        if (!self::$hookInstalled) {
            // Priority 8 = before WP's default menu population (10),
            // so submenu order is deterministic. Plugins must finish
            // calling `Bootstrap::register()` before priority 8 fires
            // — i.e. inside `plugins_loaded` / `init` (default 10).
            add_action('admin_menu', [self::class, 'drainAndRender'], 8);
            self::$hookInstalled = true;
            self::ensureSharedServices();
        }
    }

    /**
     * One-time bootstrap of the singleton services every hub-mode
     * plugin needs but only ONE plugin should register: the AJAX
     * handler for the Extensions tab's install flow, the Extensions
     * tab's static assets enqueue, and the catalog client used by
     * both. Called the first time any hub plugin registers.
     */
    private static function ensureSharedServices(): void
    {
        $catalog = new CatalogClient();
        $logger  = new Logger('codeon-hub');
        $license = new LicenseClient($logger);

        (new InstallController($catalog, $license))->register();

        // Enqueue Extensions tab assets only on the Extensions
        // submenu page. The hookSuffix WordPress hands back when
        // we add the submenu encodes the slug; we record it for the
        // gate.
        add_action('admin_enqueue_scripts', [self::class, 'enqueueExtensionsAssets']);
    }

    /**
     * Drain registered plugins and emit `add_menu_page` +
     * `add_submenu_page` calls. Called once on `admin_menu` priority 8.
     */
    public static function drainAndRender(): void
    {
        // Group registrations by hub group. Today there's only
        // 'codeon'; the loop sets up the model for future suites.
        $byGroup = [];
        foreach (self::$registered as $row) {
            $byGroup[$row['manifest']->hubGroup][] = $row;
        }

        foreach ($byGroup as $group => $rows) {
            self::renderGroup($group, $rows);
        }
    }

    /**
     * Resolve the canonical top-level config for a hub group.
     * Surfaces a filter so the Core plugin can override icon,
     * label, position, and the dashboard landing callback without
     * a framework release.
     *
     * @return array{label:string, icon:string, position:int, capability:string, dashboardCallback:?callable}
     */
    public static function resolveTopLevelConfig(string $group): array
    {
        $defaults = [
            'label'             => 'CodeOn',
            'icon'              => 'dashicons-admin-generic',
            'position'          => 58,
            'capability'        => 'manage_options',
            // null = "redirect to first registered submenu". Real
            // dashboard arrives when Core registers a callback via
            // the filter below.
            'dashboardCallback' => null,
        ];

        $config = apply_filters(self::FILTER_TOPLEVEL_CONFIG, $defaults, $group);

        // Defensive shape merge — filter callbacks may drop keys.
        return array_merge($defaults, is_array($config) ? $config : []);
    }

    /**
     * @return array<string, array{manifest: Manifest, page: Page}>
     */
    public static function registered(string $group = 'codeon'): array
    {
        $out = [];
        foreach (self::$registered as $slug => $row) {
            if ($row['manifest']->hubGroup === $group) {
                $out[$slug] = $row;
            }
        }
        return $out;
    }

    /**
     * Test-only reset hook. Production code never calls this; PHPUnit
     * suites do, between tests, so in-process registrations don't
     * leak across cases.
     */
    public static function reset(): void
    {
        self::$registered          = [];
        self::$hookInstalled       = false;
        self::$topLevelRegistered  = [];
    }

    // ---------------------------------------------------------------------

    /**
     * @param array<int, array{manifest: Manifest, page: Page}> $rows
     */
    private static function renderGroup(string $group, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $config = self::resolveTopLevelConfig($group);

        // Top-level menu — created exactly once per group.
        if (!isset(self::$topLevelRegistered[$group])) {
            $dashboardCallback = $config['dashboardCallback'];
            if (!is_callable($dashboardCallback)) {
                // No registered dashboard: the parent page redirects
                // to the first plugin's submenu. WP requires SOME
                // callable for `add_menu_page`, otherwise it dies.
                $firstRow = $rows[0];
                $dashboardCallback = static function () use ($firstRow): void {
                    if (!current_user_can('read')) {
                        wp_die(esc_html__('You do not have permission to access this page.', 'codeon-framework'));
                    }
                    wp_safe_redirect(add_query_arg(
                        ['page' => $firstRow['manifest']->slug],
                        admin_url('admin.php')
                    ));
                    exit;
                };
            }

            add_menu_page(
                $config['label'],
                $config['label'],
                $config['capability'],
                self::TOP_LEVEL_SLUG,
                $dashboardCallback,
                $config['icon'],
                $config['position']
            );

            self::$topLevelRegistered[$group] = true;
        }

        // Submenus — one per registered plugin, in registration order.
        // We use the manifest's render callback (Page::render) as the
        // submenu page handler so the existing tab pipeline runs
        // unchanged.
        foreach ($rows as $row) {
            $manifest = $row['manifest'];
            $page     = $row['page'];

            $hookSuffix = add_submenu_page(
                self::TOP_LEVEL_SLUG,
                $manifest->menuTitle,
                $manifest->resolveHubLabel(),
                $manifest->capability,
                $manifest->slug,
                [$page, 'render']
            );

            if (is_string($hookSuffix) && $hookSuffix !== '') {
                $manifest->rememberHookSuffix($hookSuffix);
            }
        }

        // Default Extensions submenu — the framework's fallback that
        // ensures every hub install has a "Browse plugins" entry,
        // even when CodeOn Core isn't present. Core (when installed)
        // suppresses this via the filter and provides its own
        // richer Extensions UX.
        if ((bool) apply_filters(self::FILTER_DEFAULT_EXTENSIONS, true, $group)) {
            self::registerDefaultExtensionsSubmenu();
        }

        // WordPress auto-creates a duplicate "CodeOn" submenu pointing
        // at the parent page. Only meaningful when the dashboard
        // callback is the redirect stub; remove it so the menu tree
        // doesn't show "CodeOn → CodeOn → first plugin". A Core-
        // provided real dashboard rewrites this entry separately
        // (Core can pin its own labelled "Dashboard" submenu).
        if (
            !is_callable($config['dashboardCallback'])
            && function_exists('remove_submenu_page')
        ) {
            remove_submenu_page(self::TOP_LEVEL_SLUG, self::TOP_LEVEL_SLUG);
        }
    }

    /**
     * Add the framework's default Extensions submenu under the hub.
     * Each plugin's own submenu still renders its tab pipeline; this
     * stub renders only the {@see ExtensionsTab} so the merchant has
     * a place to install + unlock more CodeOn plugins regardless of
     * which premium plugin landed on their site first.
     */
    private static function registerDefaultExtensionsSubmenu(): void
    {
        $hookSuffix = add_submenu_page(
            self::TOP_LEVEL_SLUG,
            __('CodeOn Plugins', 'codeon-framework'),
            __('Extensions', 'codeon-framework'),
            'install_plugins',
            self::EXTENSIONS_SLUG,
            [self::class, 'renderDefaultExtensions']
        );

        if (is_string($hookSuffix) && $hookSuffix !== '') {
            self::$extensionsHookSuffix = $hookSuffix;
        }
    }

    public static function renderDefaultExtensions(): void
    {
        if (!current_user_can('install_plugins')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'codeon-framework'));
        }

        // Honour `?codeon_refresh=1` from the toolbar's "Refresh"
        // button — flush the cache, then redirect to the same page
        // without the param so a refresh-on-reload doesn't loop.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['codeon_refresh']) && (string) $_GET['codeon_refresh'] === '1') {
            (new CatalogClient())->flush();
            $url = remove_query_arg('codeon_refresh');
            wp_safe_redirect($url);
            exit;
        }

        $client = new CatalogClient();
        $tab = new ExtensionsTab($client);

        echo '<div class="wrap codeon-wrap">';
        echo '<h1 class="screen-reader-text">' .
            esc_html__('CodeOn Plugins', 'codeon-framework') . '</h1>';
        $tab->render('codeon-extensions');
        echo '</div>';
    }

    public static function enqueueExtensionsAssets(string $hookSuffix): void
    {
        if (self::$extensionsHookSuffix === null) {
            return;
        }
        if ($hookSuffix !== self::$extensionsHookSuffix) {
            return;
        }
        $base = self::frameworkPackageUrl();
        $ver = self::frameworkAssetVersion();

        // The Extensions submenu is owned by HubRegistry, not by any
        // plugin's Manifest, so the per-plugin {@see Assets::enqueue}
        // gate never fires for this page. Enqueue the base framework
        // CSS/JS directly here so the page picks up the --codeon-*
        // design tokens, HealthGrid styles, and chrome that the
        // Extensions overlay depends on.
        wp_enqueue_style(
            'codeon-framework-admin',
            $base . 'assets/css/codeon-admin.css',
            [],
            $ver
        );
        wp_enqueue_script(
            'codeon-framework-admin',
            $base . 'assets/js/codeon-admin.js',
            [],
            $ver,
            true
        );

        wp_enqueue_style(
            'codeon-framework-extensions',
            $base . 'assets/css/codeon-extensions.css',
            ['codeon-framework-admin'],
            $ver
        );
        wp_enqueue_script(
            'codeon-framework-extensions',
            $base . 'assets/js/codeon-extensions.js',
            [],
            $ver,
            true
        );
        wp_localize_script(
            'codeon-framework-extensions',
            'CodeOnFramework',
            [
                'i18n' => [
                    'installModalTitle' => __('Install %s', 'codeon-framework'),
                ],
            ]
        );
    }

    private static ?string $extensionsHookSuffix = null;

    /**
     * Cache-buster for the framework's Extensions assets. Reads the
     * package's composer.json `version` so a published framework
     * release naturally invalidates the merchant's browser cache;
     * dev builds without a version field fall through to the
     * `filemtime()` of the CSS so local iteration shows up too.
     */
    private static function frameworkAssetVersion(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $packageDir = dirname(__DIR__, 2);
        $manifest = $packageDir . '/composer.json';
        if (is_readable($manifest)) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (is_array($data) && !empty($data['version']) && is_string($data['version'])) {
                $cached = $data['version'];
                return $cached;
            }
        }
        $cssPath = $packageDir . '/assets/css/codeon-extensions.css';
        if (is_readable($cssPath)) {
            $mtime = filemtime($cssPath);
            if ($mtime !== false) {
                $cached = (string) $mtime;
                return $cached;
            }
        }
        $cached = '0';
        return $cached;
    }

    /**
     * Same package-URL resolution Assets.php uses, duplicated here so
     * the Extensions assets can enqueue without a Manifest in scope.
     */
    private static function frameworkPackageUrl(): string
    {
        $packageDir = dirname(__DIR__, 2);
        $contentDir = WP_CONTENT_DIR;
        $contentUrl = content_url();
        if (str_starts_with($packageDir, $contentDir)) {
            $relative = substr($packageDir, strlen($contentDir));
            return trailingslashit($contentUrl . $relative);
        }
        $fallback = (string) apply_filters(
            'codeon/admin/assets_url',
            content_url('/plugins/codeon-framework/')
        );
        return trailingslashit($fallback);
    }
}
