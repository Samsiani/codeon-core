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
            /* CodeOn brand palette — mirrors codeon.ge tokens so the WP-admin
               surface looks like part of the same product. Minimalistic by
               design: flat surfaces, subtle borders, accent colour used only
               for primary actions and active states. */
            .codeon-dashboard {
                --bg: #fff;
                --bg-soft: #fafbfc;
                --bg-muted: #f4f5f7;
                --border: #e6e8ec;
                --border-strong: #d4d7de;
                --border-muted: #eef0f3;
                --fg: #0b0f19;
                --fg-strong: #000;
                --fg-muted: #4b5363;
                --fg-dim: #5b6270;
                --fg-faint: #9aa0ab;
                --brand: #2563eb;
                --brand-hover: #4779ee;
                --brand-soft: #eff4ff;
                --brand-border: #c9d9fe;
                --success: #0f9d58;
                --success-soft: #e6f4ec;
                --warning: #b7791f;
                --warning-soft: #fdf3e0;
                --danger: #c4362a;
                --danger-soft: #fbebe8;
                --radius-sm: 6px;
                --radius-md: 8px;
                --radius-lg: 12px;
                --shadow-xs: 0 1px 0 rgba(15,23,42,0.04);

                color: var(--fg);
                max-width: 1180px;
            }

            .codeon-dashboard .codeon-card {
                background: var(--bg);
                border: 1px solid var(--border);
                border-radius: var(--radius-lg);
                padding: 20px 22px;
                margin: 14px 0;
                box-shadow: var(--shadow-xs);
            }

            .codeon-dashboard .codeon-welcome {
                background: var(--bg);
                color: var(--fg);
                border: 1px solid var(--border);
                padding: 22px 24px;
            }
            .codeon-dashboard .codeon-welcome-head {
                display: flex; justify-content: space-between; align-items: center;
                gap: 12px; flex-wrap: wrap; margin-bottom: 10px;
            }
            .codeon-dashboard .codeon-welcome h2 {
                font-size: 22px; margin: 0; color: var(--fg-strong);
                line-height: 1.2; font-weight: 600;
            }
            .codeon-dashboard .codeon-welcome p {
                color: var(--fg-muted); line-height: 1.6; margin: 6px 0;
                max-width: 780px;
            }
            .codeon-dashboard .codeon-welcome-lede {
                font-size: 15px; color: var(--fg) !important; margin-top: 2px !important;
            }
            .codeon-dashboard .codeon-welcome-actions { margin-top: 14px; margin-bottom: 0; }
            .codeon-dashboard .codeon-welcome-link {
                display: inline-flex; align-items: center; gap: 4px;
                color: var(--brand); text-decoration: none; font-weight: 500;
                font-size: 13px;
            }
            .codeon-dashboard .codeon-welcome-link:hover {
                color: var(--brand-hover); text-decoration: underline;
            }
            .codeon-dashboard .codeon-welcome-link .dashicons {
                font-size: 14px; width: 14px; height: 14px; line-height: 1.4; vertical-align: -2px;
            }

            .codeon-dashboard .codeon-status-pill {
                display: inline-flex; align-items: center; gap: 4px;
                font-size: 12px; font-weight: 600;
                padding: 3px 10px; border-radius: 999px;
                white-space: nowrap;
                border: 1px solid transparent;
            }
            .codeon-dashboard .codeon-status-pill .dashicons {
                font-size: 13px; width: 13px; height: 13px; line-height: 1;
            }
            .codeon-dashboard .codeon-status-pill--on {
                background: var(--success-soft); color: var(--success);
                border-color: rgba(15,157,88,0.18);
            }
            .codeon-dashboard .codeon-status-pill--off {
                background: var(--danger-soft); color: var(--danger);
                border-color: rgba(196,54,42,0.18);
            }

            .codeon-dashboard .codeon-card-head {
                display: flex; justify-content: space-between; align-items: flex-start;
                gap: 16px; flex-wrap: wrap; margin-bottom: 12px;
            }
            .codeon-dashboard .codeon-card-head h3 {
                margin: 0 0 4px; font-size: 15px; color: var(--fg-strong); font-weight: 600;
            }
            .codeon-dashboard .codeon-card-sub {
                margin: 0; max-width: 720px; color: var(--fg-dim); font-size: 13px;
            }
            .codeon-dashboard .codeon-card-cta {
                display: inline-flex; align-items: center; gap: 6px;
                white-space: nowrap; flex-shrink: 0; border-radius: var(--radius-sm);
                font-size: 13px; font-weight: 500; padding: 6px 14px;
                line-height: 1.5; min-height: 0; height: auto;
            }
            .codeon-dashboard .codeon-card-cta .dashicons {
                font-size: 14px; width: 14px; height: 14px; line-height: 1; vertical-align: -2px;
            }
            .codeon-dashboard .button-primary.codeon-card-cta {
                background: var(--brand); border-color: var(--brand); color: #fff;
                box-shadow: none; text-shadow: none;
            }
            .codeon-dashboard .button-primary.codeon-card-cta:hover,
            .codeon-dashboard .button-primary.codeon-card-cta:focus {
                background: var(--brand-hover); border-color: var(--brand-hover); color: #fff;
                box-shadow: 0 0 0 3px rgba(37,99,235,0.14);
            }
            .codeon-dashboard .button-secondary.codeon-card-cta {
                background: var(--bg); border: 1px solid var(--border-strong);
                color: var(--fg); box-shadow: none;
            }
            .codeon-dashboard .button-secondary.codeon-card-cta:hover,
            .codeon-dashboard .button-secondary.codeon-card-cta:focus {
                background: var(--bg-soft); border-color: var(--brand-border); color: var(--brand);
            }

            .codeon-dashboard .codeon-stats {
                display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 10px; list-style: none; padding: 4px 0 0; margin: 0;
            }
            .codeon-dashboard .codeon-stats li {
                background: var(--bg-soft); border: 1px solid var(--border-muted);
                border-radius: var(--radius-sm); padding: 12px 14px;
                display: flex; flex-direction: column; gap: 4px;
            }
            .codeon-dashboard .codeon-stats strong {
                font-size: 20px; line-height: 1.1; color: var(--fg-strong);
                font-weight: 600; font-variant-numeric: tabular-nums;
            }
            .codeon-dashboard .codeon-stats span {
                font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
                color: var(--fg-faint); font-weight: 500;
            }

            .codeon-dashboard .codeon-plugin-list { list-style: none; padding: 0; margin: 0; }
            .codeon-dashboard .codeon-plugin-list li { padding: 4px 0; font-size: 13px; }
            .codeon-dashboard .codeon-plugin-list a { color: var(--fg); text-decoration: none; }
            .codeon-dashboard .codeon-plugin-list a:hover { color: var(--brand); }
            .codeon-dashboard .codeon-version { color: var(--fg-faint); font-size: 12px; margin-left: 8px; }

            .codeon-dashboard .codeon-ecosystem-grid {
                display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 8px; margin-top: 4px;
            }
            .codeon-dashboard .codeon-ecosystem-item {
                background: var(--bg); border: 1px solid var(--border);
                border-radius: var(--radius-sm); padding: 12px 14px;
                display: flex; flex-direction: column; gap: 6px;
                transition: border-color 120ms ease, background-color 120ms ease;
            }
            .codeon-dashboard .codeon-ecosystem-item:hover {
                border-color: var(--brand-border); background: var(--bg-soft);
            }
            .codeon-dashboard .codeon-ecosystem-item.is-installed {
                background: var(--brand-soft); border-color: var(--brand-border);
            }
            .codeon-dashboard .codeon-ecosystem-head {
                display: flex; justify-content: space-between; align-items: center; gap: 8px;
            }
            .codeon-dashboard .codeon-ecosystem-head h4 {
                margin: 0; font-size: 13px; line-height: 1.3;
                font-weight: 600; color: var(--fg-strong);
            }
            .codeon-dashboard .codeon-ecosystem-badge {
                font-size: 10px; font-weight: 600; text-transform: uppercase;
                letter-spacing: 0.4px; padding: 2px 7px; border-radius: 999px;
                white-space: nowrap; display: inline-flex; align-items: center; gap: 2px;
                border: 1px solid transparent;
            }
            .codeon-dashboard .codeon-ecosystem-badge .dashicons {
                font-size: 10px; width: 10px; height: 10px; line-height: 1;
            }
            .codeon-dashboard .codeon-ecosystem-badge--active {
                background: var(--success-soft); color: var(--success);
                border-color: rgba(15,157,88,0.18);
            }
            .codeon-dashboard .codeon-ecosystem-badge--free {
                background: var(--brand-soft); color: var(--brand);
                border-color: var(--brand-border);
            }
            .codeon-dashboard .codeon-ecosystem-badge--available {
                background: var(--bg-muted); color: var(--fg-dim);
                border-color: var(--border);
            }
            .codeon-dashboard .codeon-ecosystem-category {
                font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px;
                color: var(--fg-faint); font-weight: 500;
            }

            .codeon-dashboard .codeon-links ul { list-style: none; padding: 0; margin: 4px 0 0; }
            .codeon-dashboard .codeon-links li { padding: 4px 0; font-size: 13px; }
            .codeon-dashboard .codeon-links a { color: var(--brand); text-decoration: none; }
            .codeon-dashboard .codeon-links a:hover { text-decoration: underline; color: var(--brand-hover); }

            @media (max-width: 720px) {
                .codeon-dashboard .codeon-card-cta { width: 100%; justify-content: center; }
                .codeon-dashboard .codeon-welcome-head { flex-direction: column; align-items: flex-start; }
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
