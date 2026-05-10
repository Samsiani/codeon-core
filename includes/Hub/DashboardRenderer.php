<?php
/**
 * Renders the toplevel_page_codeon dashboard — the landing page when a
 * merchant clicks "CodeOn" in the WP sidebar.
 *
 * Sections (top to bottom):
 *   1. Welcome card — explains the ecosystem mission, three short paragraphs.
 *      No action buttons here — each section below carries its own CTA.
 *   2. Georgian Locations dataset — at-a-glance stats + "Configure Locations".
 *   3. The CodeOn ecosystem — every plugin in the family + "Browse Extensions".
 *   4. Installed CodeOn plugins — sibling submenus.
 *   5. Help & resources.
 *
 * @package CodeOn\Core
 */

declare(strict_types=1);

namespace CodeOn\Core\Hub;

defined('ABSPATH') || exit;

use CodeOn\Core\Locations\Data\Repository;
use CodeOn\Core\Locations\Settings\FieldMode;
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

        // Cascade master switch status pill (Locations ON / OFF).
        $opts             = (array) get_option('codeon_core_settings', []);
        $locationsEnabled = (bool) ($opts['locations_enabled'] ?? true);

        ?>
        <div class="wrap codeon-wrap codeon-dashboard">
            <h1 class="screen-reader-text"><?php esc_html_e('CodeOn Dashboard', 'codeon-core'); ?></h1>

            <div class="codeon-card codeon-welcome">
                <div class="codeon-welcome-head">
                    <h2><?php esc_html_e('Welcome to CodeOn', 'codeon-core'); ?></h2>
                    <?php if ($hasWc): ?>
                        <span class="codeon-status-pill codeon-status-pill--<?php echo $locationsEnabled ? 'on' : 'off'; ?>">
                            <span class="dashicons dashicons-<?php echo $locationsEnabled ? 'yes-alt' : 'marker'; ?>" aria-hidden="true"></span>
                            <?php
                            echo esc_html(
                                $locationsEnabled
                                    ? __('Locations cascade is ON', 'codeon-core')
                                    : __('Locations cascade is OFF', 'codeon-core')
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
                <p class="codeon-welcome-lede">
                    <?php esc_html_e('CodeOn is a plugin family for Georgian WooCommerce stores. Every plugin shares this admin hub, the same License & Updates flow, and the same design language — install one, the next feels familiar.', 'codeon-core'); ?>
                </p>
                <p>
                    <?php esc_html_e('CodeOn Core (this plugin, free) ships the Georgian address hierarchy as a cascading WooCommerce checkout picker — 13 regions, 77 municipalities, 4,394 settlements, validated server-side. It is also the canonical home for the rest of the family.', 'codeon-core'); ?>
                </p>
                <p>
                    <?php esc_html_e('The premium plugins sold at codeon.ge plug in beneath Core: every major Georgian payment method (TBC, BOG, Flitt, Credo) with their card and installment variants, the Fina ↔ WooCommerce accounting sync, and QuickShipper courier delivery.', 'codeon-core'); ?>
                </p>
                <p class="codeon-welcome-actions">
                    <a class="codeon-welcome-link" href="https://codeon.ge" target="_blank" rel="noopener">
                        <?php esc_html_e('Visit codeon.ge', 'codeon-core'); ?>
                        <span class="dashicons dashicons-external" aria-hidden="true"></span>
                    </a>
                </p>
            </div>

            <?php if ($hasWc && $repo !== null): ?>
                <div class="codeon-card codeon-locations-card">
                    <div class="codeon-card-head">
                        <div>
                            <h3><?php esc_html_e('Georgian Locations dataset', 'codeon-core'); ?></h3>
                            <p class="description codeon-card-sub">
                                <?php
                                $built = (string) ($repo->meta()['built_at'] ?? '');
                                echo esc_html(sprintf(
                                    /* translators: %s: ISO-8601 timestamp */
                                    __('Bundle built %s. Configure how the cascade appears at checkout below.', 'codeon-core'),
                                    $built
                                ));
                                ?>
                            </p>
                        </div>
                        <a class="button button-primary codeon-card-cta" href="<?php echo esc_url(admin_url('admin.php?page=codeon-core&tab=general')); ?>">
                            <span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
                            <?php esc_html_e('Configure Locations', 'codeon-core'); ?>
                        </a>
                    </div>
                    <?php $meta = $repo->meta(); ?>
                    <ul class="codeon-stats">
                        <li>
                            <strong><?php echo esc_html(number_format_i18n($meta['region_count'])); ?></strong>
                            <span><?php esc_html_e('regions', 'codeon-core'); ?></span>
                        </li>
                        <li>
                            <strong><?php echo esc_html(number_format_i18n($meta['municipality_count'])); ?></strong>
                            <span><?php esc_html_e('municipalities', 'codeon-core'); ?></span>
                        </li>
                        <li>
                            <strong><?php echo esc_html(number_format_i18n($meta['settlement_count'])); ?></strong>
                            <span><?php esc_html_e('settlements', 'codeon-core'); ?></span>
                        </li>
                        <li>
                            <strong><?php echo esc_html(self::fieldModeLabel(FieldMode::resolve(FieldMode::FIELD_REGION))); ?></strong>
                            <span><?php esc_html_e('Region field', 'codeon-core'); ?></span>
                        </li>
                        <li>
                            <strong><?php echo esc_html(self::fieldModeLabel(FieldMode::resolve(FieldMode::FIELD_MUNICIPALITY))); ?></strong>
                            <span><?php esc_html_e('Municipality field', 'codeon-core'); ?></span>
                        </li>
                        <li>
                            <strong><?php echo esc_html(self::fieldModeLabel(FieldMode::resolve(FieldMode::FIELD_SETTLEMENT))); ?></strong>
                            <span><?php esc_html_e('Settlement field', 'codeon-core'); ?></span>
                        </li>
                    </ul>
                </div>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p><?php esc_html_e('WooCommerce is not active — install and activate it to use the Georgian Locations cascade.', 'codeon-core'); ?></p>
                </div>
            <?php endif; ?>

            <div class="codeon-card">
                <div class="codeon-card-head">
                    <div>
                        <h3><?php esc_html_e('The CodeOn ecosystem', 'codeon-core'); ?></h3>
                        <p class="description codeon-card-sub">
                            <?php esc_html_e('Every plugin in the CodeOn family. Plugins you have installed are marked. The rest are available at codeon.ge.', 'codeon-core'); ?>
                        </p>
                    </div>
                    <a class="button button-secondary codeon-card-cta" href="<?php echo esc_url(admin_url('admin.php?page=codeon-core-extensions')); ?>">
                        <span class="dashicons dashicons-screenoptions" aria-hidden="true"></span>
                        <?php esc_html_e('Browse Extensions', 'codeon-core'); ?>
                    </a>
                </div>
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
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

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
            .codeon-dashboard { --codeon-deep: #1a2747; --codeon-mid: #2a4080; --codeon-accent: #6c8cff; }
            .codeon-dashboard .codeon-card {
                background: #fff; border: 1px solid #dcdcde; border-radius: 6px;
                padding: 18px 22px; margin: 14px 0;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
            }
            .codeon-dashboard .codeon-welcome {
                background: linear-gradient(135deg, var(--codeon-deep) 0%, var(--codeon-mid) 70%, #3856aa 100%);
                color: #fff; border: none; padding: 28px 30px;
                position: relative; overflow: hidden;
            }
            .codeon-dashboard .codeon-welcome::after {
                content: ""; position: absolute; right: -120px; top: -120px;
                width: 380px; height: 380px; border-radius: 50%;
                background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
                pointer-events: none;
            }
            .codeon-dashboard .codeon-welcome-head {
                display: flex; justify-content: space-between; align-items: center;
                gap: 16px; flex-wrap: wrap; margin-bottom: 8px;
            }
            .codeon-dashboard .codeon-welcome h2 { font-size: 26px; margin: 0; color: #fff; line-height: 1.2; }
            .codeon-dashboard .codeon-welcome p { color: #fff; line-height: 1.55; }
            .codeon-dashboard .codeon-welcome-lede { font-size: 16px; opacity: 0.94; margin-top: 4px; }
            .codeon-dashboard .codeon-welcome-actions { margin-top: 14px; margin-bottom: 0; }
            .codeon-dashboard .codeon-welcome-link {
                display: inline-flex; align-items: center; gap: 4px;
                color: #fff; opacity: 0.9; text-decoration: underline; font-weight: 500;
            }
            .codeon-dashboard .codeon-welcome-link:hover { opacity: 1; color: #fff; }
            .codeon-dashboard .codeon-welcome-link .dashicons { font-size: 14px; width: 14px; height: 14px; line-height: 1.4; vertical-align: -2px; }

            .codeon-dashboard .codeon-status-pill {
                display: inline-flex; align-items: center; gap: 4px;
                font-size: 12px; font-weight: 600;
                padding: 4px 12px; border-radius: 999px;
                background: rgba(255,255,255,0.16);
                border: 1px solid rgba(255,255,255,0.2);
                white-space: nowrap; backdrop-filter: blur(4px);
            }
            .codeon-dashboard .codeon-status-pill .dashicons { font-size: 14px; width: 14px; height: 14px; line-height: 1; }
            .codeon-dashboard .codeon-status-pill--on { background: rgba(56, 178, 95, 0.28); border-color: rgba(56, 178, 95, 0.5); }
            .codeon-dashboard .codeon-status-pill--off { background: rgba(213, 79, 79, 0.28); border-color: rgba(213, 79, 79, 0.5); }

            .codeon-dashboard .codeon-card-head {
                display: flex; justify-content: space-between; align-items: flex-start;
                gap: 16px; flex-wrap: wrap; margin-bottom: 14px;
            }
            .codeon-dashboard .codeon-card-head h3 { margin: 0 0 4px; font-size: 16px; }
            .codeon-dashboard .codeon-card-sub { margin: 0; max-width: 720px; }
            .codeon-dashboard .codeon-card-cta {
                display: inline-flex; align-items: center; gap: 4px;
                white-space: nowrap; flex-shrink: 0;
            }
            .codeon-dashboard .codeon-card-cta .dashicons { font-size: 16px; width: 16px; height: 16px; line-height: 1.4; vertical-align: -3px; }
            .codeon-dashboard .button-primary.codeon-card-cta {
                background: var(--codeon-mid); border-color: var(--codeon-mid);
                color: #fff; box-shadow: 0 1px 0 #1a2747;
            }
            .codeon-dashboard .button-primary.codeon-card-cta:hover {
                background: #233670; border-color: #233670;
            }

            .codeon-dashboard .codeon-stats {
                display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 8px; list-style: none; padding: 12px 0 0; margin: 0;
            }
            .codeon-dashboard .codeon-stats li {
                background: #f6f7fb; border: 1px solid #e6e8f0; border-radius: 5px;
                padding: 10px 12px; display: flex; flex-direction: column; gap: 2px;
                min-height: 0;
            }
            .codeon-dashboard .codeon-stats strong {
                font-size: 20px; line-height: 1.1; color: var(--codeon-deep); font-weight: 700;
            }
            .codeon-dashboard .codeon-stats span {
                font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
                color: #4a5060; font-weight: 600;
            }

            .codeon-dashboard .codeon-plugin-list { list-style: none; padding: 0; margin: 0; }
            .codeon-dashboard .codeon-plugin-list li { padding: 6px 0; }
            .codeon-dashboard .codeon-version { color: #646970; font-size: 12px; margin-left: 8px; }

            .codeon-dashboard .codeon-ecosystem-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 8px; margin-top: 4px;
            }
            .codeon-dashboard .codeon-ecosystem-item {
                background: #fafbff; border: 1px solid #e2e6f4; border-radius: 5px;
                padding: 10px 12px; display: flex; flex-direction: column; gap: 4px;
                transition: border-color 120ms ease, transform 120ms ease;
            }
            .codeon-dashboard .codeon-ecosystem-item:hover { border-color: #b9c2e3; transform: translateY(-1px); }
            .codeon-dashboard .codeon-ecosystem-item.is-installed {
                background: #f1f5ff; border-color: var(--codeon-mid);
                box-shadow: inset 3px 0 0 var(--codeon-mid); padding-left: 14px;
            }
            .codeon-dashboard .codeon-ecosystem-head { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
            .codeon-dashboard .codeon-ecosystem-head h4 { margin: 0; font-size: 13px; line-height: 1.25; font-weight: 600; color: var(--codeon-deep); }
            .codeon-dashboard .codeon-ecosystem-badge {
                font-size: 10px; font-weight: 600; text-transform: uppercase;
                letter-spacing: 0.3px; padding: 2px 8px; border-radius: 999px;
                white-space: nowrap; display: inline-flex; align-items: center; gap: 2px;
            }
            .codeon-dashboard .codeon-ecosystem-badge .dashicons { font-size: 10px; width: 10px; height: 10px; line-height: 1; }
            .codeon-dashboard .codeon-ecosystem-badge--active { background: #d4edda; color: #155724; }
            .codeon-dashboard .codeon-ecosystem-badge--free { background: #fff3cd; color: #856404; }
            .codeon-dashboard .codeon-ecosystem-badge--available { background: #e6e8f0; color: #4a5060; }
            .codeon-dashboard .codeon-ecosystem-category { font-size: 10px; text-transform: uppercase; letter-spacing: 0.4px; color: #646970; font-weight: 600; }

            @media (max-width: 720px) {
                .codeon-dashboard .codeon-card-cta { width: 100%; justify-content: center; }
            }
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
            'slug'      => 'codeon-core',
            'name'      => __('CodeOn Core', 'codeon-core'),
            'tagline'   => __('Georgian address hierarchy as a cascading WooCommerce checkout picker — 13 regions, 77 municipalities, 4,394 settlements. Also the canonical hub for the rest of the family.', 'codeon-core'),
            'category'  => __('Free · Locations', 'codeon-core'),
            'url'       => 'https://codeon.ge',
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
                    'slug'      => $plugin->pluginSlug,
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
            return self::sortByFamily($rows);
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
                'slug'      => $slug,
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

        return self::sortByFamily($rows);
    }

    /**
     * Sort the ecosystem rows so plugins from the same bank/family
     * sit side-by-side (BoG card ↔ BoG installments, TBC card ↔ TBC
     * installments). Unknown slugs fall to the end alphabetically.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function sortByFamily(array $rows): array
    {
        $priority = [
            'codeon-core'                 => 0,   // host plugin always first
            'codeon-bog-card-payment'     => 10,  // BoG family
            'codeon-bog-installments'     => 11,
            'codeon-tbc-card-payment'     => 20,  // TBC family
            'codeon-tbc-installments'     => 21,
            'codeon-flitt-payment'        => 30,
            'codeon-credo-installments'   => 40,
            'fina-sync'                   => 50,  // sync family
            'codeon-1c-sync'              => 51,
            'quickshipper-delivery'       => 60,  // shipping
        ];

        usort($rows, static function (array $a, array $b) use ($priority): int {
            $pa = $priority[$a['slug'] ?? ''] ?? 99;
            $pb = $priority[$b['slug'] ?? ''] ?? 99;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return array_values($rows);
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

    /** Short, dashboard-friendly label for a 3-state field mode. */
    private static function fieldModeLabel(string $mode): string
    {
        return match ($mode) {
            FieldMode::DISABLED => __('Disabled', 'codeon-core'),
            FieldMode::OPTIONAL => __('Optional', 'codeon-core'),
            FieldMode::REQUIRED => __('Required', 'codeon-core'),
            default             => $mode,
        };
    }
}
