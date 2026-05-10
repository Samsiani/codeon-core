<?php
/**
 * 3-state field mode resolver for Region / Municipality / Settlement.
 *
 * Each field has one of three modes:
 *
 *   - DISABLED — field is not rendered at checkout, no validation.
 *   - OPTIONAL — field is rendered, but blank values pass validation.
 *   - REQUIRED — field is rendered AND customers must fill it.
 *
 * Backwards-compat: reads the new `<field>_field_mode` option key first,
 * then falls back to the v0.2.x legacy boolean keys so existing merchants
 * upgrading from 0.2.9 don't see behavior changes until they touch the
 * settings page.
 *
 * Cross-field rule: if Municipality is DISABLED, the cascade has no
 * source to filter the Settlement dropdown — the Settlement field stays
 * registered but falls back to vanilla WooCommerce city text input (no
 * Select2, no cascade JS). The configured required-ness is preserved.
 *
 * @package CodeOn\Core\Locations\Settings
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\Settings;

defined('ABSPATH') || exit;

final class FieldMode
{
    public const DISABLED = 'disabled';
    public const OPTIONAL = 'optional';
    public const REQUIRED = 'required';

    public const FIELD_REGION       = 'region';
    public const FIELD_MUNICIPALITY = 'municipality';
    public const FIELD_SETTLEMENT   = 'settlement';

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [self::DISABLED, self::OPTIONAL, self::REQUIRED];
    }

    /**
     * Map of mode constant → human label for the select widget.
     * @return array<string,string>
     */
    public static function choices(): array
    {
        return [
            self::DISABLED => __('Disabled — field not shown at checkout', 'codeon-core'),
            self::OPTIONAL => __('Optional — shown, blank value allowed', 'codeon-core'),
            self::REQUIRED => __('Required — shown, customer must fill it', 'codeon-core'),
        ];
    }

    public static function resolve(string $field): string
    {
        $opts = (array) get_option('codeon_core_settings', []);
        $key  = $field . '_field_mode';

        if (isset($opts[$key]) && in_array($opts[$key], self::allowed(), true)) {
            return $opts[$key];
        }

        // Legacy fallback for installs that still have the v0.2.x option
        // shape (hide_region_field / require_municipality / require_settlement).
        return match ($field) {
            self::FIELD_REGION => array_key_exists('hide_region_field', $opts)
                ? (!empty($opts['hide_region_field']) ? self::DISABLED : self::REQUIRED)
                : self::DISABLED,
            self::FIELD_MUNICIPALITY => array_key_exists('require_municipality', $opts)
                ? (!empty($opts['require_municipality']) ? self::REQUIRED : self::OPTIONAL)
                : self::REQUIRED,
            self::FIELD_SETTLEMENT => array_key_exists('require_settlement', $opts)
                ? (!empty($opts['require_settlement']) ? self::REQUIRED : self::OPTIONAL)
                : self::REQUIRED,
            default => self::REQUIRED,
        };
    }

    public static function isDisabled(string $field): bool
    {
        return self::resolve($field) === self::DISABLED;
    }

    public static function isRequired(string $field): bool
    {
        return self::resolve($field) === self::REQUIRED;
    }

    /**
     * True when the Settlement dropdown can actually cascade — i.e. the
     * Municipality field is on the form to filter by. When false, the
     * Settlement falls back to vanilla WC city behavior even if its own
     * mode is OPTIONAL or REQUIRED.
     */
    public static function settlementCascadePossible(): bool
    {
        return self::resolve(self::FIELD_MUNICIPALITY) !== self::DISABLED;
    }
}
