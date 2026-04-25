<?php
/**
 * Classic-checkout field surgery for Georgian addresses.
 *
 *   - Insert a `municipality` field after `state`.
 *   - Convert `city` from text input to `<select>`.
 *   - Mark them required (configurable via settings).
 *   - Enqueue checkout-cascade.js to fill the dropdowns via REST as the
 *     customer picks Country=GE → State → Municipality → City.
 *
 * Block checkout is M2 — uses entirely different machinery
 * (Automattic\WooCommerce\Blocks integration registry).
 *
 * @package CodeOn\Core\Locations\WooIntegration
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\WooIntegration;

use CodeOn\Framework\Storage\SettingsRepository;

final class ClassicCheckoutFields
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function register(): void
    {
        // Add municipality + adjust city for default address fields. Both
        // billing and shipping inherit defaults; we apply per-field tweaks
        // in the country locale filter below.
        add_filter('woocommerce_default_address_fields', [$this, 'extendDefaultFields']);

        // Per-country locale: mark fields required only when country=GE.
        add_filter('woocommerce_get_country_locale', [$this, 'extendGeorgiaLocale']);

        // Order field priority — keep municipality sandwiched between
        // state and city.
        add_filter('woocommerce_billing_fields',  [$this, 'extendBillingFields'], 20);
        add_filter('woocommerce_shipping_fields', [$this, 'extendShippingFields'], 20);

        // Enqueue cascade JS only on checkout / account edit-address pages.
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * @param array<string, array<string,mixed>> $fields
     * @return array<string, array<string,mixed>>
     */
    public function extendDefaultFields(array $fields): array
    {
        // Convert city to a select. Options are populated by
        // checkout-cascade.js based on the chosen state+municipality.
        if (isset($fields['city'])) {
            $fields['city']['type']    = 'select';
            $fields['city']['options'] = ['' => __('Select…', 'codeon-core')];
            $fields['city']['class']   = array_merge(
                (array) ($fields['city']['class'] ?? []),
                ['codeon-geo-field', 'codeon-geo-city']
            );
            // Priority 47 puts City (Settlement) RIGHT after Municipality (46),
            // before address_1 (default 50). Keeps the geographic cascade
            // visually grouped at the top of the form.
            $fields['city']['priority'] = 47;
        }

        // Move state up too. WC default priority is 80 — way below city.
        // For the cascade to read top-to-bottom (Region → Municipality →
        // Settlement → Address) we need state right under country (40).
        if (isset($fields['state'])) {
            $fields['state']['priority'] = 45;
            $fields['state']['class']    = array_merge(
                (array) ($fields['state']['class'] ?? []),
                ['codeon-geo-field', 'codeon-geo-state']
            );
        }

        // Insert municipality right after state. The priority chain is
        // country(40) → state(45) → municipality(46) → city(47) →
        // address_1(50) → address_2(60) → postcode(90).
        $rebuilt = [];
        foreach ($fields as $key => $cfg) {
            $rebuilt[$key] = $cfg;
            if ($key === 'state') {
                $rebuilt['municipality'] = [
                    'label'    => __('Municipality', 'codeon-core'),
                    'type'     => 'select',
                    'required' => false,
                    'class'    => ['form-row-wide', 'codeon-geo-field', 'codeon-geo-municipality'],
                    'options'  => ['' => __('Select…', 'codeon-core')],
                    'priority' => 46,
                ];
            }
        }
        return $rebuilt;
    }

    /**
     * @param array<string, array<string,mixed>> $locales
     * @return array<string, array<string,mixed>>
     */
    public function extendGeorgiaLocale(array $locales): array
    {
        $opts = (array) get_option('codeon_core_settings', []);
        $munRequired   = (bool) ($opts['require_municipality'] ?? true);
        $cityRequired  = (bool) ($opts['require_settlement']   ?? true);

        $locales['GE'] = array_replace_recursive(
            $locales['GE'] ?? [],
            [
                'state' => [
                    'required' => true,
                    'label'    => __('Region', 'codeon-core'),
                ],
                'municipality' => [
                    'required' => $munRequired,
                    'hidden'   => false,
                ],
                'city' => [
                    'required' => $cityRequired,
                    'label'    => __('Settlement', 'codeon-core'),
                ],
            ]
        );
        return $locales;
    }

    /**
     * @param array<string, array<string,mixed>> $fields
     * @return array<string, array<string,mixed>>
     */
    public function extendBillingFields(array $fields): array
    {
        return $this->ensureMunicipalityField($fields, 'billing_');
    }

    /**
     * @param array<string, array<string,mixed>> $fields
     * @return array<string, array<string,mixed>>
     */
    public function extendShippingFields(array $fields): array
    {
        return $this->ensureMunicipalityField($fields, 'shipping_');
    }

    /**
     * The default-address-fields filter creates the `municipality` field,
     * but WC core's per-context billing/shipping field arrays use
     * prefixed keys (`billing_municipality`). Make sure the prefixed
     * key exists with sensible defaults.
     *
     * @param array<string, array<string,mixed>> $fields
     * @return array<string, array<string,mixed>>
     */
    private function ensureMunicipalityField(array $fields, string $prefix): array
    {
        $key      = $prefix . 'municipality';
        $stateKey = $prefix . 'state';
        $cityKey  = $prefix . 'city';

        // Apply the same priority overrides on the prefixed copies of state
        // and city so billing/shipping fields match.
        if (isset($fields[$stateKey])) {
            $fields[$stateKey]['priority'] = 45;
        }
        if (isset($fields[$cityKey])) {
            $fields[$cityKey]['priority'] = 47;
        }

        if (isset($fields[$key])) {
            $fields[$key]['priority'] = 46;
            return $fields;
        }

        $rebuilt = [];
        foreach ($fields as $k => $cfg) {
            $rebuilt[$k] = $cfg;
            if ($k === $stateKey) {
                $rebuilt[$key] = [
                    'label'    => __('Municipality', 'codeon-core'),
                    'type'     => 'select',
                    'required' => false,
                    'class'    => ['form-row-wide', 'codeon-geo-field', 'codeon-geo-municipality'],
                    'options'  => ['' => __('Select…', 'codeon-core')],
                    'priority' => 46,
                ];
            }
        }
        return $rebuilt;
    }

    public function enqueue(): void
    {
        // Only on pages where address fields render: checkout, cart (with
        // shipping calculator), edit-address account page.
        if (!function_exists('is_checkout') || !function_exists('is_account_page')) {
            return;
        }
        if (!is_checkout() && !is_account_page() && !is_cart()) {
            return;
        }
        wp_enqueue_style(
            'codeon-core-checkout',
            CODEON_CORE_URL . 'assets/css/checkout.css',
            [],
            CODEON_CORE_VERSION
        );
        wp_enqueue_script(
            'codeon-core-checkout-cascade',
            CODEON_CORE_URL . 'assets/js/checkout-cascade.js',
            ['jquery', 'wc-checkout'],
            CODEON_CORE_VERSION,
            true
        );
        wp_localize_script('codeon-core-checkout-cascade', 'CodeOnGeo', [
            'restUrl' => esc_url_raw(rest_url('codeon-geo/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
            'i18n'    => [
                'select'           => __('Select…', 'codeon-core'),
                'loading'          => __('Loading…', 'codeon-core'),
                'noResults'        => __('No matches', 'codeon-core'),
                'pickRegionFirst'  => __('— pick a Region first —', 'codeon-core'),
                'pickMuniFirst'    => __('— pick a Municipality first —', 'codeon-core'),
            ],
        ]);
    }
}
