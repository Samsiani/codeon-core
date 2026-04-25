<?php

declare(strict_types=1);

namespace CodeOn\Framework\Extensions;

/**
 * Typed value object for the parsed catalog response.
 *
 * Lives separate from {@see CatalogClient} so render code can
 * type-hint against the structure without smuggling raw arrays
 * through the call graph. Two-way (de)serialisation through
 * {@see fromArray()} / {@see toArray()} keeps the transient
 * payload small and shape-stable.
 */
final class Catalog
{
    /**
     * @param array<int, CatalogCategory> $categories
     * @param array<int, CatalogPlugin>   $plugins
     */
    public function __construct(
        public readonly string $version,
        public readonly string $fetchedAt,
        public readonly array $categories,
        public readonly array $plugins,
    ) {
    }

    public function findByPluginSlug(string $slug): ?CatalogPlugin
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->pluginSlug === $slug) {
                return $plugin;
            }
        }
        return null;
    }

    public function findByPluginId(string $id): ?CatalogPlugin
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->pluginId === $id) {
                return $plugin;
            }
        }
        return null;
    }

    /** @return array<int, CatalogPlugin> */
    public function pluginsInCategory(string $category): array
    {
        return array_values(array_filter(
            $this->plugins,
            static fn (CatalogPlugin $p) => $p->category === $category
        ));
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $categories = [];
        foreach ((array) ($data['categories'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $categories[] = new CatalogCategory(
                id: (string) ($row['id'] ?? ''),
                label: (string) ($row['label'] ?? ''),
                iconKey: (string) ($row['iconKey'] ?? ''),
            );
        }

        $plugins = [];
        foreach ((array) ($data['plugins'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $plugins[] = CatalogPlugin::fromArray($row);
        }

        return new self(
            version: (string) ($data['version'] ?? ''),
            fetchedAt: (string) ($data['fetchedAt'] ?? ''),
            categories: $categories,
            plugins: $plugins,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'version'    => $this->version,
            'fetchedAt'  => $this->fetchedAt,
            'categories' => array_map(static fn (CatalogCategory $c) => [
                'id'      => $c->id,
                'label'   => $c->label,
                'iconKey' => $c->iconKey,
            ], $this->categories),
            'plugins'    => array_map(static fn (CatalogPlugin $p) => $p->toArray(), $this->plugins),
        ];
    }
}

/** @internal */
final class CatalogCategory
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $iconKey,
    ) {
    }
}

/** @internal */
final class CatalogPlugin
{
    /**
     * @param array<int, CatalogProduct> $products
     * @param array{php:string, wp:string, wc:?string} $requirements
     */
    public function __construct(
        public readonly string $pluginSlug,
        public readonly string $pluginId,
        public readonly string $name,
        public readonly string $tagline,
        public readonly string $description,
        public readonly string $category,
        public readonly string $iconKey,
        public readonly ?string $iconUrl,
        public readonly ?string $bannerUrl,
        public readonly string $productUrl,
        public readonly ?string $currentVersion,
        public readonly bool $popular,
        public readonly array $requirements,
        public readonly array $products,
    ) {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $products = [];
        foreach ((array) ($data['products'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $products[] = new CatalogProduct(
                id: (string) ($row['id'] ?? ''),
                name: (string) ($row['name'] ?? ''),
                sub: isset($row['sub']) ? (string) $row['sub'] : null,
                slug: (string) ($row['slug'] ?? ''),
                priceTetri: (int) ($row['priceTetri'] ?? 0),
                currency: (string) ($row['currency'] ?? 'GEL'),
                bank: isset($row['bank']) ? (string) $row['bank'] : null,
            );
        }

        $requirements = (array) ($data['requirements'] ?? []);
        return new self(
            pluginSlug: (string) ($data['pluginSlug'] ?? ''),
            pluginId: (string) ($data['pluginId'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            tagline: (string) ($data['tagline'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            category: (string) ($data['category'] ?? ''),
            iconKey: (string) ($data['iconKey'] ?? ''),
            iconUrl: isset($data['iconUrl']) ? (string) $data['iconUrl'] : null,
            bannerUrl: isset($data['bannerUrl']) ? (string) $data['bannerUrl'] : null,
            productUrl: (string) ($data['productUrl'] ?? ''),
            currentVersion: isset($data['currentVersion']) ? (string) $data['currentVersion'] : null,
            popular: (bool) ($data['popular'] ?? false),
            requirements: [
                'php' => (string) ($requirements['php'] ?? ''),
                'wp'  => (string) ($requirements['wp'] ?? ''),
                'wc'  => isset($requirements['wc']) ? (string) $requirements['wc'] : null,
            ],
            products: $products,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'pluginSlug'     => $this->pluginSlug,
            'pluginId'       => $this->pluginId,
            'name'           => $this->name,
            'tagline'        => $this->tagline,
            'description'    => $this->description,
            'category'       => $this->category,
            'iconKey'        => $this->iconKey,
            'iconUrl'        => $this->iconUrl,
            'bannerUrl'      => $this->bannerUrl,
            'productUrl'     => $this->productUrl,
            'currentVersion' => $this->currentVersion,
            'popular'        => $this->popular,
            'requirements'   => $this->requirements,
            'products'       => array_map(static fn (CatalogProduct $p) => [
                'id'         => $p->id,
                'name'       => $p->name,
                'sub'        => $p->sub,
                'slug'       => $p->slug,
                'priceTetri' => $p->priceTetri,
                'currency'   => $p->currency,
                'bank'       => $p->bank,
            ], $this->products),
        ];
    }
}

/** @internal */
final class CatalogProduct
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $sub,
        public readonly string $slug,
        public readonly int $priceTetri,
        public readonly string $currency,
        public readonly ?string $bank,
    ) {
    }

    public function priceGel(): float
    {
        return $this->priceTetri / 100;
    }

    public function displayLabel(): string
    {
        return $this->sub !== null && $this->sub !== ''
            ? $this->name . ' · ' . $this->sub
            : $this->name;
    }
}
