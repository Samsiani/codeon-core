<?php

declare(strict_types=1);

namespace CodeOn\Framework\Extensions;

use CodeOn\Framework\Admin\Tab;
use CodeOn\Framework\Plugin\HubRegistry;

/**
 * Single shared tab that lists every CodeOn plugin: installed +
 * active, installed + inactive, and locked (catalog-only).
 *
 * The Core plugin (when it ships) registers its own ExtensionsTab
 * subclass with richer marketing copy + license-management UX. In
 * the meantime the framework auto-registers this tab as a fallback
 * so a fresh install of any premium micro-plugin still has a
 * "Browse plugins" landing page.
 *
 * The card grid + "Unlock" modal HTML is rendered server-side; the
 * accompanying JS (`assets/js/codeon-extensions.js`) takes over to
 * drive the AJAX install flow handled by
 * {@see InstallController}.
 */
class ExtensionsTab extends Tab
{
    public const SLUG = 'extensions';

    /** @var array<string,string> Pluginfile → new_version, populated in render(). */
    private array $updatesByFile = [];

    /** @var array<string,array<string,mixed>> Plugin file → get_plugins() entry. */
    private array $allPlugins = [];

    public function __construct(
        protected readonly CatalogClient $client,
    ) {
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function label(): string
    {
        return __('Extensions', 'codeon-framework');
    }

    public function render(string $nonceAction): void
    {
        $catalog = $this->client->fetch();
        $stale   = $catalog === null;

        if ($stale) {
            echo '<div class="notice notice-warning inline"><p>';
            echo esc_html__(
                'We could not reach the CodeOn catalog. Showing the last cached list, if any. Click Refresh to retry.',
                'codeon-framework'
            );
            echo '</p></div>';
            $catalog = $this->client->cached();
        }

        // `get_plugins()` lives in wp-admin/includes/plugin.php and
        // isn't autoloaded on every admin request. Load it on
        // demand so we can join catalog plugins against installed
        // ones to compute the active/inactive/locked buckets.
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $allPlugins = get_plugins();

        // WP's standard update-check transient, populated by every
        // plugin's UpdateChecker (PUC). When a plugin's installed
        // version is older than the latest manifest it appears under
        // `$updates->response[$pluginFile]->new_version`. We surface
        // that as an inline "Update available" pill + button so
        // merchants don't have to bounce to /plugins.php to upgrade.
        $updates       = get_site_transient('update_plugins');
        $updatesByFile = [];
        if (is_object($updates) && isset($updates->response) && is_array($updates->response)) {
            foreach ($updates->response as $file => $data) {
                if (is_object($data) && isset($data->new_version)) {
                    $updatesByFile[$file] = (string) $data->new_version;
                }
            }
        }
        $this->updatesByFile = $updatesByFile;
        $this->allPlugins    = $allPlugins;

        echo '<div class="codeon-extensions">';
        $this->renderToolbar($stale);

        if ($catalog === null || $catalog->plugins === []) {
            echo '<p class="codeon-empty">' .
                esc_html__('No plugins available yet.', 'codeon-framework') .
                '</p>';
            echo '</div>';
            return;
        }

        $sections = $this->bucketise($catalog, $allPlugins);

        if ($sections['active'] !== []) {
            $this->renderSection(
                __('Active on this site', 'codeon-framework'),
                $sections['active'],
                'active'
            );
        }
        if ($sections['inactive'] !== []) {
            $this->renderSection(
                __('Installed but inactive', 'codeon-framework'),
                $sections['inactive'],
                'inactive'
            );
        }
        if ($sections['locked'] !== []) {
            $this->renderSection(
                __('Available to unlock', 'codeon-framework'),
                $sections['locked'],
                'locked'
            );
        }

        $this->renderModal();
        echo '</div>';
    }

    private function renderToolbar(bool $stale): void
    {
        echo '<div class="codeon-extensions-toolbar">';
        echo '<h2 class="codeon-extensions-title">' .
            esc_html__('CodeOn Plugins', 'codeon-framework') .
            '</h2>';
        echo '<p class="codeon-extensions-sub">' .
            esc_html__(
                'Install and unlock additional CodeOn plugins with your purchased license keys.',
                'codeon-framework'
            ) .
            '</p>';
        $refreshLabel = $stale
            ? __('Retry', 'codeon-framework')
            : __('Refresh', 'codeon-framework');
        printf(
            '<button type="button" class="button button-secondary codeon-extensions-refresh">%s</button>',
            esc_html($refreshLabel)
        );
        echo '</div>';
    }

    /**
     * @param array<string, array{plugin: CatalogPlugin, file: ?string}> $cards
     */
    private function renderSection(string $title, array $cards, string $bucket): void
    {
        echo '<h3 class="codeon-extensions-section-title">' . esc_html($title) . '</h3>';
        echo '<div class="codeon-extensions-grid">';
        foreach ($cards as $card) {
            $this->renderCard($card['plugin'], $card['file'], $bucket);
        }
        echo '</div>';
    }

    private function renderCard(CatalogPlugin $plugin, ?string $pluginFile, string $bucket): void
    {
        $cardClasses = ['codeon-extension-card', 'codeon-extension-card-' . $bucket];
        printf(
            '<article class="%s" data-plugin-id="%s" data-plugin-slug="%s">',
            esc_attr(implode(' ', $cardClasses)),
            esc_attr($plugin->pluginId),
            esc_attr($plugin->pluginSlug)
        );

        if ($plugin->bannerUrl !== null && $plugin->bannerUrl !== '') {
            printf(
                '<div class="codeon-extension-banner" style="background-image:url(\'%s\');"></div>',
                esc_url($plugin->bannerUrl)
            );
        }

        echo '<header class="codeon-extension-head">';
        if ($plugin->iconUrl !== null && $plugin->iconUrl !== '') {
            printf(
                '<img class="codeon-extension-icon" src="%s" alt="" />',
                esc_url($plugin->iconUrl)
            );
        }
        echo '<div class="codeon-extension-titles">';
        printf(
            '<h4 class="codeon-extension-name">%s</h4>',
            esc_html($plugin->name)
        );
        if ($plugin->popular) {
            echo '<span class="codeon-extension-pill codeon-extension-popular">' .
                esc_html__('Popular', 'codeon-framework') . '</span>';
        }
        echo '</div></header>';

        printf(
            '<p class="codeon-extension-tagline">%s</p>',
            esc_html($plugin->tagline)
        );

        $this->renderProducts($plugin);
        $this->renderFooter($plugin, $pluginFile, $bucket);

        echo '</article>';
    }

    private function renderProducts(CatalogPlugin $plugin): void
    {
        if ($plugin->products === []) {
            return;
        }
        echo '<ul class="codeon-extension-products">';
        foreach ($plugin->products as $product) {
            $price = number_format_i18n($product->priceGel(), 0);
            printf(
                '<li><span class="codeon-extension-product-label">%s</span><span class="codeon-extension-product-price">₾%s%s</span></li>',
                esc_html($product->displayLabel()),
                esc_html($price),
                esc_html__('/yr', 'codeon-framework')
            );
        }
        echo '</ul>';
    }

    private function renderFooter(CatalogPlugin $plugin, ?string $pluginFile, string $bucket): void
    {
        echo '<footer class="codeon-extension-foot">';
        if ($bucket === 'active') {
            $href = $this->submenuUrl($plugin->pluginSlug);
            printf(
                '<a class="button button-primary" href="%s">%s</a>',
                esc_url($href),
                esc_html__('Open settings', 'codeon-framework')
            );
            $this->renderVersionPill($pluginFile);
        } elseif ($bucket === 'inactive' && $pluginFile !== null) {
            $activateUrl = wp_nonce_url(
                self_admin_url('plugins.php?action=activate&plugin=' . urlencode($pluginFile)),
                'activate-plugin_' . $pluginFile
            );
            printf(
                '<a class="button button-primary" href="%s">%s</a>',
                esc_url($activateUrl),
                esc_html__('Activate', 'codeon-framework')
            );
            $this->renderVersionPill($pluginFile);
        } else {
            // Locked — primary CTA opens the modal.
            echo '<button type="button" class="button button-primary codeon-extension-unlock">' .
                esc_html__('Unlock', 'codeon-framework') . '</button>';
            if ($plugin->productUrl !== '') {
                printf(
                    '<a class="codeon-extension-buy" href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url($plugin->productUrl),
                    esc_html__('Get a license →', 'codeon-framework')
                );
            }
        }
        echo '</footer>';
    }

    /**
     * Render the version status for an installed (active or inactive)
     * plugin. Three states:
     *   - "v0.1.12 · Latest"  — already on the newest published release
     *   - "v0.1.7 → v0.1.12"  + Update button when an upgrade is available
     *   - silent if we don't know the installed version (shouldn't happen
     *     for hub-mode plugins)
     */
    private function renderVersionPill(?string $pluginFile): void
    {
        if ($pluginFile === null || !isset($this->allPlugins[$pluginFile])) {
            return;
        }
        $installed = isset($this->allPlugins[$pluginFile]['Version'])
            ? (string) $this->allPlugins[$pluginFile]['Version']
            : '';
        if ($installed === '') {
            return;
        }
        $newVersion = $this->updatesByFile[$pluginFile] ?? null;
        if ($newVersion === null) {
            // No pending update — show "Latest" pill.
            printf(
                '<span class="codeon-extension-version codeon-extension-version-latest" title="%s">v%s · %s</span>',
                esc_attr__('You are on the latest published release.', 'codeon-framework'),
                esc_html($installed),
                esc_html__('Latest', 'codeon-framework')
            );
            return;
        }
        // Update available — pill + one-click upgrade link. The URL
        // points at WP's stock single-plugin upgrade flow, which
        // shows progress in the standard upgrader UI.
        $upgradeUrl = wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($pluginFile)),
            'upgrade-plugin_' . $pluginFile
        );
        printf(
            '<span class="codeon-extension-version codeon-extension-version-update">v%s → v%s</span>',
            esc_html($installed),
            esc_html($newVersion)
        );
        printf(
            '<a class="button button-secondary codeon-extension-update-btn" href="%s">%s</a>',
            esc_url($upgradeUrl),
            esc_html__('Update', 'codeon-framework')
        );
    }

    private function renderModal(): void
    {
        $nonce = wp_create_nonce(InstallController::NONCE_ACTION);
        ?>
        <div class="codeon-modal" id="codeon-install-modal" hidden role="dialog" aria-modal="true">
            <div class="codeon-modal-card">
                <header>
                    <h3 class="codeon-modal-title"></h3>
                    <button type="button" class="codeon-modal-close" aria-label="<?php esc_attr_e('Close', 'codeon-framework'); ?>">×</button>
                </header>
                <p class="codeon-modal-body"><?php esc_html_e('Paste the license key for this plugin. We download a watermarked copy from codeon.ge and install it on this site.', 'codeon-framework'); ?></p>
                <label class="codeon-modal-field">
                    <span><?php esc_html_e('License key', 'codeon-framework'); ?></span>
                    <input type="text" class="codeon-modal-key" autocomplete="off" spellcheck="false" placeholder="SMS-XXXX-XXXX-XXXX-XXXX" />
                </label>
                <p class="codeon-modal-error" hidden></p>
                <footer>
                    <button type="button" class="button codeon-modal-cancel"><?php esc_html_e('Cancel', 'codeon-framework'); ?></button>
                    <button type="button" class="button button-primary codeon-modal-submit" data-nonce="<?php echo esc_attr($nonce); ?>"><?php esc_html_e('Install plugin', 'codeon-framework'); ?></button>
                </footer>
            </div>
        </div>
        <?php
    }

    /**
     * Group catalog plugins into active / inactive / locked buckets
     * by joining against the WP `get_plugins()` registry.
     *
     * @param array<string, array<string,mixed>> $allPlugins  WP get_plugins() output
     * @return array{active: array<int, array{plugin: CatalogPlugin, file: ?string}>, inactive: array<int, array{plugin: CatalogPlugin, file: ?string}>, locked: array<int, array{plugin: CatalogPlugin, file: ?string}>}
     */
    private function bucketise(Catalog $catalog, array $allPlugins): array
    {
        $byFolder = [];
        foreach ($allPlugins as $file => $data) {
            $folder = strtok($file, '/');
            if ($folder === false || $folder === $file) {
                // Single-file plugins: folder == file's basename
                // without extension. Best-effort match.
                $folder = pathinfo($file, PATHINFO_FILENAME);
            }
            $byFolder[$folder] = $file;
        }

        $hubGroup = HubRegistry::registered('codeon');
        $hubSlugByFolder = [];
        foreach ($hubGroup as $row) {
            // Heuristic: a hub-mode plugin's manifest slug usually
            // matches its folder name (fina-sync, quickshipper-delivery).
            // The InstallController writes the per-plugin license-key
            // option, so future improvements can join more reliably.
            $hubSlugByFolder[$row['manifest']->slug] = true;
        }

        $active   = [];
        $inactive = [];
        $locked   = [];

        foreach ($catalog->plugins as $plugin) {
            $pluginFile = $byFolder[$plugin->pluginSlug] ?? null;
            if ($pluginFile !== null && function_exists('is_plugin_active') && is_plugin_active($pluginFile)) {
                $active[] = ['plugin' => $plugin, 'file' => $pluginFile];
                continue;
            }
            if ($pluginFile !== null) {
                $inactive[] = ['plugin' => $plugin, 'file' => $pluginFile];
                continue;
            }
            $locked[] = ['plugin' => $plugin, 'file' => null];
        }

        return ['active' => $active, 'inactive' => $inactive, 'locked' => $locked];
    }

    private function submenuUrl(string $pluginSlug): string
    {
        return add_query_arg(
            ['page' => $pluginSlug],
            admin_url('admin.php')
        );
    }
}
