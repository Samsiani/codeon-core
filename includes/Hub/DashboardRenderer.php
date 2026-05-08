<?php
/**
 * Renders the toplevel_page_codeon dashboard — the landing page when a
 * merchant clicks "CodeOn" in the WP sidebar.
 *
 * Sections (top to bottom):
 *   1. Welcome card — explains the ecosystem mission, two CTAs.
 *   2. The CodeOn ecosystem — every plugin in the family, sourced from
 *      the live codeon.ge catalog (cached) with a hardcoded fallback
 *      so the section never renders empty.
 *   3. Georgian Locations dataset — at-a-glance health stats.
 *   4. Installed CodeOn plugins — sibling submenus from HubRegistry.
 *   5. Help & resources.
 *
 * @package CodeOn\Core
 */

declare(strict_types=1);

namespace CodeOn\Core\Hub;

defined('ABSPATH') || exit;

use CodeOn\Core\Locations\Data\Repository;
use CodeOn\Framework\Extensions\Catalog;
use CodeOn\Framework\Extensions\CatalogClient;
use CodeOn\Framework\Plugin\HubRegistry;

final class DashboardRenderer
{
    public static function render(): void
    {
        if (!current_user_can('read')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'codeon-core'));
        }

        $hubPlugins = HubRegistry::registered('codeon');
        $hasWc      = class_exists('WooCommerce');
        $repo       = $hasWc ? Repository::instance() : null;

        $installedSlugs = [];
        foreach ($hubPlugins as $row) {
            $installedSlugs[$row['manifest']->slug] = $row['manifest']->version;
        }

        $ecosystem = self::ecosystem($installedSlugs);

        ?>
        <div class="wrap codeon-wrap codeon-dashboard">
            <h1 class="screen-reader-text"><?php esc_html_e('CodeOn Dashboard', 'codeon-core'); ?></h1>

            <div class="codeon-card codeon-welcome">
                <h2><?php esc_html_e('Welcome to CodeOn', 'codeon-core'); ?></h2>
                <p class="codeon-welcome-lede">
                    <?php esc_html_e('A complete plugin family for Georgian WooCommerce stores.', 'codeon-core'); ?>
                </p>
                <p>
                    <?php esc_html_e('CodeOn Core (this plugin, free on WordPress.org) ships the Georgian address hierarchy as a checkout cascade — 13 regions, 77 municipalities, 4,394 settlements, validated server-side. The premium plugins sold at codeon.ge plug in beneath it as siblings: every major Georgian payment method (TBC, BOG, Flitt, Credo) with their card and installment variants, the Fina ↔ WooCommerce accounting sync, and QuickShipper courier delivery — all sharing this admin hub, the same License & Updates flow, and the same design language.', 'codeon-core'); ?>
                </p>
                <p class="codeon-welcome-actions">
                    <?php if ($hasWc): ?>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=codeon-core')); ?>">
                            <?php esc_html_e('Configure Locations', 'codeon-core'); ?>
                        </a>
                    <?php endif; ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=codeon-core-extensions')); ?>">
                        <?php esc_html_e('Browse Extensions', 'codeon-core'); ?>
                    </a>
                    <a class="button button-link" href="https://codeon.ge" target="_blank" rel="noopener">
                        <?php esc_html_e('Visit codeon.ge', 'codeon-core'); ?>
                        <span class="dashicons dashicons-external" aria-hidden="true"></span>
                    </a>
                </p>
            </div>

            <div class="codeon-card">
                <h3><?php esc_html_e('The CodeOn ecosystem', 'codeon-core'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Every plugin in the CodeOn family. Plugins you have installed are marked. The rest are available at codeon.ge.', 'codeon-core'); ?>
                </p>
                <div class="codeon-ecosystem-grid">
                    <?php foreach ($ecosystem as $plugin): ?>
                        <div class="codeon-ecosystem-item<?php echo $plugin['installed'] ? ' is-installed' : ''; ?>">
                            <div class="codeon-ecosystem-head">
                                <h4><?php echo esc_html($plugin['name']); ?></h4>
                                <?php if ($plugin['installed']): ?>
                                    <span class="codeon-ecosystem-badge codeon-ecosystem-badge--active">
                                        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                        <?php
                                        if ($plugin['version'] !== '') {
                                            /* translators: %s: plugin version e.g. v0.3.10 */
                                            echo esc_html(sprintf(__('Installed v%s', 'codeon-core'), $plugin['version']));
                                        } else {
                                            esc_html_e('Installed', 'codeon-core');
                                        }
                                        ?>
                                    </span>
                                <?php elseif ($plugin['free']): ?>
                                    <span class="codeon-ecosystem-badge codeon-ecosystem-badge--free">
                                        <?php esc_html_e('Free', 'codeon-core'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="codeon-ecosystem-badge codeon-ecosystem-badge--available">
                                        <?php esc_html_e('Available', 'codeon-core'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($plugin['category'] !== ''): ?>
                                <div class="codeon-ecosystem-category"><?php echo esc_html($plugin['category']); ?></div>
                            <?php endif; ?>
                            <p class="codeon-ecosystem-tagline"><?php echo esc_html($plugin['tagline']); ?></p>
                            <p class="codeon-ecosystem-cta">
                                <?php if ($plugin['installed'] && $plugin['admin_url'] !== ''): ?>
                                    <a href="<?php echo esc_url($plugin['admin_url']); ?>">
                                        <?php esc_html_e('Manage', 'codeon-core'); ?> &rarr;
                                    </a>
                                <?php elseif ($plugin['url'] !== ''): ?>
                                    <a href="<?php echo esc_url($plugin['url']); ?>" target="_blank" rel="noopener">
                                        <?php esc_html_e('Learn more', 'codeon-core'); ?> &rarr;
                                    </a>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($hasWc && $repo !== null): ?>
                <div class="codeon-card">
                    <h3><?php esc_html_e('Georgian Locations dataset', 'codeon-core'); ?></h3>
                    <?php $meta = $repo->meta(); ?>
                    <ul class="codeon-stats">
                        <li><strong><?php echo esc_html(number_format_i18n($meta['region_count'])); ?></strong> <?php esc_html_e('regions', 'codeon-core'); ?></li>
                        <li><strong><?php echo esc_html(number_format_i18n($meta['municipality_count'])); ?></strong> <?php esc_html_e('municipalities', 'codeon-core'); ?></li>
                        <li><strong><?php echo esc_html(number_format_i18n($meta['settlement_count'])); ?></strong> <?php esc_html_e('settlements', 'codeon-core'); ?></li>
                    </ul>
                    <p class="description">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %s: ISO-8601 timestamp */
                            __('Bundle built %s.', 'codeon-core'),
                            $meta['built_at']
                        ));
                        ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p><?php esc_html_e('WooCommerce is not active — install and activate it to use the Georgian Locations cascade.', 'codeon-core'); ?></p>
                </div>
            <?php endif; ?>

            <div class="codeon-card">
                <h3><?php esc_html_e('Installed CodeOn plugins', 'codeon-core'); ?></h3>
                <?php if (empty($hubPlugins)): ?>
                    <p class="description"><?php esc_html_e('Only Core is installed. Browse Extensions to add payment plugins.', 'codeon-core'); ?></p>
                <?php else: ?>
                    <ul class="codeon-plugin-list">
                        <?php foreach ($hubPlugins as $row):
                            $manifest = $row['manifest'];
                            ?>
                            <li>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $manifest->slug)); ?>">
                                    <?php echo esc_html($manifest->resolveHubLabel()); ?>
                                </a>
                                <?php if ($manifest->version !== ''): ?>
                                    <span class="codeon-version">v<?php echo esc_html($manifest->version); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="codeon-card codeon-links">
                <h3><?php esc_html_e('Help &amp; resources', 'codeon-core'); ?></h3>
                <ul>
                    <li><a href="https://wordpress.org/support/plugin/codeon-core/" target="_blank" rel="noopener"><?php esc_html_e('Support forum', 'codeon-core'); ?></a></li>
                    <li><a href="https://codeon.ge" target="_blank" rel="noopener">codeon.ge</a></li>
                </ul>
            </div>
        </div>
        <style>
            .codeon-dashboard .codeon-card {
                background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;
                padding: 16px 20px; margin: 12px 0;
            }
            .codeon-dashboard .codeon-welcome { background: linear-gradient(135deg, #1a2747 0%, #2a4080 100%); color: #fff; border: none; padding: 24px 28px; }
            .codeon-dashboard .codeon-welcome h2 { font-size: 26px; margin-top: 0; }
            .codeon-dashboard .codeon-welcome h2,
            .codeon-dashboard .codeon-welcome p { color: #fff; }
            .codeon-dashboard .codeon-welcome-lede { font-size: 16px; opacity: 0.92; margin-top: 4px; }
            .codeon-dashboard .codeon-welcome-actions .button { margin-right: 6px; }
            .codeon-dashboard .codeon-welcome-actions .button-link { color: #fff; opacity: 0.85; text-decoration: underline; }
            .codeon-dashboard .codeon-welcome-actions .button-link:hover { opacity: 1; }
            .codeon-dashboard .codeon-welcome-actions .dashicons { font-size: 14px; width: 14px; height: 14px; line-height: 1.4; vertical-align: -2px; }

            .codeon-dashboard .codeon-stats { display: flex; gap: 32px; list-style: none; padding: 0; margin: 12px 0; flex-wrap: wrap; }
            .codeon-dashboard .codeon-stats strong { font-size: 24px; display: block; }
            .codeon-dashboard .codeon-plugin-list { list-style: none; padding: 0; }
            .codeon-dashboard .codeon-plugin-list li { padding: 6px 0; }
            .codeon-dashboard .codeon-version { color: #646970; font-size: 12px; margin-left: 8px; }

            .codeon-dashboard .codeon-ecosystem-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 14px;
                margin-top: 14px;
            }
            .codeon-dashboard .codeon-ecosystem-item {
                background: #fafafb;
                border: 1px solid #dcdcde;
                border-radius: 6px;
                padding: 14px 16px;
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            .codeon-dashboard .codeon-ecosystem-item.is-installed { border-color: #2a4080; box-shadow: inset 3px 0 0 #2a4080; }
            .codeon-dashboard .codeon-ecosystem-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
            .codeon-dashboard .codeon-ecosystem-head h4 { margin: 0; font-size: 15px; line-height: 1.3; }
            .codeon-dashboard .codeon-ecosystem-badge {
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                padding: 2px 8px;
                border-radius: 10px;
                white-space: nowrap;
            }
            .codeon-dashboard .codeon-ecosystem-badge .dashicons { font-size: 12px; width: 12px; height: 12px; line-height: 1; vertical-align: -1px; margin-right: 2px; }
            .codeon-dashboard .codeon-ecosystem-badge--active { background: #d4edda; color: #155724; }
            .codeon-dashboard .codeon-ecosystem-badge--free { background: #fff3cd; color: #856404; }
            .codeon-dashboard .codeon-ecosystem-badge--available { background: #e2e3e5; color: #383d41; }
            .codeon-dashboard .codeon-ecosystem-category { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #646970; font-weight: 600; }
            .codeon-dashboard .codeon-ecosystem-tagline { margin: 4px 0 0; font-size: 13px; color: #50575e; line-height: 1.45; }
            .codeon-dashboard .codeon-ecosystem-cta { margin: 6px 0 0; }
            .codeon-dashboard .codeon-ecosystem-cta a { font-size: 13px; text-decoration: none; }
        </style>
        <?php
    }

    /**
     * Build the merged ecosystem list — codeon-core + every plugin in
     * the live codeon.ge catalog, with a hardcoded fallback so the
     * section never renders empty (first install / network down).
     *
     * @param array<string,string> $installedSlugs slug => version map of currently registered hub plugins
     * @return list<array{name:string,tagline:string,category:string,url:string,admin_url:string,installed:bool,version:string,free:bool}>
     */
    private static function ecosystem(array $installedSlugs): array
    {
        $rows = [];

        // codeon-core itself (this plugin) — always first, always installed.
        $rows[] = [
            'name'      => __('CodeOn Core', 'codeon-core'),
            'tagline'   => __('Georgian address hierarchy as a cascading WooCommerce checkout picker — 13 regions, 77 municipalities, 4,394 settlements. Free on WordPress.org. Also the canonical hub for the rest of the family.', 'codeon-core'),
            'category'  => __('Free · Locations', 'codeon-core'),
            'url'       => 'https://wordpress.org/plugins/codeon-core/',
            'admin_url' => admin_url('admin.php?page=codeon-core'),
            'installed' => true,
            'version'   => defined('CODEON_CORE_VERSION') ? (string) CODEON_CORE_VERSION : '',
            'free'      => true,
        ];

        // Pull live catalog from codeon.ge. CatalogClient::fetch() reads
        // the transient first, only HTTPs on miss, and returns null only
        // when both cache AND network fail.
        $catalog = (new CatalogClient())->fetch();

        if ($catalog instanceof Catalog && $catalog->plugins !== []) {
            foreach ($catalog->plugins as $plugin) {
                $installed = isset($installedSlugs[$plugin->pluginSlug]);
                $rows[] = [
                    'name'      => $plugin->name,
                    'tagline'   => $plugin->tagline !== '' ? $plugin->tagline : $plugin->description,
                    'category'  => self::categoryLabel($catalog, $plugin->category),
                    'url'       => $plugin->productUrl,
                    'admin_url' => $installed ? admin_url('admin.php?page=' . $plugin->pluginSlug) : '',
                    'installed' => $installed,
                    'version'   => $installed ? ($installedSlugs[$plugin->pluginSlug] ?? '') : '',
                    'free'      => false,
                ];
            }
            return $rows;
        }

        // Offline / first-load fallback. Hand-curated to match the
        // ecosystem table in `codeon-plugin-basic-architecture/README.md`.
        // Slugs match each plugin's WordPress folder so the installed
        // detection still works against $installedSlugs.
        $fallback = [
            ['fina-sync',                    __('Fina ↔ WooCommerce Sync', 'codeon-core'),  __('Sync', 'codeon-core'),       __('Keep WooCommerce products, prices and stock aligned with the Fina accounting system on a scheduled sync.', 'codeon-core'),                                              'https://codeon.ge/plugins/synchronization/fina'],
            ['quickshipper-delivery',        __('QuickShipper Delivery', 'codeon-core'),    __('Shipping', 'codeon-core'),   __('Real-time WooCommerce shipping rates from QuickShipper couriers (Glovo, Wolt, Go Delivery, OnWay, Easy Way, Georgian Post, …).', 'codeon-core'),                'https://codeon.ge/plugins/shipping/quickshipper'],
            ['codeon-tbc-card-payment',      __('TBC Card Payments', 'codeon-core'),        __('Payments', 'codeon-core'),   __('TBC Bank Card (TPay) gateway — Visa / Mastercard / Apple Pay / Google Pay, 3DS, pre-auth + capture, refunds.', 'codeon-core'),                                       'https://codeon.ge/plugins/payments/tbc-card'],
            ['codeon-tbc-installments',      __('TBC Online Installments', 'codeon-core'),  __('Payments', 'codeon-core'),   __('TBC Bank installments gateway for WooCommerce.', 'codeon-core'),                                                                                                  'https://codeon.ge/plugins/payments/tbc-inst'],
            ['codeon-bog-card-payment',      __('BOG Card Payments', 'codeon-core'),        __('Payments', 'codeon-core'),   __('Bank of Georgia Card (iPay) gateway — Card / Apple Pay / Google Pay / BOG P2P / MR / Gift Card via Business API v1.', 'codeon-core'),                              'https://codeon.ge/plugins/payments/bog-card'],
            ['codeon-bog-installments',      __('BOG Installments', 'codeon-core'),         __('Payments', 'codeon-core'),   __('Bank of Georgia monthly-installments gateway for WooCommerce, with checkout estimator and reconcile sweep.', 'codeon-core'),                                      'https://codeon.ge/plugins/payments/bog-inst'],
            ['codeon-flitt-payment',         __('Flitt Payments', 'codeon-core'),           __('Payments', 'codeon-core'),   __('Flitt (ex-Fondy) hosted-redirect card gateway with synchronous refunds and signed webhooks.', 'codeon-core'),                                                      'https://codeon.ge/plugins/payments/flitt'],
            ['codeon-credo-installments',    __('Credo Installments', 'codeon-core'),       __('Payments', 'codeon-core'),   __('Credo Bank installments gateway for WooCommerce (polling-only, no webhook).', 'codeon-core'),                                                                     'https://codeon.ge/plugins/payments/credo-inst'],
        ];

        foreach ($fallback as [$slug, $name, $category, $tagline, $url]) {
            $installed = isset($installedSlugs[$slug]);
            $rows[] = [
                'name'      => $name,
                'tagline'   => $tagline,
                'category'  => $category,
                'url'       => $url,
                'admin_url' => $installed ? admin_url('admin.php?page=' . $slug) : '',
                'installed' => $installed,
                'version'   => $installed ? ($installedSlugs[$slug] ?? '') : '',
                'free'      => false,
            ];
        }

        return $rows;
    }

    /** Resolve a category id to its human label via the catalog manifest. */
    private static function categoryLabel(Catalog $catalog, string $categoryId): string
    {
        if ($categoryId === '') {
            return '';
        }
        foreach ($catalog->categories as $category) {
            if ($category->id === $categoryId) {
                return $category->label;
            }
        }
        return $categoryId;
    }
}
