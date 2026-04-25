<?php

declare(strict_types=1);

namespace CodeOn\Framework\Http;

use CodeOn\Framework\Admin\Page;

/**
 * Backwards-compat alias layer for legacy `admin_post_*` URLs.
 *
 * When a plugin migrates from a hand-rolled save handler to the framework,
 * we still want every existing merchant bookmark, every cron-triggered
 * fetch, every admin-quicklink — the URLs out there in the wild — to keep
 * working. This router rewires `admin_post_<legacy>` to dispatch into the
 * framework's save flow without changing the URL shape or the nonce action.
 *
 * Usage from a plugin's bootstrap:
 *
 *   AdminPostRouter::aliasLegacy(
 *       legacyAction: 'codeon_payments_save_license',
 *       page: $page,
 *       tabSlug: 'license',
 *       opName: 'save'
 *   );
 *
 * The legacy nonce action stays valid because the merchant's existing form
 * was using it; the framework's NonceGate verifies it against
 * {@see Page::nonceAction()} which the plugin manifest can override.
 */
final class AdminPostRouter
{
    public static function aliasLegacy(
        string $legacyAction,
        Page $page,
        string $tabSlug,
        string $opName = 'save'
    ): void {
        add_action(
            'admin_post_' . $legacyAction,
            static function () use ($page, $tabSlug, $opName): void {
                if (!current_user_can($page->manifest()->capability)) {
                    wp_die(esc_html__('You do not have permission to perform this action.', 'codeon-framework'));
                }
                NonceGate::verifyOrDie($page->nonceAction());

                $posted = [
                    'codeon' => isset($_POST['codeon']) && is_array($_POST['codeon'])
                        ? wp_unslash($_POST['codeon'])
                        : (array) wp_unslash($_POST),
                ];

                $page->dispatchLegacy($tabSlug, $opName, $posted);
            }
        );
    }
}
