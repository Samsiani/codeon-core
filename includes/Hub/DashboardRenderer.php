<?php
/**
 * Renders the toplevel_page_codeon dashboard — the landing page when a
 * merchant clicks "CodeOn" in the WP sidebar.
 *
 * Designed to surface useful at-a-glance info instead of being a
 * marketing splash. Sections:
 *   - Welcome card with two CTAs (Locations setup, Browse Extensions)
 *   - Installed CodeOn plugins (sibling submenus from HubRegistry::registered)
 *   - Locations dataset health (count, version, last sync)
 *   - Quick links: docs, support
 *
 * @package CodeOn\Core
 */

declare(strict_types=1);

namespace CodeOn\Core\Hub;

use CodeOn\Core\Locations\Data\Repository;
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

        ?>
        <div class="wrap codeon-wrap codeon-dashboard">
            <h1 class="screen-reader-text"><?php esc_html_e('CodeOn Dashboard', 'codeon-core'); ?></h1>

            <div class="codeon-card codeon-welcome">
                <h2><?php esc_html_e('Welcome to CodeOn', 'codeon-core'); ?></h2>
                <p><?php esc_html_e('A small family of WooCommerce plugins built for Georgian stores. CodeOn Core is free — premium plugins (TBC, BOG, Flitt) sit underneath it as siblings.', 'codeon-core'); ?></p>
                <p>
                    <?php if ($hasWc): ?>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=codeon-core')); ?>">
                            <?php esc_html_e('Configure Locations', 'codeon-core'); ?>
                        </a>
                    <?php endif; ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=codeon-core-extensions')); ?>">
                        <?php esc_html_e('Browse Extensions', 'codeon-core'); ?>
                    </a>
                </p>
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
                <h3><?php esc_html_e('Help & resources', 'codeon-core'); ?></h3>
                <ul>
                    <li><a href="https://wordpress.org/support/plugin/codeon-core/" target="_blank" rel="noopener"><?php esc_html_e('Support forum', 'codeon-core'); ?></a></li>
                    <li><a href="https://codeon.ge" target="_blank" rel="noopener">codeon.ge</a></li>
                </ul>
            </div>
        </div>
        <style>
            .codeon-dashboard .codeon-card {
                background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;
                padding: 16px 20px; margin: 12px 0; max-width: 760px;
            }
            .codeon-dashboard .codeon-welcome { background: linear-gradient(135deg, #1a2747 0%, #2a4080 100%); color: #fff; border: none; }
            .codeon-dashboard .codeon-welcome h2,
            .codeon-dashboard .codeon-welcome p { color: #fff; }
            .codeon-dashboard .codeon-stats { display: flex; gap: 32px; list-style: none; padding: 0; margin: 12px 0; }
            .codeon-dashboard .codeon-stats strong { font-size: 24px; display: block; }
            .codeon-dashboard .codeon-plugin-list { list-style: none; padding: 0; }
            .codeon-dashboard .codeon-plugin-list li { padding: 6px 0; }
            .codeon-dashboard .codeon-version { color: #646970; font-size: 12px; margin-left: 8px; }
        </style>
        <?php
    }
}
