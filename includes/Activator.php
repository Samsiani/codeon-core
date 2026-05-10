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

defined('ABSPATH') || exit;

final class Activator
{
    public static function activate(): void
    {
        self::flushOwnOpcache();
        self::checkPhpVersion();
        self::seedDefaults();
        self::migrateLegacyFieldModes();
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
                'display_mode'             => 'auto',
                'locations_enabled'        => true,
                'show_occupied'            => false,
                'simplified_latin'         => true,
                // 3-state field modes. Defaults match the common
                // Georgian-store baseline: Region auto-derived from muni
                // (Disabled in UI), Municipality + Settlement required.
                'region_field_mode'        => \CodeOn\Core\Locations\Settings\FieldMode::DISABLED,
                'municipality_field_mode'  => \CodeOn\Core\Locations\Settings\FieldMode::REQUIRED,
                'settlement_field_mode'    => \CodeOn\Core\Locations\Settings\FieldMode::REQUIRED,
                // Standard WC field visibility toggles (hide/show only).
                'hide_country_field'       => false,
                'hide_company_field'       => false,
                'hide_address_2_field'     => false,
                'hide_postcode_field'      => false,
                // Tbilisi-area override mode (off by default; opt-in only).
                'tbilisi_only_mode'              => false,
                'tbilisi_scope'                  => \CodeOn\Core\Locations\Settings\TbilisiMode::SCOPE_ONLY,
                'tbilisi_surrounding_settlements' => [],
            ]);
        }
        if (get_option('codeon_core_activated_at') === false) {
            add_option('codeon_core_activated_at', gmdate('c'));
        }
    }

    /**
     * One-shot migration: convert v0.2.x boolean keys
     * (hide_region_field, require_municipality, require_settlement) into
     * the v0.3.x 3-state keys, so merchants upgrading from 0.2.9 keep
     * their previously chosen behavior verbatim.
     *
     * Runs on every activation (idempotent: only writes a key that
     * isn't already set), so reactivating after a manual edit won't
     * stomp newer values.
     */
    private static function migrateLegacyFieldModes(): void
    {
        $opts = get_option('codeon_core_settings');
        if (!is_array($opts)) {
            return;
        }
        $changed = false;

        // Region: hide_region_field=true → DISABLED, false → REQUIRED.
        if (!isset($opts['region_field_mode']) && array_key_exists('hide_region_field', $opts)) {
            $opts['region_field_mode'] = !empty($opts['hide_region_field'])
                ? \CodeOn\Core\Locations\Settings\FieldMode::DISABLED
                : \CodeOn\Core\Locations\Settings\FieldMode::REQUIRED;
            $changed = true;
        }

        // Municipality / Settlement: require_* checkbox → REQUIRED, false → OPTIONAL.
        foreach ([
            'municipality_field_mode' => 'require_municipality',
            'settlement_field_mode'   => 'require_settlement',
        ] as $newKey => $oldKey) {
            if (!isset($opts[$newKey]) && array_key_exists($oldKey, $opts)) {
                $opts[$newKey] = !empty($opts[$oldKey])
                    ? \CodeOn\Core\Locations\Settings\FieldMode::REQUIRED
                    : \CodeOn\Core\Locations\Settings\FieldMode::OPTIONAL;
                $changed = true;
            }
        }

        if ($changed) {
            update_option('codeon_core_settings', $opts, false);
        }
    }
}
