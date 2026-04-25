<?php

declare(strict_types=1);

namespace CodeOn\Framework\Admin;

/**
 * Per-user transient flash bus.
 *
 * Replaces the per-plugin patterns of redirect-with-querystring + lookup
 * tables (codeon-payments) and inline `add_action('admin_notices', closure)`
 * (qsd). The framework adds a notice via {@see add()} just before redirect,
 * the next admin page load calls {@see flush()} which dequeues and emits.
 *
 * Per-user keying so notices from one admin's action don't leak to other
 * admins viewing the same page concurrently.
 */
final class Notices
{
    private const TTL = 60; // seconds — long enough for the redirect, short enough to not stick

    public static function add(string $message, string $type = 'info', ?string $context = null): void
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return;
        }
        $key = self::transientKey($userId);
        $bag = get_transient($key);
        if (!is_array($bag)) {
            $bag = [];
        }
        $bag[] = [
            'message' => $message,
            'type'    => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
            'context' => $context,
        ];
        set_transient($key, $bag, self::TTL);
    }

    public static function flush(): void
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return;
        }
        $key = self::transientKey($userId);
        $bag = get_transient($key);
        if (!is_array($bag) || $bag === []) {
            return;
        }
        delete_transient($key);

        foreach ($bag as $row) {
            printf(
                '<div class="notice notice-%s is-dismissible codeon-notice"><p>%s</p></div>',
                esc_attr($row['type']),
                wp_kses_post($row['message'])
            );
        }
    }

    public static function clear(): void
    {
        $userId = get_current_user_id();
        if ($userId === 0) {
            return;
        }
        delete_transient(self::transientKey($userId));
    }

    private static function transientKey(int $userId): string
    {
        return 'codeon_notices_' . $userId;
    }
}
