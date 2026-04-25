<?php

declare(strict_types=1);

namespace CodeOn\Framework\License;

use CodeOn\Framework\Logging\Logger;

/**
 * HTTP client for codeon.ge's license validator.
 *
 * Verifies the RSA-SHA256 signature on every response with the
 * pinned production public key (see {@see PublicKey}). A mismatched
 * signature is treated as a hard failure — we never accept an
 * unsigned response even if the HTTP call succeeded.
 *
 * One client serves every plugin in the framework: it takes the
 * plugin's per-plugin BUILD_ID constant name in the constructor so
 * the watermark UUID telemetry can run without forcing every plugin
 * to share the same global constant (a known-bad pattern that
 * collides under multi-plugin installs — see
 * codeon-plugin-basic-architecture's per-plugin BUILD_ID rule).
 */
final class LicenseClient
{
    public const ENDPOINT = 'https://codeon.ge/api/v1/validate-license';
    public const RELEASE_ENDPOINT = 'https://codeon.ge/api/v1/release-license-domain';

    /**
     * @param string $buildIdConstant Name of the per-plugin define
     *        carrying the watermark UUID (e.g. `FINA_SYNC_BUILD_ID`).
     *        Default falls back to the legacy shared `CODEON_BUILD_ID`
     *        for plugins still on the old constant; new plugins
     *        should pass their own.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly string $buildIdConstant = 'CODEON_BUILD_ID',
    ) {
    }

    /**
     * @return array{ok:bool, response?:array<string,mixed>, error?:string}
     */
    public function validate(string $licenseKey, string $siteUrl, string $pluginVersion): array
    {
        if ($licenseKey === '') {
            return ['ok' => false, 'error' => __('License key is empty.', 'codeon-framework')];
        }

        $nonce = wp_generate_password(16, false, false);
        $payload = [
            'license_key'    => $licenseKey,
            'site_url'       => $siteUrl,
            'plugin_version' => $pluginVersion,
            'nonce'          => $nonce,
        ];
        $buildId = $this->currentBuildId();
        if ($buildId !== null) {
            $payload['build_id'] = $buildId;
        }

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            $this->logger->warning('License validation transport error: ' . $response->get_error_message());
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        $data   = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => __('Invalid response from license server.', 'codeon-framework')];
        }
        if ($status < 200 || $status >= 300) {
            $msg = (string) ($data['error'] ?? $data['message'] ?? "HTTP {$status}");
            return ['ok' => false, 'error' => $msg];
        }
        if (($data['nonce'] ?? null) !== $nonce) {
            return ['ok' => false, 'error' => __('License nonce mismatch — request/response pair rejected.', 'codeon-framework')];
        }

        // codeon.ge strips any trailing slash before signing the
        // response; WordPress's home_url('/') leaves one on. Normalise
        // both sides before comparing so a path difference as innocent
        // as "/" doesn't lock a perfectly valid license out of the
        // domain.
        $expectedSite = untrailingslashit($siteUrl);
        $receivedSite = untrailingslashit((string) ($data['site_url'] ?? ''));
        if ($expectedSite !== $receivedSite) {
            return ['ok' => false, 'error' => __('License site URL mismatch — response was not signed for this domain.', 'codeon-framework')];
        }

        if (!$this->verifySignature($data)) {
            return ['ok' => false, 'error' => __('License signature invalid — ignoring response.', 'codeon-framework')];
        }

        return ['ok' => true, 'response' => $data];
    }

    /**
     * Fire-and-forget release of the site's domain binding.
     *
     * @return array{ok:bool, error?:string}
     */
    public function release(string $licenseKey, string $siteUrl): array
    {
        $response = wp_remote_post(self::RELEASE_ENDPOINT, [
            'timeout' => 12,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode([
                'license_key' => $licenseKey,
                'site_url'    => $siteUrl,
            ]),
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }
        return ['ok' => ((int) wp_remote_retrieve_response_code($response) === 200)];
    }

    /**
     * @param array<string,mixed> $response
     */
    private function verifySignature(array $response): bool
    {
        // Dev mode: vendored placeholder still in place. Trust the
        // response so local development against staging keeps
        // working. The framework surfaces a banner in the License
        // tab when this is the case so it's never silent in prod.
        if (PublicKey::isPlaceholder()) {
            return true;
        }

        $signature = (string) ($response['signature'] ?? '');
        if ($signature === '') {
            return false;
        }
        $copy = $response;
        unset($copy['signature']);
        $canonical = self::canonicalJson($copy);
        if ($canonical === null) {
            return false;
        }
        $binary = base64_decode($signature, true);
        if ($binary === false) {
            return false;
        }
        $ok = openssl_verify($canonical, $binary, PublicKey::pem(), OPENSSL_ALGO_SHA256);
        return $ok === 1;
    }

    /**
     * Read the per-plugin watermark UUID. Returns null when:
     * - the constant isn't defined
     * - it still holds the placeholder string
     * - the value isn't a UUID (defensive: refuse to send garbage)
     */
    private function currentBuildId(): ?string
    {
        if (!defined($this->buildIdConstant)) {
            return null;
        }
        $value = (string) constant($this->buildIdConstant);
        if ($value === '' || str_contains($value, '__') /* placeholder */ ) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return null;
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $value
     */
    private static function canonicalJson(array $value): ?string
    {
        self::ksortRecursive($value);
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : null;
    }

    /**
     * @param array<mixed> $array
     */
    private static function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        ksort($array);
    }
}
