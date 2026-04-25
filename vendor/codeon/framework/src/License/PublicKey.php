<?php

declare(strict_types=1);

namespace CodeOn\Framework\License;

/**
 * Single source of truth for the codeon.ge license-response RSA
 * public key.
 *
 * The key is resolved in this order:
 *   1. `CODEON_LICENSE_PUBLIC_KEY` constant defined in wp-config.php
 *      — overrides everything; useful for staging keys.
 *   2. `codeon/license/public_key` filter — for runtime injection.
 *   3. The vendored production key embedded below.
 *
 * The class refuses to verify until a real key is in place. On dev
 * builds (placeholder still present) verification short-circuits to
 * "trusted" so local development against a non-production endpoint
 * keeps working — see {@see isPlaceholder()}.
 */
final class PublicKey
{
    public const PLACEHOLDER = 'REPLACE_WITH_PRODUCTION_PUBLIC_KEY_BASE64';

    /**
     * Production RSA-2048 public key. Replace at release time —
     * deliberately left as the placeholder in the vendored library
     * so that every plugin that bundles the framework is forced to
     * decide whether to ship the prod key, override at runtime, or
     * stay in dev mode.
     */
    private const VENDORED_PEM = "-----BEGIN PUBLIC KEY-----\n"
        . self::PLACEHOLDER . "\n"
        . "-----END PUBLIC KEY-----\n";

    public static function pem(): string
    {
        if (defined('CODEON_LICENSE_PUBLIC_KEY')) {
            $constant = (string) constant('CODEON_LICENSE_PUBLIC_KEY');
            if ($constant !== '') {
                return $constant;
            }
        }
        $filtered = apply_filters('codeon/license/public_key', self::VENDORED_PEM);
        return is_string($filtered) && $filtered !== '' ? $filtered : self::VENDORED_PEM;
    }

    public static function isPlaceholder(): bool
    {
        return str_contains(self::pem(), self::PLACEHOLDER);
    }
}
