<?php

declare(strict_types=1);

namespace CodeOn\Framework\Admin;

use CodeOn\Framework\Schema\Field;
use CodeOn\Framework\Storage\SettingsRepository;

/**
 * Abstract base for every admin tab.
 *
 * Default flow: a tab declares its {@see schema()} (a Field[] array) and a
 * {@see repository()}; the framework renders the form, dispatches the save
 * to {@see \CodeOn\Framework\Schema\FieldValidator}, persists, and shows
 * success/error notices. Plugins write zero HTML.
 *
 * Subclasses may override {@see render()} for custom views (diagnostics,
 * events, dashboards) — the page chrome (header, tab nav, footer) is still
 * emitted by {@see Page} so the visual stays consistent regardless of
 * what's inside.
 *
 * Special non-save buttons (test connection, rotate secret, etc.) are
 * declared via {@see actions()}; the framework dispatches by the
 * `codeon_action` POST value.
 */
abstract class Tab
{
    abstract public function slug(): string;

    abstract public function label(): string;

    /**
     * Optional health tone for the tab's nav dot ('ok' / 'warn' / 'err' / null).
     */
    public function dotTone(): ?string
    {
        return null;
    }

    /**
     * @return Field[]
     */
    public function schema(): array
    {
        return [];
    }

    public function repository(): ?SettingsRepository
    {
        return null;
    }

    /**
     * @return array<string,callable(array<string,mixed>):void>  action_name → handler
     */
    public function actions(): array
    {
        return [];
    }

    /**
     * Hook before the validated payload is persisted. Mutate / strip / add
     * keys as needed; return the payload that should actually be saved.
     *
     * @param array<string,mixed> $clean
     * @return array<string,mixed>
     */
    public function beforeSave(array $clean): array
    {
        return $clean;
    }

    /**
     * Hook after persistence — useful for re-scheduling crons, clearing
     * caches, sending heartbeats.
     *
     * @param array<string,mixed> $saved
     */
    public function afterSave(array $saved): void
    {
    }

    /**
     * Plugin slug, set by {@see Page::render()} before invoking
     * {@see render()}. Read by the default schema-walking impl + by
     * {@see LicenseTab::render()} so the multi-plugin form
     * discriminator (`codeon_plugin_slug` hidden field) carries the
     * actual `Manifest::slug` rather than a value derived by stripping
     * the `codeon_admin_` prefix from the nonce action — a
     * derivation that silently produced the wrong slug for plugins
     * that override `Manifest::nonce()` and broke their Save /
     * License-tab forms (admin-post.php returned an empty page).
     *
     * This is a setter on the base class rather than an extra
     * parameter on `render()` because subclass overrides of
     * `render(string $nonceAction): void` already exist in the wild
     * (DashboardTab, SettingsTab, etc.) and PHP's covariance rules
     * reject an override with a different parameter count even when
     * the new parameter has a default value.
     *
     * v0.3.7+.
     */
    protected string $pluginSlug = '';

    public function setPluginSlug(string $slug): void
    {
        $this->pluginSlug = $slug;
    }

    /**
     * Default render: walk the schema. Override for custom UI.
     */
    public function render(string $nonceAction): void
    {
        \CodeOn\Framework\Schema\FieldRenderer::renderForm(
            $this->schema(),
            $this->repository(),
            $this->slug(),
            $nonceAction,
            '',
            $this->pluginSlug
        );
    }
}
