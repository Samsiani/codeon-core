<?php

declare(strict_types=1);

namespace CodeOn\Framework\License;

/**
 * The single contract every plugin must implement to plug its existing
 * License/LicenseGate/LicenseStore stack into the framework's
 * {@see \CodeOn\Framework\Admin\LicenseTab}.
 *
 * The framework calls these methods to draw the License tab and route
 * activate/release/refresh actions. It NEVER stores keys, NEVER calls
 * codeon.ge directly, NEVER reasons about grace periods. All of that
 * business logic stays inside each plugin's existing License code — this
 * adapter is a thin presenter layer only.
 *
 * Snapshots and arrays are loose by design so each plugin can return
 * whatever extra context (plan name, modules, expiry, last_check) makes
 * sense for its license model.
 */
interface LicenseAdapter
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_GRACE    = 'grace';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_INACTIVE = 'inactive';

    /**
     * Current license status (one of STATUS_* constants).
     */
    public function status(): string;

    /**
     * Display-safe summary of the current license. Suggested keys:
     *   - key_masked   string  e.g. "XXXX-XXXX-1234"
     *   - plan         string  e.g. "Pro"
     *   - expires_at   int|0   unix timestamp; 0 = perpetual
     *   - last_check   int|0   unix timestamp
     *   - bound_domain string  domain the license is bound to
     *   - last_error   string  human-readable last failure, '' if none
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array;

    /**
     * Activate / re-validate the given key. Should set the key, call out
     * to codeon.ge, store the response. Returns a result envelope.
     *
     * @return array{ok:bool,message:string}
     */
    public function activate(string $key): array;

    /**
     * Release the current domain binding without wiping the local key
     * (used when a merchant moves the site).
     *
     * @return array{ok:bool,message:string}
     */
    public function release(): array;

    /**
     * Force a re-validation against codeon.ge.
     *
     * @return array{ok:bool,message:string}
     */
    public function refresh(): array;

    /**
     * Human-readable list of features the current license enables.
     * Empty array if the licence is inactive.
     *
     * @return array<int,string>
     */
    public function features(): array;
}
