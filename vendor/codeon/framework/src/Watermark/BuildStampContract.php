<?php

declare(strict_types=1);

namespace CodeOn\Framework\Watermark;

/**
 * The host plugin's per-license build watermark verifier.
 *
 * Each plugin implements this interface against its own scatter sites:
 *   1. A `CODEON_BUILD_ID` constant in the main plugin file.
 *   2. A `BUILD_FINGERPRINT` class constant on a core class that always
 *      boots (canonically the LicenseGate).
 *   3. A frontend JS sentinel comment (any handler the plugin enqueues).
 *
 * The framework asks `verify(): bool` once during {@see Bootstrap::register()};
 * a false return value flips the bootstrap into recovery mode (chrome +
 * License tab + UpdateChecker only — no business logic registered).
 *
 * The framework does NOT compute the fingerprint — the plugin's existing
 * License code already does that. This interface is only the answer.
 *
 * Reference implementation: fina-sync's `Plugin::isBuildStampValid()` +
 * `LicenseGate::buildFingerprint()` pair. See docs/WATERMARK.md.
 */
interface BuildStampContract
{
    public function verify(): bool;

    /**
     * Returns the verified build ID, or '' if the stamp is invalid.
     * Used by the Footer to show a short identifier for support.
     */
    public function fingerprint(): string;
}
