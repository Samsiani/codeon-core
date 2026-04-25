<?php

declare(strict_types=1);

namespace CodeOn\Framework\Watermark;

/**
 * Static helpers for the framework's recovery boot path.
 *
 * The contract: when {@see BuildStampContract::verify()} returns false,
 * the framework registers ONLY the menu, Assets, and License tab — every
 * other plugin subsystem is suppressed. The customer's WordPress site
 * keeps serving traffic exactly as it did before; only the plugin's
 * write-side machinery (sync jobs, payment gateways, schedulers, REST
 * mutators, CLI commands) is gated off.
 *
 * The host plugin still owns the decision to NOT register its sync
 * machinery in recovery mode — the framework can't know which files
 * contain it. The framework just promises:
 *   - admin chrome renders
 *   - License tab is reachable so the merchant can trigger an update
 *   - UpdateChecker stays alive so a clean ZIP can replace the install
 *
 * See docs/WATERMARK.md for the full pre-release checklist.
 */
final class RecoveryMode
{
    public static function adminNotice(string $supportEmail = 'support@codeon.ge'): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $msg = sprintf(
            /* translators: %s: support email */
            esc_html__(
                'This plugin failed its build-integrity check. Sync / payment operations are disabled to protect your store. Reinstall a fresh copy from your codeon.ge dashboard, or email %s — your WordPress site and existing orders are unaffected.',
                'codeon-framework'
            ),
            sprintf('<a href="mailto:%1$s">%1$s</a>', esc_attr($supportEmail))
        );
        echo '<div class="notice notice-error">';
        echo '<p><strong>' . esc_html__('CodeOn plugin in recovery mode.', 'codeon-framework') . '</strong></p>';
        echo '<p>' . wp_kses(
            $msg,
            ['a' => ['href' => true]]
        ) . '</p>';
        echo '</div>';
    }
}
