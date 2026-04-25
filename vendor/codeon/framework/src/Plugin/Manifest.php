<?php

declare(strict_types=1);

namespace CodeOn\Framework\Plugin;

use Closure;
use CodeOn\Framework\Admin\HealthCard;

/**
 * Configuration object every plugin builds and hands to {@see Bootstrap::register()}.
 *
 * Mostly mutable in the build phase (chained setters), then read-only at
 * runtime. Carries everything the framework needs to wire up the menu, the
 * page chrome, the asset enqueue predicate, and the recovery-mode rules.
 */
final class Manifest
{
    public string $iconUrl = '';
    public string $iconDashicon = '';
    public int $menuPosition = 58;
    public string $capability = 'manage_options';
    public string $version = '';
    public string $supportUrl = '';
    public string $nonceAction = '';

    /**
     * Hub-mode flag. When true, the plugin's menu is merged into the
     * shared CodeOn top-level under {@see HubRegistry::TOP_LEVEL_SLUG};
     * when false (default for back-compat) the plugin keeps its own
     * top-level menu via the legacy path. New plugins should opt in.
     */
    public bool $useHub = false;

    /**
     * Hub group identifier — defaults to 'codeon'. Lets future
     * groups (e.g. a separate 'codeon-storefront' suite) coexist
     * under different top-level menus without code changes.
     */
    public string $hubGroup = 'codeon';

    /**
     * Short label shown in the hub submenu. Falls back to
     * {@see $menuTitle} when unset, matching the most common case.
     */
    public string $hubLabel = '';

    /** @var Closure(): HealthCard|null */
    private ?Closure $headerStatusCardFactory = null;

    /** @var array<int,Closure():HealthCard> */
    private array $dashboardCardFactories = [];

    /** @var array<int,string> */
    private array $hookSuffixes = [];

    public function __construct(
        public readonly string $slug,
        public readonly string $menuTitle,
    ) {
    }

    public function capability(string $cap): self
    {
        $this->capability = $cap;
        return $this;
    }

    public function icon(string $url): self
    {
        $this->iconUrl = $url;
        return $this;
    }

    public function dashicon(string $name): self
    {
        $this->iconDashicon = $name;
        return $this;
    }

    public function position(int $pos): self
    {
        $this->menuPosition = $pos;
        return $this;
    }

    public function version(string $v): self
    {
        $this->version = $v;
        return $this;
    }

    public function support(string $url): self
    {
        $this->supportUrl = $url;
        return $this;
    }

    /**
     * Override the nonce action used for the framework's save POSTs.
     *
     * Set this to the plugin's existing nonce action when migrating an
     * older plugin so existing merchant bookmarks stay valid (see
     * codeon-payments using `codeon_payments_admin`).
     */
    public function nonce(string $action): self
    {
        $this->nonceAction = $action;
        return $this;
    }

    /**
     * Opt this plugin's admin menu into the shared CodeOn hub. When
     * enabled the plugin renders as a submenu under
     * {@see HubRegistry::TOP_LEVEL_SLUG} instead of its own top-level
     * entry. Default off so existing v0.1.x consumers keep their
     * current menu behaviour after a framework upgrade.
     */
    public function hub(bool $enabled = true): self
    {
        $this->useHub = $enabled;
        return $this;
    }

    /**
     * Override the hub group when the plugin should land under a
     * different shared top-level (rare). Defaults to 'codeon'.
     */
    public function hubGroup(string $group): self
    {
        $this->hubGroup = $group;
        return $this;
    }

    /**
     * Override the submenu label shown in the hub. Falls back to
     * {@see $menuTitle} when unset.
     */
    public function hubLabel(string $label): self
    {
        $this->hubLabel = $label;
        return $this;
    }

    public function resolveHubLabel(): string
    {
        return $this->hubLabel !== '' ? $this->hubLabel : $this->menuTitle;
    }

    /** @param Closure():HealthCard $factory */
    public function headerStatusCard(Closure $factory): self
    {
        $this->headerStatusCardFactory = $factory;
        return $this;
    }

    /** @param array<int,Closure():HealthCard> $factories */
    public function dashboardCards(array $factories): self
    {
        $this->dashboardCardFactories = $factories;
        return $this;
    }

    public function rememberHookSuffix(string $hookSuffix): void
    {
        if ($hookSuffix !== '' && !in_array($hookSuffix, $this->hookSuffixes, true)) {
            $this->hookSuffixes[] = $hookSuffix;
        }
    }

    /** @return array<int,string> */
    public function hookSuffixes(): array
    {
        return $this->hookSuffixes;
    }

    public function resolveHeaderStatusCard(): ?HealthCard
    {
        if ($this->headerStatusCardFactory === null) {
            return null;
        }
        return ($this->headerStatusCardFactory)();
    }

    /** @return HealthCard[] */
    public function resolveDashboardCards(): array
    {
        return array_map(
            static fn (Closure $f) => $f(),
            $this->dashboardCardFactories
        );
    }
}
