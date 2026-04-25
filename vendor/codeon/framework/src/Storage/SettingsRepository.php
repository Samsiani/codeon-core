<?php

declare(strict_types=1);

namespace CodeOn\Framework\Storage;

/**
 * The framework's storage contract.
 *
 * One method per concern, three total. Every adapter implementation must
 * round-trip values losslessly — same input, same output, regardless of how
 * the bytes are physically stored (single option blob, dot-pathed tree,
 * WC gateway settings, or split across multiple keys).
 *
 * The framework never writes to wp_options directly. All persistence flows
 * through this interface so the same {@see \CodeOn\Framework\Schema\Field}
 * schema can run against any storage layout the host plugin chose.
 */
interface SettingsRepository
{
    /**
     * Read a value by path. Path semantics depend on the adapter:
     *  - FlatOptionRepository → top-level array key
     *  - NestedDotPathRepository → 'a.b.c' walks nested arrays
     *  - WCGatewayRepository → maps to gateway form_fields key
     *  - SplitOptionRepository → first segment routes to a sub-adapter
     */
    public function get(string $path, mixed $default = null): mixed;

    /**
     * Write a value by path. May buffer until {@see flush()} is called.
     */
    public function set(string $path, mixed $value): void;

    /**
     * Persist any buffered writes to the underlying store. Adapters that
     * write eagerly may make this a no-op.
     */
    public function flush(): void;
}
