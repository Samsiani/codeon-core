<?php

declare(strict_types=1);

namespace CodeOn\Framework\Logging;

/**
 * Lightweight WC-aware logger shared across CodeOn plugins.
 *
 * Today fina-sync and quickshipper-delivery ship byte-identical
 * `Logger` classes. This is the framework version they consolidate
 * onto. The class delegates to {@see \WC_Logger} when WooCommerce is
 * active, and falls through to PHP's `error_log()` otherwise so
 * the legacy admin pages of plugins that wrap WP only (no WC) still
 * leave a trail.
 *
 * Sensitive context redaction is opt-in: pass an array of keys to
 * the constructor and any matching values are replaced with a
 * masked placeholder before they hit the log.
 */
final class Logger
{
    private static ?\WC_Logger $wcLogger = null;

    /**
     * @param string $source            WC log source, typically the plugin slug.
     * @param array<int,string> $redact Keys whose values should be masked
     *                                  before being written to the log.
     */
    public function __construct(
        private readonly string $source,
        private readonly array $redact = ['license_key', 'password', 'authorization', 'token', 'secret'],
    ) {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->write('critical', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = $message;
        if ($context !== []) {
            $line .= ' ' . wp_json_encode($this->redactContext($context));
        }

        if (function_exists('wc_get_logger')) {
            self::$wcLogger ??= wc_get_logger();
            self::$wcLogger->log($level, $line, ['source' => $this->source]);
            return;
        }

        // Pre-WC fallback. Drops debug to suppress noise.
        if ($level === 'debug') {
            return;
        }
        error_log(sprintf('[%s][%s] %s', $this->source, strtoupper($level), $line));
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function redactContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $this->redact, true)) {
                $clean[$key] = $this->mask((string) $value);
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->redactContext($value);
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    private function mask(string $value): string
    {
        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }
        return substr($value, 0, 4) . '…' . substr($value, -4);
    }
}
