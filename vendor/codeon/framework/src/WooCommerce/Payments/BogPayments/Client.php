<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments\BogPayments;

use CodeOn\Framework\Logging\Logger;
use CodeOn\Framework\WooCommerce\Payments\Money;

/**
 * Shared HTTP client for Bank of Georgia's Payments API
 * (`api.bog.ge/payments/v1/...`).
 *
 * Originally lived inside `codeon-bog-installments` (`Api\BogPaymentsClient`,
 * v0.3.0) and `codeon-bog-card-payment` (`Api\BogClient`, v0.2.x) as
 * byte-near-identical copies. Lifted to the framework in v0.3.14 with
 * the second consumer's refactor — `CLAUDE.md` rule 2's "no abstractions
 * for single-use code" trigger has fired.
 *
 * Responsibilities:
 *  - OAuth 2.0 client_credentials token acquisition + caching, single-flight
 *    mutex (HARDENING.md §4) so concurrent checkouts at the expiry window
 *    don't all hammer BOG's OAuth endpoint.
 *  - Structured request/response with redacted logging via the framework
 *    {@see Logger}.
 *  - Typed errors via {@see ApiException}.
 *  - Shipping the BOG callback public key as a class constant for
 *    {@see \CodeOn\Framework\Http\RsaCallbackSignature} (HARDENING.md §1.4
 *    — never make this configurable).
 *
 * Per-plugin namespacing: the constructor takes the consumer plugin's
 * slug and version. Token transient keys, object-cache lock groups, and
 * the `User-Agent` header are scoped to that slug so two CodeOn payment
 * plugins co-installed on the same site never collide on cache or share
 * a misleading log channel.
 *
 * NOT handled here (by design):
 *  - WooCommerce concerns (order objects, meta, hooks).
 *  - Webhook signature verification — plugins call
 *    `RsaCallbackSignature::verify(rawBody, header)` themselves with
 *    {@see Client::CALLBACK_PUBLIC_KEY_PEM}.
 */
final class Client
{
    public const ENV_SANDBOX    = 'sandbox';
    public const ENV_PRODUCTION = 'production';

    private const OAUTH_BASE    = 'https://oauth2.bog.ge';
    private const OAUTH_PATH    = '/auth/realms/bog/protocol/openid-connect/token';
    private const PAYMENTS_BASE = 'https://api.bog.ge';

    private const TIMEOUT             = 15;
    private const TOKEN_TTL_SAFETY_S  = 60;

    /**
     * Single-flight mutex around the token refresh. 30s is well above
     * BOG's OAuth round-trip — long enough to cover network jitter,
     * short enough that a crashed PHP worker can't deadlock new traffic.
     */
    private const TOKEN_LOCK_TTL_S = 30;

    /**
     * BOG-published RSA-2048 public key for verifying `Callback-Signature`
     * on every callback body. Hard-coded here per HARDENING.md §1.4: the
     * key is API-version-scoped, ships with the plugin, and rotates only
     * when BOG rotates the API. Making it configurable would only let an
     * attacker substitute their own key.
     *
     * Source: https://api.bog.ge/docs/en/payments/standard-process/callback
     */
    public const CALLBACK_PUBLIC_KEY_PEM = <<<PEM
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAu4RUyAw3+CdkS3ZNILQh
zHI9Hemo+vKB9U2BSabppkKjzjjkf+0Sm76hSMiu/HFtYhqWOESryoCDJoqffY0Q
1VNt25aTxbj068QNUtnxQ7KQVLA+pG0smf+EBWlS1vBEAFbIas9d8c9b9sSEkTrr
TYQ90WIM8bGB6S/KLVoT1a7SnzabjoLc5Qf/SLDG5fu8dH8zckyeYKdRKSBJKvhx
tcBuHV4f7qsynQT+f2UYbESX/TLHwT5qFWZDHZ0YUOUIvb8n7JujVSGZO9/+ll/g
4ZIWhC1MlJgPObDwRkRd8NFOopgxMcMsDIZIoLbWKhHVq67hdbwpAq9K9WMmEhPn
PwIDAQAB
-----END PUBLIC KEY-----
PEM;

    /**
     * @param string $clientId       BOG OAuth client id (per merchant).
     * @param string $clientSecret   BOG OAuth client secret.
     * @param string $environment    `sandbox` / `production` — internal label
     *                               only; BOG publishes one host pair.
     * @param string $pluginSlug     Consumer plugin slug
     *                               (`codeon-bog-card-payment`, etc.).
     *                               Scopes the token cache key, lock group,
     *                               log channel, and User-Agent so two
     *                               co-installed CodeOn payment plugins
     *                               never collide.
     * @param string $pluginVersion  Consumer plugin version — embedded in
     *                               the User-Agent for BOG-side debugging.
     * @param Logger $logger         Framework logger pre-scoped to the
     *                               consumer plugin slug.
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $environment,
        private readonly string $pluginSlug,
        private readonly string $pluginVersion,
        private readonly Logger $logger,
    ) {
    }

    public function environment(): string
    {
        return in_array($this->environment, [self::ENV_SANDBOX, self::ENV_PRODUCTION], true)
            ? $this->environment
            : self::ENV_SANDBOX;
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    // ─── Payments API ────────────────────────────────────────────────

    /**
     * Create an e-commerce order. Body shape is product-agnostic; the
     * caller decides whether `payment_method` is `["card"]`, `["bog_loan"]`,
     * `["bnpl"]`, etc. and supplies any product-specific config (e.g.
     * `config.loan` for installments / BNPL).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function createOrder(array $args): array
    {
        return $this->request('POST', self::PAYMENTS_BASE, '/payments/v1/ecommerce/orders', $args);
    }

    /** @return array<string,mixed> */
    public function getReceipt(string $orderId): array
    {
        return $this->request(
            'GET',
            self::PAYMENTS_BASE,
            '/payments/v1/receipt/' . rawurlencode($orderId),
            null,
        );
    }

    /**
     * Refund a captured payment. Pass `$amount = null` for a full refund.
     *
     * BOG's docs say refunds support card / Apple Pay / Google Pay / BOG
     * authorisation only — `bog_loan` / `bnpl` orders typically return
     * an error here. The caller surfaces BOG's verbatim error to the
     * WC admin via `WP_Error`.
     *
     * @return array<string,mixed>
     */
    public function refund(string $orderId, ?float $amount = null, string $currency = 'GEL'): array
    {
        $body = [];
        if ($amount !== null) {
            $body['amount'] = self::formatAmountForApi($amount, $currency);
        }
        return $this->request(
            'POST',
            self::PAYMENTS_BASE,
            '/payments/v1/payment/refund/' . rawurlencode($orderId),
            $body,
        );
    }

    /**
     * Mark an existing order as eligible for "save card" — BOG will then
     * offer the customer a save-card checkbox on the hosted page, and
     * (if accepted) make the resulting card available for subsequent
     * recurring / saved-card payments tied to this order.
     *
     * Endpoint: `PUT /payments/v1/orders/{order_id}/cards`. Returns
     * `202 Accepted` on success. Per BOG's docs, this MUST be called
     * BEFORE redirecting the customer to the payment page, not after.
     *
     * Idempotent on BOG's side via `Idempotency-Key` (UUID v4) — the
     * `request()` helper already injects `X-Request-ID` per call, but
     * `Idempotency-Key` is a separate slot the bank treats specially
     * for retries.
     *
     * @return array<string,mixed>
     */
    public function enableSaveCard(string $orderId, ?string $idempotencyKey = null): array
    {
        return $this->request(
            'PUT',
            self::PAYMENTS_BASE,
            '/payments/v1/orders/' . rawurlencode($orderId) . '/cards',
            null,
            $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey],
        );
    }

    /**
     * Complete or cancel a pre-authorised payment.
     *
     *   - POST   /payments/v1/payment/{order_id}  → capture (full or partial via amount)
     *   - DELETE /payments/v1/payment/{order_id}  → void / cancel pre-auth
     *
     * @param string     $authType  FULL_COMPLETE | PARTIAL_COMPLETE | CANCEL
     * @param null|float $amount    Required when $authType === PARTIAL_COMPLETE
     * @return array<string,mixed>
     */
    public function completePreauth(string $orderId, string $authType, ?float $amount = null, string $currency = 'GEL'): array
    {
        $path = '/payments/v1/payment/' . rawurlencode($orderId);

        if ($authType === 'CANCEL') {
            return $this->request('DELETE', self::PAYMENTS_BASE, $path, null);
        }

        $body = null;
        if ($authType === 'PARTIAL_COMPLETE' && $amount !== null) {
            $body = ['amount' => self::formatAmountForApi($amount, $currency)];
        }

        return $this->request('POST', self::PAYMENTS_BASE, $path, $body);
    }

    /**
     * Format a float for a BOG JSON body. Casting `(string) $amount`
     * directly leaks float precision noise (e.g. "12.300000000000004").
     * `number_format` with the currency's minor-unit precision is the
     * only safe path. (HARDENING.md §5.)
     */
    public static function formatAmountForApi(float $amount, string $currency): string
    {
        $rounded = Money::toApiDecimal($amount, $currency);
        // GEL/USD/EUR/GBP all 2dp today; if BOG ships a 3dp currency
        // this grows a Money::decimals() lookup.
        return number_format($rounded, 2, '.', '');
    }

    // ─── Authentication ──────────────────────────────────────────────

    public function accessToken(): string
    {
        $cacheKey = $this->tokenCacheKey();
        $cached = get_transient($cacheKey);
        if (is_array($cached) && isset($cached['token'], $cached['expires_at']) && $cached['expires_at'] > time()) {
            return (string) $cached['token'];
        }

        // Single-flight: only one PHP worker refreshes the token at a
        // time. Without this, every concurrent checkout that lands at
        // the expiry window slams BOG's OAuth endpoint and risks a
        // rate-limit lockout for the whole site.
        $lockKey  = $cacheKey . '_lock';
        $lockGroup = $this->lockGroup();
        $haveLock = wp_cache_add($lockKey, 1, $lockGroup, self::TOKEN_LOCK_TTL_S);

        if (!$haveLock) {
            // Someone else is refreshing — small backoff, then re-read.
            // If the refresher won we'll find a fresh token; if it
            // crashed we'll fall through and refresh ourselves.
            usleep(150_000);
            $retry = get_transient($cacheKey);
            if (is_array($retry) && isset($retry['token'], $retry['expires_at']) && $retry['expires_at'] > time()) {
                return (string) $retry['token'];
            }
        }

        try {
            $credentials = base64_encode($this->clientId . ':' . $this->clientSecret);

            $response = wp_remote_post(self::OAUTH_BASE . self::OAUTH_PATH, [
                'timeout'   => self::TIMEOUT,
                'sslverify' => true,
                'headers'   => [
                    'Authorization' => 'Basic ' . $credentials,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Accept'        => 'application/json',
                    'User-Agent'    => $this->userAgent(),
                ],
                'body'      => http_build_query(['grant_type' => 'client_credentials']),
            ]);

            if (is_wp_error($response)) {
                throw new ApiException(
                    'BOG access-token transport error: ' . $response->get_error_message(),
                    0,
                    [],
                    'transport_error',
                );
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body   = json_decode((string) wp_remote_retrieve_body($response), true);

            if ($status < 200 || $status >= 300 || !is_array($body) || empty($body['access_token'])) {
                $bodySafe = is_array($body) ? $body : [];
                $this->logger->warning('BOG access-token failed', [
                    'status' => $status,
                    'error'  => $bodySafe['error'] ?? 'unknown',
                ]);
                throw new ApiException('BOG access-token failed', $status, $bodySafe);
            }

            $ttl = max(60, (int) ($body['expires_in'] ?? 3600) - self::TOKEN_TTL_SAFETY_S);
            set_transient(
                $cacheKey,
                ['token' => (string) $body['access_token'], 'expires_at' => time() + $ttl],
                $ttl,
            );

            return (string) $body['access_token'];
        } finally {
            if ($haveLock) {
                wp_cache_delete($lockKey, $lockGroup);
            }
        }
    }

    public function clearCachedToken(): void
    {
        delete_transient($this->tokenCacheKey());
    }

    private function tokenCacheKey(): string
    {
        return 'codeon_bog_token_' . md5(
            $this->pluginSlug . '|' . $this->clientId . '|' . $this->environment()
        );
    }

    private function lockGroup(): string
    {
        // Object-cache groups are usually limited to ~64 chars; the slug
        // is well within that and uniquely scopes per-plugin locking.
        return $this->pluginSlug;
    }

    private function userAgent(): string
    {
        return 'Codeon/' . $this->pluginVersion . ' (' . $this->pluginSlug . ')';
    }

    // ─── Internals ───────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|object|null $body
     * @param array<string,string>            $extraHeaders
     * @return array<string,mixed>
     */
    private function request(string $method, string $base, string $path, mixed $body, array $extraHeaders = []): array
    {
        $token = $this->accessToken();

        $args = [
            'method'    => $method,
            'timeout'   => self::TIMEOUT,
            'sslverify' => true,
            'headers'   => array_merge([
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'X-Request-ID'  => wp_generate_uuid4(),
                'User-Agent'    => $this->userAgent(),
            ], $extraHeaders),
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($base . $path, $args);

        if (is_wp_error($response)) {
            $this->logger->warning('BOG transport error', [
                'path'  => $path,
                'error' => $response->get_error_message(),
            ]);
            throw new ApiException(
                'BOG transport error: ' . $response->get_error_message(),
                0,
                [],
                'transport_error',
            );
        }

        $status  = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $decoded = $rawBody === '' ? [] : json_decode($rawBody, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $ok      = $status >= 200 && $status < 300;

        $this->logger->debug('BOG request', [
            'method' => $method,
            'path'   => $path,
            'status' => $status,
            'ok'     => $ok,
        ]);

        if (!$ok) {
            if ($status === 401) {
                $this->clearCachedToken();
            }
            $detail = (string) (
                $decoded['detail']
                ?? $decoded['message']
                ?? $decoded['error_description']
                ?? $decoded['title']
                ?? 'http_' . $status
            );
            throw new ApiException('BOG API error: ' . $detail, $status, $decoded);
        }

        return $decoded;
    }
}
