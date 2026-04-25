<?php

declare(strict_types=1);

namespace CodeOn\Framework\Extensions;

/**
 * HTTP client + cache for the public CodeOn plugin catalog.
 *
 * Backs the Extensions tab. Reads from
 * `https://codeon.ge/api/v1/catalog`, parses the v1 response shape,
 * and caches the parsed payload in a transient. The "Refresh" button
 * busts the transient; an HTTP failure falls back to whatever the
 * transient last held (with a warning surfaced to the merchant).
 *
 * The endpoint itself is edge-cached for ~5 minutes on the codeon.ge
 * side, so the worst-case total staleness is approximately the
 * transient TTL plus that — fine for a catalog that updates once or
 * twice a quarter when a new plugin ships.
 */
final class CatalogClient
{
    public const TRANSIENT_KEY = 'codeon_framework_catalog_v1';
    public const ENDPOINT = 'https://codeon.ge/api/v1/catalog';
    public const TTL = 6 * HOUR_IN_SECONDS;

    /**
     * Fetch the catalog. Falls through cache → HTTP → stale-cache
     * fallback. Returns `null` only when both the cache is empty
     * AND the HTTP call fails — at that point the UI shows an empty
     * state with a "try again" button rather than crash.
     */
    public function fetch(bool $force = false): ?Catalog
    {
        if (!$force) {
            $cached = $this->cached();
            if ($cached !== null) {
                return $cached;
            }
        }

        $fresh = $this->fetchRemote();
        if ($fresh !== null) {
            set_transient(self::TRANSIENT_KEY, $fresh->toArray(), self::TTL);
            return $fresh;
        }

        // HTTP failed; surface whatever transient still has, even if
        // it's past TTL — get_transient returns false on expiry, so
        // we read the option directly to recover stale data.
        $stale = get_option('_transient_' . self::TRANSIENT_KEY);
        if (is_array($stale)) {
            return Catalog::fromArray($stale);
        }
        return null;
    }

    /** Cached lookup with no HTTP fallback — used to render quickly. */
    public function cached(): ?Catalog
    {
        $raw = get_transient(self::TRANSIENT_KEY);
        if (!is_array($raw)) {
            return null;
        }
        return Catalog::fromArray($raw);
    }

    /** Wipe the cache; the next `fetch()` re-hits the endpoint. */
    public function flush(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    private function fetchRemote(): ?Catalog
    {
        $url = add_query_arg(
            [
                'wp_version'  => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'wc_version'  => defined('WC_VERSION') ? WC_VERSION : '',
                'site_url'    => home_url(),
            ],
            self::ENDPOINT
        );

        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'CodeOnFramework/' . $this->frameworkVersion(),
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('[CodeOn] CatalogClient HTTP error: ' . $response->get_error_message());
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log(sprintf('[CodeOn] CatalogClient HTTP %d from %s', $code, self::ENDPOINT));
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            error_log('[CodeOn] CatalogClient JSON decode failed.');
            return null;
        }

        $version = isset($decoded['version']) ? (string) $decoded['version'] : '';
        if ($version !== '1') {
            error_log(sprintf(
                '[CodeOn] CatalogClient unsupported schema version "%s" — upgrade the framework.',
                $version
            ));
            return null;
        }

        return Catalog::fromArray($decoded);
    }

    private function frameworkVersion(): string
    {
        // The framework's composer.json doesn't expose a runtime
        // version constant; embed a stable token until we add one.
        return 'v0.2.0';
    }
}
