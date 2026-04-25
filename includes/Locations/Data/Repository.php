<?php
/**
 * Locations dataset repository — single in-process source of truth.
 *
 * Loads /data/locations.php exactly once per request. The file is a pure
 * PHP literal (var_export output), so opcache caches the compiled
 * representation: there is no JSON parsing or file I/O on subsequent
 * requests, only an opcached require.
 *
 * All accessors return raw arrays (matching the bundle schema). Display
 * formatting (Georgian / Latin / Bilingual) lives in {@see DisplayFormatter}
 * — Repository is intentionally locale-agnostic so it can be reused by
 * REST, checkout JS bootstrap, settings UI, etc.
 *
 * @package CodeOn\Core\Locations\Data
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\Data;

final class Repository
{
    private static ?Repository $instance = null;

    /** @var array{_meta: array<string,mixed>, regions: array<int, array<string,mixed>>} */
    private array $bundle;

    /** @var array<string, array<string,mixed>> region_id → region row */
    private array $regionsById = [];

    /** @var array<string, array<string,mixed>> mun_id → mun row (with region_id added) */
    private array $munsById = [];

    /** @var array<int, array<string,mixed>> settlement_id → settlement row (with mun_id, region_id) */
    private array $settlementsById = [];

    private function __construct()
    {
        $bundlePath = CODEON_CORE_PATH . 'data/locations.php';
        if (!is_readable($bundlePath)) {
            throw new \RuntimeException(
                sprintf('CodeOn Core: data bundle missing at %s. Run build/sync-from-georgian-data.php.', $bundlePath)
            );
        }
        /** @var array{_meta: array<string,mixed>, regions: array<int, array<string,mixed>>} $data */
        $data = require $bundlePath;
        $this->bundle = $data;
        $this->buildIndexes();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Test-only — flush singleton state. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return $this->bundle['_meta'];
    }

    /**
     * @param bool $includeOccupied  Set false to filter out occupied territories.
     * @return array<int, array<string,mixed>>
     */
    public function regions(bool $includeOccupied = true): array
    {
        if ($includeOccupied) {
            return $this->bundle['regions'];
        }
        return array_values(array_filter(
            $this->bundle['regions'],
            static fn (array $r): bool => !$r['occupied']
        ));
    }

    public function region(string $id): ?array
    {
        return $this->regionsById[$id] ?? null;
    }

    public function regionByWcCode(string $code): ?array
    {
        foreach ($this->bundle['regions'] as $region) {
            if ($region['wc_state_code'] === $code) {
                return $region;
            }
        }
        return null;
    }

    /**
     * @return array<int, array<string,mixed>> Municipalities of a region (without nested settlements).
     */
    public function municipalitiesOf(string $regionId, bool $includeOccupied = true): array
    {
        $region = $this->regionsById[$regionId] ?? null;
        if ($region === null) {
            return [];
        }
        $out = [];
        foreach ($region['municipalities'] as $m) {
            if (!$includeOccupied && $m['occupied']) {
                continue;
            }
            // Drop the nested settlements array — caller fetches via settlementsOf.
            $copy = $m;
            unset($copy['settlements']);
            $copy['settlement_count'] = count($m['settlements']);
            $out[] = $copy;
        }
        return $out;
    }

    public function municipality(string $id): ?array
    {
        return $this->munsById[$id] ?? null;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function settlementsOf(string $municipalityId): array
    {
        $mun = $this->munsById[$municipalityId] ?? null;
        if ($mun === null) {
            return [];
        }
        return $mun['settlements'];
    }

    public function settlement(int $id): ?array
    {
        return $this->settlementsById[$id] ?? null;
    }

    /**
     * Substring search across every settlement (case-insensitive on Georgian).
     *
     * @return array<int, array<string,mixed>>
     */
    public function searchSettlements(string $query, int $limit = 20, bool $includeOccupied = true): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $needle = mb_strtolower($query);
        $results = [];
        foreach ($this->settlementsById as $s) {
            if (count($results) >= $limit) {
                break;
            }
            if (!$includeOccupied) {
                $mun = $this->munsById[$s['municipality_id']];
                if ($mun['occupied']) {
                    continue;
                }
            }
            if (mb_strpos(mb_strtolower($s['name_ka']), $needle) !== false) {
                $results[] = $s;
            }
        }
        return $results;
    }

    private function buildIndexes(): void
    {
        foreach ($this->bundle['regions'] as $region) {
            $this->regionsById[$region['id']] = $region;
            foreach ($region['municipalities'] as $mun) {
                $munCopy = $mun;
                $munCopy['region_id'] = $region['id'];
                // Decorate each settlement with mun_id + region_id once,
                // so accessors don't need to do the join repeatedly.
                $decorated = [];
                foreach ($mun['settlements'] as $s) {
                    $sCopy = $s;
                    $sCopy['municipality_id'] = $mun['id'];
                    $sCopy['region_id']       = $region['id'];
                    $decorated[] = $sCopy;
                    $this->settlementsById[(int) $s['id']] = $sCopy;
                }
                $munCopy['settlements'] = $decorated;
                $this->munsById[$mun['id']] = $munCopy;
            }
        }
    }
}
