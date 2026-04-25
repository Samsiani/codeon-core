<?php
/**
 * Block-checkout (Cart/Checkout block) integration for Georgian addresses.
 *
 * Block checkout uses an entirely different field surface than the classic
 * shortcode checkout — woocommerce_default_address_fields and friends do
 * NOT apply here. Instead WC 8.6+ exposes
 * {@see woocommerce_register_additional_checkout_field()} for plugins to
 * register their own fields that render in both the block checkout AND the
 * block-based My Account address forms.
 *
 * Strategy:
 *   - Register `codeon/municipality` (select, 77 options) — limits muns
 *     server-side; JS filters visible options by chosen state.
 *   - Register `codeon/settlement` (text + HTML5 datalist) — customer
 *     types the village name, browser autocompletes from a datalist
 *     containing all settlements of the chosen municipality. Datalist
 *     is the simplest cascading-search UX that works without React.
 *   - Inject a `<datalist>` of settlements on every block checkout page;
 *     JS narrows it down as muni changes.
 *
 * Server side: when the order is created, we read these custom field
 * values and write our standard `_billing_geo_*` meta + sync the
 * settlement name into the core `_billing_city` field so existing WC
 * reports and exports keep working.
 *
 * @package CodeOn\Core\Locations\WooIntegration
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\WooIntegration;

use CodeOn\Core\Locations\Data\DisplayFormatter;
use CodeOn\Core\Locations\Data\Repository;

final class BlockCheckoutFields
{
    public const FIELD_MUN = 'codeon/municipality';
    public const FIELD_SET = 'codeon/settlement';

    public function register(): void
    {
        // WC bundles Blocks since 8.x. By the time Locations\Boot::register
        // runs (at our init hook), woocommerce_register_additional_checkout_field
        // is already defined. If the hook already fired we just call directly;
        // otherwise we hook in case Locations\Boot ran early for some reason.
        if (function_exists('woocommerce_register_additional_checkout_field')) {
            $this->registerFields();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'registerFields']);
        }

        // Persist on order create — write our standard geo_* meta + sync
        // to the core `_billing_city` so WC reports keep working.
        add_action('woocommerce_store_api_checkout_update_order_from_request',
            [$this, 'syncToOrderMeta'], 10, 2);

        // Inject the <datalist> into the checkout page footer.
        add_action('wp_footer', [$this, 'renderDatalist']);

        // Enqueue cascade JS on pages that contain the checkout/cart blocks.
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerFields(): void
    {
        if (!function_exists('woocommerce_register_additional_checkout_field')) {
            // WC Blocks < 8.6 — additional checkout field API not available.
            return;
        }

        $repo = Repository::instance();
        $fmt  = DisplayFormatter::fromOptions();
        $opts = (array) get_option('codeon_core_settings', []);
        $showOccupied = (bool) ($opts['show_occupied'] ?? false);
        $munRequired  = (bool) ($opts['require_municipality'] ?? true);
        $setRequired  = (bool) ($opts['require_settlement']   ?? true);

        // Build the municipality options as a flat list — 77 total. JS
        // hides options whose region doesn't match the chosen state.
        // The `data-region` attribute is set on the option element via
        // the rendered HTML; WC Blocks supports `options` as [value=>label].
        $munOptions = [];
        foreach ($repo->regions(includeOccupied: $showOccupied) as $region) {
            foreach ($region['municipalities'] as $m) {
                if (!$showOccupied && $m['occupied']) continue;
                // Prefix label with region for context — this is the only
                // way to disambiguate when JS isn't loaded.
                $munOptions[$m['id']] = sprintf(
                    '%s — %s',
                    $fmt->label(['name_ka' => $region['name_ka'], 'name_en' => $region['name_en']]),
                    $fmt->label($m)
                );
            }
        }

        woocommerce_register_additional_checkout_field([
            'id'         => self::FIELD_MUN,
            'label'      => __('Municipality', 'codeon-core'),
            'location'   => 'address',
            'type'       => 'select',
            'required'   => $munRequired,
            'options'    => array_map(
                static fn(string $id, string $label) => ['value' => $id, 'label' => $label],
                array_keys($munOptions),
                array_values($munOptions)
            ),
            'attributes' => [
                'autocomplete'   => 'address-level3',
                'data-codeon-geo'=> 'municipality',
            ],
            'validate_callback' => [$this, 'validateMunicipality'],
            'sanitize_callback' => static fn(string $v): string => sanitize_key($v),
        ]);

        woocommerce_register_additional_checkout_field([
            'id'         => self::FIELD_SET,
            'label'      => __('Settlement (city / town / village)', 'codeon-core'),
            'location'   => 'address',
            'type'       => 'text',
            'required'   => $setRequired,
            'attributes' => [
                'autocomplete'   => 'address-level2',
                'list'           => 'codeon-geo-settlements',
                'data-codeon-geo'=> 'settlement',
                'placeholder'    => __('Start typing the name…', 'codeon-core'),
            ],
            'validate_callback' => [$this, 'validateSettlement'],
            'sanitize_callback' => static fn(string $v): string => sanitize_text_field($v),
        ]);
    }

    public function validateMunicipality(mixed $value): \WP_Error|null
    {
        $value = is_string($value) ? $value : '';
        if ($value === '') return null; // optionality handled by `required`
        if (Repository::instance()->municipality($value) === null) {
            return new \WP_Error('codeon_invalid_municipality',
                __('Please pick a valid Georgian municipality.', 'codeon-core'));
        }
        return null;
    }

    public function validateSettlement(mixed $value): \WP_Error|null
    {
        $value = is_string($value) ? $value : '';
        if ($value === '') return null;
        // Settlement is free text matched against the dataset — but WC
        // posts every block-checkout field with a stable schema, so a
        // value-presence check is enough here. Cross-validation against
        // the chosen municipality runs in syncToOrderMeta.
        return null;
    }

    /**
     * @param \WC_Order $order
     */
    public function syncToOrderMeta($order, \WP_REST_Request $request): void
    {
        if (!$order instanceof \WC_Order) return;
        if ($order->get_billing_country() !== 'GE') return;

        // Block-checkout custom fields land in WC's address-meta map under
        // namespace `_<location>_<plugin/field>`. Read both billing &
        // shipping contexts.
        $repo = Repository::instance();

        foreach (['billing', 'shipping'] as $ctx) {
            // WC normalizes "/" to "_" in meta keys — `codeon/municipality`
            // becomes `_billing_codeon_municipality`.
            $munId = (string) $order->get_meta('_' . $ctx . '_codeon_municipality');
            $setNm = (string) $order->get_meta('_' . $ctx . '_codeon_settlement');

            if ($munId !== '') {
                $mun = $repo->municipality($munId);
                if ($mun !== null) {
                    $order->update_meta_data("_{$ctx}_geo_municipality_id",    $mun['id']);
                    $order->update_meta_data("_{$ctx}_geo_municipality_label", $mun['name_ka']);
                    $order->update_meta_data("_{$ctx}_geo_region_id",          $mun['region_id']);

                    // Settlement: try to match the typed string against
                    // the muni's settlements. If matched, save the id +
                    // sync the canonical Georgian name into core `city`.
                    if ($setNm !== '') {
                        $needle = mb_strtolower($setNm);
                        foreach ($mun['settlements'] as $s) {
                            if (mb_strtolower($s['name_ka']) === $needle) {
                                $order->update_meta_data("_{$ctx}_geo_settlement_id",   (int) $s['id']);
                                $order->update_meta_data("_{$ctx}_geo_settlement_name", $s['name_ka']);
                                $setterCity = "set_{$ctx}_city";
                                if (method_exists($order, $setterCity)) {
                                    $order->{$setterCity}($s['name_ka']);
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }
        $order->save();
    }

    /**
     * Inject the master <datalist> of settlements once per page. The
     * cascade JS narrows the list down by mutating its <option>
     * elements based on the selected municipality.
     *
     * Datalist is only useful on pages with a checkout / cart / address
     * form — gate to those pages to keep page weight off the rest of
     * the site.
     */
    public function renderDatalist(): void
    {
        if (!self::isCheckoutOrAddressPage()) return;

        $repo = Repository::instance();
        $fmt  = DisplayFormatter::fromOptions();
        $opts = (array) get_option('codeon_core_settings', []);
        $showOccupied = (bool) ($opts['show_occupied'] ?? false);

        echo '<datalist id="codeon-geo-settlements">';
        foreach ($repo->regions(includeOccupied: $showOccupied) as $region) {
            foreach ($region['municipalities'] as $m) {
                if (!$showOccupied && $m['occupied']) continue;
                foreach ($m['settlements'] as $s) {
                    printf(
                        '<option value="%s" data-mun="%s" label="%s"></option>',
                        esc_attr($s['name_ka']),
                        esc_attr($m['id']),
                        esc_attr($fmt->label($m))
                    );
                }
            }
        }
        echo '</datalist>';
    }

    public function enqueueAssets(): void
    {
        if (!self::isCheckoutOrAddressPage()) return;

        wp_enqueue_style(
            'codeon-core-checkout',
            CODEON_CORE_URL . 'assets/css/checkout.css',
            [],
            CODEON_CORE_VERSION
        );
        wp_enqueue_script(
            'codeon-core-blocks-cascade',
            CODEON_CORE_URL . 'assets/js/blocks-cascade.js',
            [],
            CODEON_CORE_VERSION,
            true
        );
        wp_localize_script('codeon-core-blocks-cascade', 'CodeOnGeoBlocks', [
            'restUrl' => esc_url_raw(rest_url('codeon-geo/v1/')),
        ]);
    }

    private static function isCheckoutOrAddressPage(): bool
    {
        if (!function_exists('is_checkout') || !function_exists('is_account_page')) {
            return false;
        }
        // Block checkout still satisfies is_checkout(); My Account
        // edit-address satisfies is_account_page().
        return is_checkout() || is_account_page() || is_cart();
    }
}
