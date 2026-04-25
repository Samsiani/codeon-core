<?php
/**
 * CodeOn Core activation.
 *
 * Memory rule (`feedback_plugin_activator_opcache_flush.md`): the very
 * first thing every CodeOn Activator must do is opcache_invalidate its
 * own PHP files. Reinstalls otherwise leak stale bytecode and the
 * framework's recovery-mode check trips falsely on the next page load.
 *
 * @package CodeOn\Core
 */

declare(strict_types=1);

namespace CodeOn\Core;

final class Activator
{
    public static function activate(): void
    {
        self::flushOwnOpcache();
        self::checkPhpVersion();
        self::seedDefaults();
    }

    /**
     * Walk the plugin directory and force-invalidate every PHP file in
     * opcache. Cheap enough to do on every activation; far cheaper than
     * debugging a stale-bytecode false-positive recovery mode trip.
     */
    private static function flushOwnOpcache(): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(CODEON_CORE_PATH, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                @opcache_invalidate($file->getPathname(), true);
            }
        }
    }

    private static function checkPhpVersion(): void
    {
        if (version_compare(PHP_VERSION, CODEON_CORE_MIN_PHP, '<')) {
            deactivate_plugins(CODEON_CORE_BASENAME);
            wp_die(
                esc_html(sprintf(
                    /* translators: %1$s required PHP version, %2$s current version */
                    __('CodeOn Core requires PHP %1$s or newer. This server is running %2$s.', 'codeon-core'),
                    CODEON_CORE_MIN_PHP,
                    PHP_VERSION
                )),
                esc_html__('Plugin activation failed', 'codeon-core'),
                ['back_link' => true]
            );
        }
    }

    private static function seedDefaults(): void
    {
        if (get_option('codeon_core_settings') === false) {
            add_option('codeon_core_settings', [
                'display_mode'      => 'auto',   // auto | ka | en | bilingual
                'show_occupied'     => false,
                'require_municipality' => true,
                'require_settlement'   => true,
                'simplified_latin'     => true,
            ]);
        }
        if (get_option('codeon_core_activated_at') === false) {
            add_option('codeon_core_activated_at', gmdate('c'));
        }
    }
}
