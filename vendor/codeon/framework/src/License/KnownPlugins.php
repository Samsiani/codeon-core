<?php

declare(strict_types=1);

namespace CodeOn\Framework\License;

/**
 * Canonical registry of CodeOn plugin slug → human label.
 *
 * Used by the License tab to render the "Plan includes" list when a
 * single license unlocks multiple CodeOn plugins. Without this, each
 * plugin's `LicenseAdapter::features()` would have to maintain its own
 * lookup table for sibling plugins — and they'd drift.
 *
 * The map is filterable so individual plugins can register additional
 * slugs (a private bundle, an unreleased plugin, a renamed one)
 * without waiting for a framework release. Built-in entries cover
 * the publicly listed CodeOn catalogue at codeon.ge/plugins.
 */
final class KnownPlugins
{
    /**
     * Translate a module slug into a human label. Falls back to
     * Title-Casing the slug so an unknown plugin never breaks rendering.
     */
    public static function label(string $slug): string
    {
        $map = self::map();
        if (isset($map[$slug]) && $map[$slug] !== '') {
            return $map[$slug];
        }
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * @return array<string,string>
     */
    public static function map(): array
    {
        $defaults = [
            'fina-sync'             => __('Fina ↔ WooCommerce Synchronization', 'codeon-framework'),
            'codeon-payments'       => __('Codeon Georgian Payments', 'codeon-framework'),
            'quickshipper-delivery' => __('QuickShipper Delivery', 'codeon-framework'),
        ];
        /**
         * Filter the slug → label map.
         *
         * @param array<string,string> $defaults
         */
        $filtered = apply_filters('codeon/framework/known_plugins', $defaults);
        return is_array($filtered) ? $filtered : $defaults;
    }
}
