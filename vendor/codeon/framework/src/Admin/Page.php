<?php

declare(strict_types=1);

namespace CodeOn\Framework\Admin;

use CodeOn\Framework\Http\NonceGate;
use CodeOn\Framework\Plugin\Manifest;
use CodeOn\Framework\Schema\FieldValidator;

/**
 * One top-level admin page that hosts a stack of {@see Tab}s.
 *
 * Owns the render loop: chrome (header + tab nav + footer) wraps whatever
 * the active tab outputs. Owns the save dispatcher: a single
 * `admin_post_codeon_save_tab` handler routes the POST into the right
 * tab's schema, runs validation, persists, redirects with a flash.
 *
 * A plugin doesn't subclass Page — it just hands a Manifest + an array
 * of Tab instances to {@see \CodeOn\Framework\Plugin\Bootstrap::register()}.
 */
final class Page
{
    public const SAVE_ACTION = 'codeon_save_tab';

    /**
     * @param Tab[] $tabs
     */
    public function __construct(
        private readonly Manifest $manifest,
        private readonly array $tabs,
    ) {
    }

    public function nonceAction(): string
    {
        // Allow plugins to override (codeon-payments uses 'codeon_payments_admin'
        // for backwards-compat with merchant bookmarks).
        return $this->manifest->nonceAction !== ''
            ? $this->manifest->nonceAction
            : 'codeon_admin_' . $this->manifest->slug;
    }

    public function registerHooks(): void
    {
        // Save / per-tab action endpoints are global (not menu-bound)
        // so they always register. Menu registration is conditional —
        // hub-mode plugins delegate to HubRegistry, legacy plugins
        // keep their own top-level entry.
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('admin_post_codeon_tab_action', [$this, 'handleTabAction']);

        if (!$this->manifest->useHub) {
            add_action('admin_menu', [$this, 'registerOwnTopLevel']);
        }
        // Hub-mode plugins are added by `Bootstrap::register()` via
        // `HubRegistry::register($manifest, $page)`, which hooks
        // admin_menu at priority 8 and emits one add_submenu_page per
        // registered plugin under a single shared top-level entry.
    }

    /**
     * Legacy path: plugin owns its own top-level menu. Used when
     * {@see Manifest::hub()} is left at its default (false).
     */
    public function registerOwnTopLevel(): void
    {
        $hookSuffix = add_menu_page(
            $this->manifest->menuTitle,
            $this->manifest->menuTitle,
            $this->manifest->capability,
            $this->manifest->slug,
            [$this, 'render'],
            $this->manifest->iconDashicon !== '' ? $this->manifest->iconDashicon : 'dashicons-admin-generic',
            $this->manifest->menuPosition
        );

        // Mark this hook suffix on the manifest so Assets::isCodeonPage()
        // can recognise it on subsequent requests.
        if (is_string($hookSuffix) && $hookSuffix !== '') {
            $this->manifest->rememberHookSuffix($hookSuffix);
        }
    }

    /**
     * Back-compat alias for the legacy {@see registerMenu()} call.
     * External callers (tests, custom integrations) that referenced
     * the old name keep working after the hub-mode split.
     *
     * @deprecated Use registerOwnTopLevel(). Hub-mode plugins must
     * not call either — HubRegistry handles their menu wiring.
     */
    public function registerMenu(): void
    {
        $this->registerOwnTopLevel();
    }

    public function render(): void
    {
        if (!current_user_can($this->manifest->capability)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'codeon-framework'));
        }

        $active = $this->activeTab();
        $header = new Header($this->manifest);
        $footer = new Footer($this->manifest);

        echo '<div class="wrap codeon-wrap">';
        $header->render($this->manifest->resolveHeaderStatusCard());

        $this->renderTabNav($active);
        Notices::flush();
        echo '<div class="codeon-content">';
        $active->render($this->nonceAction());
        echo '</div>';

        $footer->render();
        echo '</div>';
    }

    public function handleSave(): void
    {
        $this->guardWriteRequest();

        $tabSlug = isset($_POST['codeon_tab']) ? sanitize_key((string) $_POST['codeon_tab']) : '';
        $tab = $this->findTab($tabSlug);
        if ($tab === null) {
            $this->redirectWithNotice($tabSlug, 'tab_not_found');
        }

        $repo = $tab->repository();
        if ($repo === null) {
            // Tab without a repository (custom render) shouldn't be POSTing here.
            $this->redirectWithNotice($tabSlug, 'no_repository');
        }

        $posted = isset($_POST['codeon']) && is_array($_POST['codeon'])
            ? wp_unslash($_POST['codeon'])
            : [];

        $result = FieldValidator::process($tab->schema(), $posted, $repo);

        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $path => $err) {
                Notices::add(sprintf('%s: %s', $path, $err->get_error_message()), 'error');
            }
            $this->redirectWithNotice($tabSlug, 'validation_failed');
        }

        $clean = $tab->beforeSave($result['clean']);
        foreach ($clean as $path => $value) {
            $repo->set($path, $value);
        }
        $repo->flush();
        $tab->afterSave($clean);

        Notices::add(__('Settings saved.', 'codeon-framework'), 'success');
        $this->redirectWithNotice($tabSlug, 'saved');
    }

    public function handleTabAction(): void
    {
        $this->guardWriteRequest();

        $tabSlug = isset($_POST['codeon_tab']) ? sanitize_key((string) $_POST['codeon_tab']) : '';
        $action  = isset($_POST['codeon_action']) ? sanitize_key((string) $_POST['codeon_action']) : '';

        $tab = $this->findTab($tabSlug);
        if ($tab === null) {
            $this->redirectWithNotice($tabSlug, 'tab_not_found');
        }

        $handler = $tab->actions()[$action] ?? null;
        if (!is_callable($handler)) {
            $this->redirectWithNotice($tabSlug, 'unknown_action');
        }

        $payload = isset($_POST['codeon']) && is_array($_POST['codeon'])
            ? wp_unslash($_POST['codeon'])
            : [];

        $handler($payload);
        $this->redirectWithNotice($tabSlug, 'action_done');
    }

    public function dispatchLegacy(string $tabSlug, string $opName, array $posted): void
    {
        // Used by AdminPostRouter::aliasLegacy to route a legacy admin_post_*
        // action into the framework's save flow without changing URLs.
        $_POST['codeon_tab'] = $tabSlug;
        $_POST['codeon']     = $posted['codeon'] ?? $posted;
        if ($opName === 'save') {
            $this->handleSave();
            return;
        }
        $_POST['codeon_action'] = $opName;
        $this->handleTabAction();
    }

    /** @return Tab[] */
    public function tabs(): array
    {
        return $this->tabs;
    }

    public function manifest(): Manifest
    {
        return $this->manifest;
    }

    // ---------------------------------------------------------------------

    private function activeTab(): Tab
    {
        $requested = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : '';
        foreach ($this->tabs as $tab) {
            if ($tab->slug() === $requested) {
                return $tab;
            }
        }
        return $this->tabs[0];
    }

    private function findTab(string $slug): ?Tab
    {
        foreach ($this->tabs as $tab) {
            if ($tab->slug() === $slug) {
                return $tab;
            }
        }
        return null;
    }

    private function renderTabNav(Tab $active): void
    {
        if (count($this->tabs) <= 1) {
            return;
        }
        echo '<nav class="nav-tab-wrapper codeon-tabs">';
        foreach ($this->tabs as $tab) {
            $isActive = $tab->slug() === $active->slug();
            $url = add_query_arg(
                ['page' => $this->manifest->slug, 'tab' => $tab->slug()],
                admin_url('admin.php')
            );
            $tone = $tab->dotTone();
            $dot = $tone !== null
                ? '<span class="codeon-tab-dot codeon-tab-dot-' . esc_attr($tone) . '"></span>'
                : '';
            printf(
                '<a href="%s" class="nav-tab%s codeon-tab">%s%s</a>',
                esc_url($url),
                $isActive ? ' nav-tab-active' : '',
                $dot,
                esc_html($tab->label())
            );
        }
        echo '</nav>';
    }

    private function guardWriteRequest(): void
    {
        if (!current_user_can($this->manifest->capability)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'codeon-framework'));
        }
        NonceGate::verifyOrDie($this->nonceAction());
    }

    private function redirectWithNotice(string $tabSlug, string $code): never
    {
        $args = ['page' => $this->manifest->slug];
        if ($tabSlug !== '') {
            $args['tab'] = $tabSlug;
        }
        $args['codeon_notice'] = $code;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
