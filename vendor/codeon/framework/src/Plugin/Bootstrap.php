<?php

declare(strict_types=1);

namespace CodeOn\Framework\Plugin;

use CodeOn\Framework\Admin\Assets;
use CodeOn\Framework\Admin\Notices;
use CodeOn\Framework\Admin\Page;
use CodeOn\Framework\Admin\Tab;
use CodeOn\Framework\Watermark\BuildStampContract;
use CodeOn\Framework\Watermark\RecoveryMode;

/**
 * Single entry point a host plugin calls to wire everything up.
 *
 * The host plugin builds a {@see Manifest}, builds its array of {@see Tab}
 * instances, and calls {@see Bootstrap::register()}. From there the
 * framework owns:
 *   - menu registration
 *   - chrome rendering (header / tabs / footer)
 *   - save dispatch + per-tab action dispatch
 *   - asset enqueueing on framework-owned pages
 *   - flash-notice flushing
 *
 * If a {@see BuildStampContract} is supplied and `verify()` returns false,
 * Bootstrap registers a *reduced* page (chrome + recovery-mode notice
 * only). The host plugin is responsible for skipping its own sync /
 * payment / scheduler subsystems in that case — Bootstrap can't know
 * which classes those are.
 */
final class Bootstrap
{
    /**
     * @param Tab[] $tabs
     * @return array{page:Page,recovery:bool}
     */
    public static function register(
        Manifest $manifest,
        array $tabs,
        ?BuildStampContract $buildStamp = null
    ): array {
        $isRecovery = $buildStamp !== null && !$buildStamp->verify();

        if ($isRecovery) {
            // Strip business-logic tabs; only the License tab survives so a
            // merchant can trigger an update / contact support.
            $tabs = array_values(array_filter(
                $tabs,
                static fn (Tab $t) => $t->slug() === 'license'
            ));

            add_action('admin_notices', static function () use ($manifest): void {
                if (!self::isCurrentPluginAdminPage($manifest->slug)) {
                    return;
                }
                RecoveryMode::adminNotice(
                    $manifest->supportUrl !== '' ? $manifest->supportUrl : 'support@codeon.ge'
                );
            });
        }

        $page = new Page($manifest, $tabs);
        $page->registerHooks();

        // Hub-mode menus go through the shared HubRegistry so every
        // CodeOn plugin lands as a submenu under one top-level entry,
        // even if Core (the future hub owner) isn't installed yet.
        // Legacy plugins (default) keep their own top-level menu via
        // Page::registerOwnTopLevel(), wired in registerHooks().
        if ($manifest->useHub) {
            HubRegistry::register($manifest, $page);
        }

        $assets = new Assets($manifest);
        $assets->register();

        // Guarantee notices are flushed on every codeon-owned page even if
        // a custom render path forgot to call Notices::flush() itself.
        add_action('admin_notices', static function () use ($manifest): void {
            if (!self::isCurrentPluginAdminPage($manifest->slug)) {
                return;
            }
            Notices::flush();
        });

        return ['page' => $page, 'recovery' => $isRecovery];
    }

    private static function isCurrentPluginAdminPage(string $slug): bool
    {
        if (!is_admin()) {
            return false;
        }
        $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
        return $page === $slug;
    }
}
