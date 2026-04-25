<?php

declare(strict_types=1);

namespace CodeOn\Framework\Updates;

use Closure;

/**
 * Generic update checker that teaches WordPress to look at codeon.ge
 * for plugin updates instead of wordpress.org.
 *
 * Each plugin instantiates one of these in its boot path with its
 * pluginId (codeon.ge identifier — `georgian-payments`, `fina-sync`,
 * `quickshipper-delivery`, …), its plugin file basename, and its
 * version. The hook plumbing is shared across every plugin so a
 * future framework upgrade rolls out everywhere at once.
 *
 * The class hooks:
 *   - `site_transient_update_plugins` — inject our row when newer.
 *   - `plugins_api` — render the "View details" popup.
 *   - `delete_site_transient_update_plugins`,
 *     `upgrader_process_complete`, `?force-check=1` — flush our
 *     short-TTL cache so admin's "Check Again" sees fresh data.
 *
 * The download URL the manifest returns carries the merchant's
 * license key + site_url; the WP auto-update cron streams the ZIP
 * through codeon.ge's license-key auth path. No session needed.
 */
final class UpdateChecker
{
    public const MANIFEST_URL_BASE = 'https://codeon.ge/api/v1/updates/';
    public const CACHE_TTL = 3 * HOUR_IN_SECONDS;

    /** @var array{icons:array<string,string>, banners:array<string,string>}|null */
    private ?array $assetPack;

    /** @var Closure():string */
    private Closure $licenseKeyGetter;

    /**
     * @param string $pluginSlug    The plugin folder name (matches WP basename's first segment).
     * @param string $pluginFile    Basename: `<slug>/<file>.php` — what `plugins_api`'s slug check expects.
     * @param string $pluginId      codeon.ge PluginId for the update manifest endpoint.
     * @param string $pluginVersion Current installed version string.
     * @param Closure():string $licenseKeyGetter  Returns the merchant's license key, or '' if unset.
     *                                            Passed as a closure so plugins are free to store the
     *                                            key wherever fits — option, custom table, encrypted
     *                                            metadata — without coupling the framework to any
     *                                            particular `LicenseStore` shape.
     * @param string $homepage      Codeon.ge product page URL — shown in the "View details" modal.
     * @param string $minPhp        Minimum PHP requirement, surfaced in the transient.
     * @param string $minWp         Minimum WP requirement, surfaced in the transient.
     * @param string $buildIdConstant  Per-plugin BUILD_ID constant name (e.g. `FINA_SYNC_BUILD_ID`).
     * @param array{icons?:array<string,string>, banners?:array<string,string>}|null $assetPack
     */
    public function __construct(
        private readonly string $pluginSlug,
        private readonly string $pluginFile,
        private readonly string $pluginId,
        private readonly string $pluginVersion,
        Closure $licenseKeyGetter,
        private readonly string $homepage = '',
        private readonly string $minPhp = '8.1',
        private readonly string $minWp = '6.4',
        private readonly string $buildIdConstant = 'CODEON_BUILD_ID',
        ?array $assetPack = null,
    ) {
        $this->licenseKeyGetter = $licenseKeyGetter;
        $this->assetPack = $assetPack;
    }

    public function register(): void
    {
        add_filter('site_transient_update_plugins', [$this, 'injectUpdate']);
        add_filter('plugins_api', [$this, 'pluginsApi'], 20, 3);
        add_action('delete_site_transient_update_plugins', [$this, 'invalidateCache']);
        add_action('upgrader_process_complete', [$this, 'invalidateCache'], 10, 0);
        add_action('admin_init', [$this, 'maybeForceRefresh']);
    }

    public function invalidateCache(): void
    {
        delete_site_transient($this->cacheKey());
    }

    public function maybeForceRefresh(): void
    {
        if (!is_admin() || !current_user_can('update_plugins')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $force = isset($_GET['force-check']) ? sanitize_text_field(wp_unslash($_GET['force-check'])) : '';
        if ($force === '1') {
            $this->invalidateCache();
        }
    }

    public function injectUpdate(mixed $transient): mixed
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $manifest = $this->manifest();
        if (!$manifest || empty($manifest['version'])) {
            return $transient;
        }
        if (version_compare((string) $manifest['version'], $this->pluginVersion, '<=')) {
            return $transient;
        }
        $assets = $this->resolveAssetPack();
        $transient->response[$this->pluginFile] = (object) [
            'slug'         => $this->pluginSlug,
            'plugin'       => $this->pluginFile,
            'new_version'  => (string) $manifest['version'],
            'package'      => (string) ($manifest['download_url'] ?? ''),
            'url'          => $this->homepage,
            'tested'       => (string) ($manifest['tested'] ?? ''),
            'requires_php' => $this->minPhp,
            'requires'     => $this->minWp,
            'icons'        => $assets['icons'],
            'banners'      => $assets['banners'],
        ];
        return $transient;
    }

    public function pluginsApi(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information') {
            return $result;
        }
        $slug = is_object($args) ? ($args->slug ?? '') : (is_array($args) ? ($args['slug'] ?? '') : '');
        if ($slug !== $this->pluginSlug) {
            return $result;
        }
        $manifest = $this->manifest();
        if (!$manifest) {
            return $result;
        }
        $assets = $this->resolveAssetPack();
        return (object) [
            'name'          => (string) ($manifest['name'] ?? $this->pluginSlug),
            'slug'          => $this->pluginSlug,
            'version'       => (string) ($manifest['version'] ?? $this->pluginVersion),
            'author'        => '<a href="https://codeon.ge">CodeOn</a>',
            'homepage'      => $this->homepage,
            'download_link' => (string) ($manifest['download_url'] ?? ''),
            'requires'      => $this->minWp,
            'requires_php'  => $this->minPhp,
            'icons'         => $assets['icons'],
            'banners'       => $assets['banners'],
            'sections'      => [
                'description' => (string) ($manifest['description']
                    ?? __('CodeOn plugin — see codeon.ge for documentation.', 'codeon-framework')),
                'changelog'   => (string) ($manifest['changelog'] ?? ''),
            ],
        ];
    }

    // ---------------------------------------------------------------------

    private function manifestUrl(): string
    {
        return self::MANIFEST_URL_BASE . rawurlencode($this->pluginId);
    }

    private function cacheKey(): string
    {
        return $this->pluginSlug . '_update_manifest';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function manifest(): ?array
    {
        $cached = get_site_transient($this->cacheKey());
        if (is_array($cached)) {
            return $cached;
        }
        $licenseKey = (string) ($this->licenseKeyGetter)();
        if ($licenseKey === '') {
            return null;
        }

        $queryArgs = [
            'license_key'     => $licenseKey,
            'site_url'        => home_url('/'),
            'current_version' => $this->pluginVersion,
        ];
        $buildId = $this->currentBuildId();
        if ($buildId !== null) {
            $queryArgs['build_id'] = $buildId;
        }

        $response = wp_remote_get(
            add_query_arg($queryArgs, $this->manifestUrl()),
            ['timeout' => 10]
        );
        if (is_wp_error($response)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }
        set_site_transient($this->cacheKey(), $data, self::CACHE_TTL);
        return $data;
    }

    private function currentBuildId(): ?string
    {
        if (!defined($this->buildIdConstant)) {
            return null;
        }
        $value = (string) constant($this->buildIdConstant);
        if ($value === '' || str_contains($value, '__')) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return null;
        }
        return $value;
    }

    /**
     * @return array{icons:array<string,string>, banners:array<string,string>}
     */
    private function resolveAssetPack(): array
    {
        if (is_array($this->assetPack)) {
            return [
                'icons'   => $this->assetPack['icons'] ?? [],
                'banners' => $this->assetPack['banners'] ?? [],
            ];
        }
        // Default convention: each plugin ships icons + banners under
        // assets/icon/ relative to the main plugin file. The plugin
        // can override by passing $assetPack to the constructor.
        $base = plugins_url('assets/icon/', WP_PLUGIN_DIR . '/' . $this->pluginFile);
        return [
            'icons' => [
                '1x'      => $base . 'icon-128x128.png',
                '2x'      => $base . 'icon-256x256.png',
                'svg'     => $base . 'icon.svg',
                'default' => $base . 'icon-256x256.png',
            ],
            'banners' => [
                'low'  => $base . 'banner-772x250.png',
                'high' => $base . 'banner-1544x500.png',
            ],
        ];
    }
}
