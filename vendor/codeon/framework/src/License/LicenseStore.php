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
 * Three options per plugin:
 *   - `<slug>_license_key`        — the merchant's pasted key
 *   - `<slug>_license_snapshot`   — last validated response (array)
 *   - `<slug>_license_check`      — last heartbeat record, success or
 *                                   failure. Lets the License tab
 *                                   surface the real "last checked"
 *                                   time and last error, and lets
 *                                   {@see effectiveStatus()} flip
 *                                   the plugin to revoked the moment
 *                                   codeon.ge stops recognising the
 *                                   key. v0.3.6+.
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
     * Also records a successful heartbeat so the License tab's
     * "Last checked" reflects the current time and any prior error
     * is cleared.
     *
     * @param array<string,mixed> $response Signed response body.
     */
    public function setSnapshot(array $response): void
    {
        $response['cached_at'] = time();
        update_option($this->pluginSlug . '_license_snapshot', $response, false);
        $this->recordCheck(true, false, '');
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
        $this->clearCheck();
    }

    /**
     * Record a heartbeat (success OR failure). Always overwrites the
     * previous record — the tab only ever displays the latest.
     *
     * Plugins should call this from their `LicenseGate::revalidate()`
     * on every cron tick, including failures, so the merchant sees
     * the truth about what's happening even when validation fails.
     * `setSnapshot()` calls this with `(true, false, '')` automatically;
     * adapters only call it directly on failures.
     *
     * @param bool   $ok          Did validation succeed?
     * @param bool   $definitive  Is the failure a server-side rejection
     *                            (key unknown / revoked / domain
     *                            mismatch) vs. a transient error?
     * @param string $error       Human-readable error message; '' on
     *                            success.
     */
    public function recordCheck(bool $ok, bool $definitive, string $error = ''): void
    {
        update_option($this->pluginSlug . '_license_check', [
            'checked_at' => time(),
            'ok'         => $ok,
            'definitive' => $definitive,
            'error'      => $error,
        ], false);
    }

    /**
     * @return array{checked_at?:int, ok?:bool, definitive?:bool, error?:string}
     */
    public function getCheck(): array
    {
        $stored = get_option($this->pluginSlug . '_license_check', []);
        return is_array($stored) ? $stored : [];
    }

    public function clearCheck(): void
    {
        delete_option($this->pluginSlug . '_license_check');
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
     *   - 'revoked'  — codeon.ge marked the license suspended,
     *                  removed it, or refused this domain on the
     *                  last heartbeat (definitive failure). v0.3.6+.
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

        // Definitive rejection from the last heartbeat trumps every
        // cached field. The previous snapshot's `expires` may be
        // years out, but if codeon.ge just told us the key is gone
        // / suspended / bound to a different domain, the merchant
        // is no longer entitled — flip immediately.
        $check = $this->getCheck();
        if (
            isset($check['ok'])
            && $check['ok'] === false
            && ($check['definitive'] ?? false) === true
        ) {
            return 'revoked';
        }

        $serverStatus = (string) ($snap['status'] ?? '');
        if ($serverStatus === 'suspended' || $serverStatus === 'revoked') {
            return 'revoked';
        }
        // Server's explicit `expired` verdict wins over the local
        // grace-window calculation. Grace is a "we can't reach
        // codeon.ge" tolerance built on the expires date; if
        // codeon.ge itself has already told us the license is
        // expired, the local grace must NOT mask that. Burned by
        // this on 2026-05-21 when a license set to `expired` from
        // /admin/customers still resolved locally to `grace` for
        // every CodeOn payment plugin because the snapshot's
        // `expires` was within the grace window. Same fix-class as
        // balance-sync v0.3.15 / fina-sync v3.14.4 /
        // quickshipper-delivery v0.5.2.
        if ($serverStatus === 'expired') {
            return 'expired';
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
