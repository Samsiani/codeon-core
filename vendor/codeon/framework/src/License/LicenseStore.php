<?php

declare(strict_types=1);

namespace CodeOn\Framework\License;

/**
 * Per-plugin license-snapshot storage with a transient cache and a
 * grace-period fallback.
 *
 * Plugins call {@see refresh()} after a successful
 * {@see LicenseClient::validate()} to persist the response. They
 * call {@see snapshot()} on every boot to read the cached entitlement
 * — purely a read of WP options, no HTTP. The grace-period logic is
 * shared (14 days, matching codeon.ge `GRACE_PERIOD_HOURS`) so every
 * plugin treats expiry the same way.
 *
 * Two options per plugin:
 *   - `<slug>_license_key`        — the merchant's pasted key
 *   - `<slug>_license_snapshot`   — last validated response (array)
 *
 * The snapshot doubles as the cache. Its `cached_at` field gates
 * the in-grace fallback when the customer's DNS is broken or
 * codeon.ge is briefly unavailable.
 */
final class LicenseStore
{
    public const GRACE_HOURS = 14 * 24;

    public function __construct(
        private readonly string $pluginSlug,
    ) {
    }

    public function getKey(): string
    {
        return (string) get_option($this->pluginSlug . '_license_key', '');
    }

    public function setKey(string $key): void
    {
        update_option($this->pluginSlug . '_license_key', $key, false);
    }

    public function clearKey(): void
    {
        delete_option($this->pluginSlug . '_license_key');
    }

    /**
     * Persist a successful validation. Adds a `cached_at` timestamp
     * the rest of the framework uses for grace-period evaluation.
     *
     * @param array<string,mixed> $response Signed response body.
     */
    public function setSnapshot(array $response): void
    {
        $response['cached_at'] = time();
        update_option($this->pluginSlug . '_license_snapshot', $response, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function getSnapshot(): array
    {
        $stored = get_option($this->pluginSlug . '_license_snapshot', []);
        return is_array($stored) ? $stored : [];
    }

    public function clearSnapshot(): void
    {
        delete_option($this->pluginSlug . '_license_snapshot');
    }

    /**
     * Effective status, taking grace-period and explicit revocation
     * into account.
     *
     * Returns one of:
     *   - 'active'   — license valid and within expiry
     *   - 'grace'    — past expiry but inside the grace window;
     *                  plugin keeps running, admin shows banner
     *   - 'expired'  — past expiry AND grace window
     *   - 'revoked'  — codeon.ge marked the license suspended
     *   - 'inactive' — never activated (no key / no snapshot)
     */
    public function effectiveStatus(?int $now = null): string
    {
        $now ??= time();
        $snap = $this->getSnapshot();
        $key  = $this->getKey();

        if ($key === '' || $snap === []) {
            return 'inactive';
        }
        $serverStatus = (string) ($snap['status'] ?? '');
        if ($serverStatus === 'suspended' || $serverStatus === 'revoked') {
            return 'revoked';
        }

        $expires = isset($snap['expires']) ? strtotime((string) $snap['expires'] . ' UTC') : false;
        if ($expires === false) {
            return $serverStatus !== '' ? $serverStatus : 'inactive';
        }
        if ($expires >= $now) {
            return 'active';
        }

        $graceCutoff = $expires + self::GRACE_HOURS * HOUR_IN_SECONDS;
        if ($now <= $graceCutoff) {
            return 'grace';
        }
        return 'expired';
    }

    /**
     * Pretty modules list — strips the underscore→hyphen wire format
     * and dedupes. Useful in the admin status card.
     *
     * @return array<int,string>
     */
    public function modules(): array
    {
        $snap = $this->getSnapshot();
        $modules = is_array($snap['modules'] ?? null) ? $snap['modules'] : [];
        return array_values(array_unique(array_map(
            static fn ($m) => (string) $m,
            $modules
        )));
    }
}
