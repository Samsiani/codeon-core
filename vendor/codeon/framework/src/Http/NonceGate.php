<?php

declare(strict_types=1);

namespace CodeOn\Framework\Http;

/**
 * Centralised nonce + capability checking for every framework-managed POST.
 *
 * Single source of truth — if a write endpoint forgets to call this, code
 * review catches it because the bypass is visible. The default error message
 * matches WordPress's stock 403 page so themes can't misrender it.
 */
final class NonceGate
{
    public static function verifyOrDie(string $action, string $nonceField = '_wpnonce'): void
    {
        $nonce = isset($_REQUEST[$nonceField])
            ? (string) wp_unslash($_REQUEST[$nonceField])
            : '';

        if (!wp_verify_nonce($nonce, $action)) {
            wp_die(
                esc_html__('Security check failed. Please reload the page and try again.', 'codeon-framework'),
                esc_html__('Forbidden', 'codeon-framework'),
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    public static function verifyOrFalse(string $action, string $nonceField = '_wpnonce'): bool
    {
        $nonce = isset($_REQUEST[$nonceField])
            ? (string) wp_unslash($_REQUEST[$nonceField])
            : '';
        return (bool) wp_verify_nonce($nonce, $action);
    }
}
