<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

/**
 * Cross-plugin webhook idempotency table.
 *
 * Single source of truth for "have we already processed this bank
 * webhook?" across every CodeOn payment micro-plugin. Each plugin's
 * Activator calls {@see installSchema()} on activation; the first
 * call creates `{$wpdb->prefix}codeon_payment_events`, every
 * subsequent call no-ops via dbDelta. The table survives any single
 * plugin's uninstall — we never auto-drop it because another active
 * plugin may still be relying on the in-flight rows.
 *
 * Handlers use `record()` which atomically `INSERT IGNORE`s the row
 * — the database itself decides who wins the race. Application-level
 * locking is unnecessary.
 *
 * Each row is keyed by `(gateway, event_id)` UNIQUE. `gateway` is the
 * concrete WC gateway slug (`codeon_tbc_card`, `codeon_bog_card`,
 * `codeon_flitt_card`, …) — the slug stays plugin-scoped so two
 * plugins can't accidentally share an event_id space.
 */
final class WebhookEvents
{
    public const TABLE = 'codeon_payment_events';
    public const DB_VERSION_OPTION = 'codeon_payment_events_db_version';
    public const DB_VERSION = '1';

    /**
     * Idempotently install the cross-plugin events table.
     * Safe to call from any plugin's activation hook.
     */
    public static function installSchema(): void
    {
        global $wpdb;

        $table           = self::tableName();
        $charsetCollate  = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            gateway       VARCHAR(40)  NOT NULL,
            event_id      VARCHAR(191) NOT NULL,
            order_id      BIGINT UNSIGNED NULL,
            status        VARCHAR(40)  NOT NULL,
            payload_hash  CHAR(64)     NOT NULL,
            received_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at  DATETIME     NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_gateway_event (gateway, event_id),
            KEY idx_order (order_id),
            KEY idx_received (received_at)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Try to record a webhook event. Returns true when the row was
     * freshly inserted (caller should process side effects) and
     * false when a duplicate already existed (drop the request).
     */
    public static function record(
        string $gateway,
        string $eventId,
        ?int $orderId,
        string $status,
        string $payloadHash
    ): bool {
        global $wpdb;
        $table = self::tableName();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (gateway, event_id, order_id, status, payload_hash, received_at) VALUES (%s, %s, %d, %s, %s, %s)",
                $gateway,
                $eventId,
                $orderId ?? 0,
                $status,
                $payloadHash,
                current_time('mysql', true)
            )
        );
        return 1 === (int) $result;
    }

    /**
     * Mark an event as fully processed. Useful for observability
     * (rows where `processed_at IS NULL` represent crashes between
     * record() and side-effect application; the reconcile cron
     * sweeps them).
     */
    public static function markProcessed(string $gateway, string $eventId): void
    {
        global $wpdb;
        $wpdb->update(
            self::tableName(),
            ['processed_at' => current_time('mysql', true)],
            [
                'gateway'  => $gateway,
                'event_id' => $eventId,
            ],
            ['%s'],
            ['%s', '%s']
        );
    }

    public static function exists(string $gateway, string $eventId): bool
    {
        global $wpdb;
        $table = self::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE gateway = %s AND event_id = %s LIMIT 1",
                $gateway,
                $eventId
            )
        );
        return $value !== null;
    }

    /** Fully-qualified table name — `{prefix}codeon_payment_events`. */
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }
}
