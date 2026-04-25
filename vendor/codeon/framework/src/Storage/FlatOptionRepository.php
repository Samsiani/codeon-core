<?php

declare(strict_types=1);

namespace CodeOn\Framework\Storage;

/**
 * Stores all settings in a single wp_option as a flat associative array.
 *
 * This is the simplest adapter and the one fina-sync uses (`fina_sync_settings`).
 * Top-level keys only — no nesting. The bytes-on-disk are a `serialize()` of an
 * associative array whose keys come straight from {@see \CodeOn\Framework\Schema\Field::$path}.
 *
 * Writes are buffered in memory until {@see flush()} so the entire form save is
 * one atomic update_option call.
 */
final class FlatOptionRepository implements SettingsRepository
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;
    /** @var array<string,mixed> */
    private array $buffer = [];

    public function __construct(
        private readonly string $optionName,
        /** @var array<string,mixed> */
        private readonly array $defaults = []
    ) {
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $data = $this->load();
        if (array_key_exists($path, $this->buffer)) {
            return $this->buffer[$path];
        }
        if (array_key_exists($path, $data)) {
            return $data[$path];
        }
        if (array_key_exists($path, $this->defaults)) {
            return $this->defaults[$path];
        }
        return $default;
    }

    public function set(string $path, mixed $value): void
    {
        $this->buffer[$path] = $value;
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $data = array_merge($this->load(), $this->buffer);
        update_option($this->optionName, $data, false);
        $this->cache = $data;
        $this->buffer = [];
    }

    public function optionName(): string
    {
        return $this->optionName;
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $raw = get_option($this->optionName, []);
        $this->cache = is_array($raw) ? $raw : [];
        return $this->cache;
    }
}
