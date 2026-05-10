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

defined('ABSPATH') || exit;

use CodeOn\Core\Locations\Settings\FieldMode;
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

        // Per-context billing/shipping filters at default priority — early
        // pass, in case nothing later overrides us.
        add_filter('woocommerce_billing_fields',  [$this, 'extendBillingFields'], 20);
        add_filter('woocommerce_shipping_fields', [$this, 'extendShippingFields'], 20);

        // Final-pass override on woocommerce_checkout_fields. Woodmart's
        // checkout-fields-manager hooks the SAME filter at priority 99999
        // and rewrites the entire field array — overwriting our priorities
        // and stripping our municipality field. We re-apply at priority
        // 100000 so our tweaks always win the last-writer race regardless
        // of what theme/plugin is installed.
        add_filter('woocommerce_checkout_fields', [$this, 'enforceFinalFieldSetup'], 100000);

        // Enqueue cascade JS only on checkout / account edit-address pages.
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Last-writer-wins enforcement of our field setup. Runs AFTER every
     * other plugin/theme has had a chance to mangle the checkout fields.
     *
     * Cascade UX (per user feedback):
     *   - State (Region) is HIDDEN — auto-populated by JS from the chosen
     *     municipality so WC's tax/shipping/order-meta still get a state
     *     code under the hood.
     *   - Municipality is the first user-facing dropdown, pre-loaded with
     *     all 77 munis (label includes region prefix for context).
     *   - Settlement (city) cascades from Municipality.
     *
     * @param array<string, array<string, array<string,mixed>>> $checkoutFields
     * @return array<string, array<string, array<string,mixed>>>
     */
    public function enforceFinalFieldSetup(array $checkoutFields): array
    {
        $opts          = (array) get_option('codeon_core_settings', []);
        $regionMode    = FieldMode::resolve(FieldMode::FIELD_REGION);
        $munMode       = FieldMode::resolve(FieldMode::FIELD_MUNICIPALITY);
        $settleMode    = FieldMode::resolve(FieldMode::FIELD_SETTLEMENT);
        $cascadeWorks  = $munMode !== FieldMode::DISABLED;
        $munOptions    = $cascadeWorks ? $this->buildMunicipalityOptions() : [];

        // Standard WC field hides (non-geo).
        $hideMap = [
            '_country'   => (bool) ($opts['hide_country_field']   ?? false),
            '_company'   => (bool) ($opts['hide_company_field']   ?? false),
            '_address_2' => (bool) ($opts['hide_address_2_field'] ?? false),
            '_postcode'  => (bool) ($opts['hide_postcode_field']  ?? false),
        ];

        foreach (['billing', 'shipping'] as $group) {
            if (!isset($checkoutFields[$group]) || !is_array($checkoutFields[$group])) {
                continue;
            }

            if ($cascadeWorks) {
                $checkoutFields[$group] = $this->ensureMunicipalityField($checkoutFields[$group], $group . '_');
            } else {
                // Muni disabled → strip our muni field if a previous boot
                // injected it. Belt & suspenders against stale state.
                unset($checkoutFields[$group][$group . '_municipality']);
            }

            // Apply visibility-hiding class to user-toggled WC fields.
            foreach ($hideMap as $suffix => $hide) {
                if (!$hide) continue;
                $key = $group . $suffix;
                if (isset($checkoutFields[$group][$key])) {
                    $checkoutFields[$group][$key]['class'] = array_merge(
                        (array) ($checkoutFields[$group][$key]['class'] ?? []),
                        ['codeon-hidden-row']
                    );
                    // Drop required flag for hidden fields that don't have
                    // an auto-derived value. Country stays required because
                    // WC preselects "Georgia" when the only allowed country.
                    if ($suffix !== '_country') {
                        $checkoutFields[$group][$key]['required'] = false;
                    }
                }
            }

            $stateKey = $group . '_state';
            $munKey   = $group . '_municipality';
            $cityKey  = $group . '_city';

            // Region (state): visibility + required-ness driven by mode.
            if (isset($checkoutFields[$group][$stateKey])) {
                $checkoutFields[$group][$stateKey]['priority'] = 45;
                $checkoutFields[$group][$stateKey]['label']    = __('Region', 'codeon-core');

                if ($regionMode === FieldMode::DISABLED) {
                    $checkoutFields[$group][$stateKey]['class'] = array_merge(
                        (array) ($checkoutFields[$group][$stateKey]['class'] ?? []),
                        ['codeon-hidden-row']
                    );
                    // When Muni is also disabled there's no auto-derivation
                    // source — drop required so empty state doesn't block
                    // checkout. When Muni is shown, JS auto-fills state,
                    // so required=true is safe and matches WC expectations.
                    $checkoutFields[$group][$stateKey]['required'] = $cascadeWorks;
                } else {
                    $checkoutFields[$group][$stateKey]['required'] = $regionMode === FieldMode::REQUIRED;
                }
            }

            // Municipality (custom field). Only render when mode != disabled.
            if ($munMode !== FieldMode::DISABLED && isset($checkoutFields[$group][$munKey])) {
                $checkoutFields[$group][$munKey]['priority'] = 46;
                $checkoutFields[$group][$munKey]['label']    = __('Municipality', 'codeon-core');
                $checkoutFields[$group][$munKey]['type']     = 'select';
                $checkoutFields[$group][$munKey]['required'] = $munMode === FieldMode::REQUIRED;
                $checkoutFields[$group][$munKey]['options']  = $munOptions;
                $checkoutFields[$group][$munKey]['class']    = array_merge(
                    (array) ($checkoutFields[$group][$munKey]['class'] ?? []),
                    ['form-row-wide', 'codeon-geo-field', 'codeon-geo-municipality']
                );
            }

            // Settlement (city). Treatment depends on combination:
            //  - settle=disabled      → hide city field entirely
            //  - settle != disabled + cascade works (muni shown) → select that cascades
            //  - settle != disabled + cascade dead (muni disabled) → vanilla WC city text
            if (isset($checkoutFields[$group][$cityKey])) {
                $checkoutFields[$group][$cityKey]['priority'] = 47;

                if ($settleMode === FieldMode::DISABLED) {
                    $checkoutFields[$group][$cityKey]['class'] = array_merge(
                        (array) ($checkoutFields[$group][$cityKey]['class'] ?? []),
                        ['codeon-hidden-row']
                    );
                    $checkoutFields[$group][$cityKey]['required'] = false;
                } elseif ($cascadeWorks) {
                    $checkoutFields[$group][$cityKey]['label']    = __('Settlement', 'codeon-core');
                    $checkoutFields[$group][$cityKey]['type']     = 'select';
                    $checkoutFields[$group][$cityKey]['required'] = $settleMode === FieldMode::REQUIRED;
                    $checkoutFields[$group][$cityKey]['options']  = ['' => __('— pick a Municipality first —', 'codeon-core')];
                    $checkoutFields[$group][$cityKey]['class']    = array_merge(
                        (array) ($checkoutFields[$group][$cityKey]['class'] ?? []),
                        ['form-row-wide', 'codeon-geo-field', 'codeon-geo-city']
                    );
                } else {
                    // Vanilla WC city: text input, only mode-driven required.
                    $checkoutFields[$group][$cityKey]['required'] = $settleMode === FieldMode::REQUIRED;
                }
            }
        }
        return $checkoutFields;
    }

    /**
     * Build the Municipality select options array. All 77 munis with region
     * prefix in the label so users can disambiguate without seeing a state
     * dropdown. Honours the `show_occupied` plugin setting.
     *
     * @return array<string,string> [muni-id => formatted label]
     */
    private function buildMunicipalityOptions(): array
    {
        $repo = \CodeOn\Core\Locations\Data\Repository::instance();
        $fmt  = \CodeOn\Core\Locations\Data\DisplayFormatter::fromOptions();
        $opts = (array) get_option('codeon_core_settings', []);
        $showOccupied = (bool) ($opts['show_occupied'] ?? false);

        $out = ['' => __('Select municipality…', 'codeon-core')];
        foreach ($repo->regions(includeOccupied: $showOccupied) as $region) {
            foreach ($region['municipalities'] as $m) {
                if (!$showOccupied && $m['occupied']) continue;
                // Just the muni name — no region prefix. Region is
                // auto-derived (or visible separately if user enabled the
                // Region field via settings).
                $out[$m['id']] = $fmt->label($m);
            }
        }
        return $out;
    }

    /**
     * Inline CSS that hides classic AND block-checkout fields based on the
     * merchant's Settings → Locations → Field visibility toggles. The
     * classic-checkout hides also work via the `codeon-hidden-row` class
     * we set on the field config, but block checkout doesn't read those
     * classes so we need explicit selectors here.
     */
    private function buildHideCss(): string
    {
        $opts = (array) get_option('codeon_core_settings', []);
        $rules = [];

        // Standard WC field hide toggles (boolean) → CSS selectors.
        $boolMap = [
            'hide_country_field' => [
                '#billing_country_field',
                '#shipping_country_field',
                '.wc-block-components-address-form__country',
            ],
            'hide_company_field' => [
                '#billing_company_field',
                '#shipping_company_field',
                '.wc-block-components-address-form__company',
            ],
            'hide_address_2_field' => [
                '#billing_address_2_field',
                '#shipping_address_2_field',
                '.wc-block-components-address-form__address_2',
            ],
            'hide_postcode_field' => [
                '#billing_postcode_field',
                '#shipping_postcode_field',
                '.wc-block-components-address-form__postcode',
            ],
        ];
        foreach ($boolMap as $key => $selectors) {
            if (!empty($opts[$key])) {
                $rules[] = implode(', ', $selectors) . ' { display: none !important; }';
            }
        }

        // 3-state geo fields. Disabled mode hides via CSS in BOTH classic
        // and block checkout. Region is the typical one (auto-derived
        // from muni) but Muni/Settlement can also be disabled.
        $modeMap = [
            FieldMode::FIELD_REGION => [
                '#billing_state_field',
                '#shipping_state_field',
                '.wc-block-components-address-form__state',
            ],
            FieldMode::FIELD_MUNICIPALITY => [
                '#billing_municipality_field',
                '#shipping_municipality_field',
                '.wc-block-components-address-form__codeon\\/municipality',
                '[id*="codeon-municipality"]',
            ],
            FieldMode::FIELD_SETTLEMENT => [
                '#billing_city_field',
                '#shipping_city_field',
                '.wc-block-components-address-form__city',
                '.wc-block-components-address-form__codeon\\/settlement',
                '[id*="codeon-settlement"]',
            ],
        ];
        foreach ($modeMap as $field => $selectors) {
            if (FieldMode::isDisabled($field)) {
                $rules[] = implode(', ', $selectors) . ' { display: none !important; }';
            }
        }

        return implode("\n", $rules);
    }

    /**
     * Build a [muni-id => wc-state-code] map for the JS cascade. Exposed
     * via wp_localize_script so JS can auto-fill the hidden state field
     * when the user picks a municipality.
     *
     * @return array<string,string>
     */
    private function buildMunToStateMap(): array
    {
        $repo = \CodeOn\Core\Locations\Data\Repository::instance();
        $opts = (array) get_option('codeon_core_settings', []);
        $showOccupied = (bool) ($opts['show_occupied'] ?? false);

        $out = [];
        foreach ($repo->regions(includeOccupied: $showOccupied) as $region) {
            foreach ($region['municipalities'] as $m) {
                if (!$showOccupied && $m['occupied']) continue;
                $out[$m['id']] = $region['wc_state_code'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, array<string,mixed>> $fields
     * @return array<string, array<string,mixed>>
     */
    public function extendDefaultFields(array $fields): array
    {
        $munMode    = FieldMode::resolve(FieldMode::FIELD_MUNICIPALITY);
        $settleMode = FieldMode::resolve(FieldMode::FIELD_SETTLEMENT);
        $cascadeWorks = $munMode !== FieldMode::DISABLED;

        // Convert city to a select only when cascade is viable AND the
        // settlement field is shown. Otherwise leave WC's vanilla text
        // input untouched.
        if (isset($fields['city']) && $cascadeWorks && $settleMode !== FieldMode::DISABLED) {
            $fields['city']['type']    = 'select';
            $fields['city']['options'] = ['' => __('Select…', 'codeon-core')];
            $fields['city']['class']   = array_merge(
                (array) ($fields['city']['class'] ?? []),
                ['codeon-geo-field', 'codeon-geo-city']
            );
            $fields['city']['priority'] = 47;
        }

        // Move state up so it renders top-to-bottom in cascade order.
        if (isset($fields['state'])) {
            $fields['state']['priority'] = 45;
            $fields['state']['class']    = array_merge(
                (array) ($fields['state']['class'] ?? []),
                ['codeon-geo-field', 'codeon-geo-state']
            );
        }

        // Inject our `municipality` field only when its mode allows it.
        if (!$cascadeWorks) {
            return $fields;
        }
        $rebuilt = [];
        foreach ($fields as $key => $cfg) {
            $rebuilt[$key] = $cfg;
            if ($key === 'state') {
                $rebuilt['municipality'] = [
                    'label'    => __('Municipality', 'codeon-core'),
                    'type'     => 'select',
                    'required' => $munMode === FieldMode::REQUIRED,
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
        $regionMode   = FieldMode::resolve(FieldMode::FIELD_REGION);
        $munMode      = FieldMode::resolve(FieldMode::FIELD_MUNICIPALITY);
        $settleMode   = FieldMode::resolve(FieldMode::FIELD_SETTLEMENT);
        $cascadeWorks = $munMode !== FieldMode::DISABLED;

        $stateRequired = match ($regionMode) {
            FieldMode::REQUIRED => true,
            FieldMode::OPTIONAL => false,
            // Disabled + cascade alive → JS auto-fills state, so we can
            // still treat it as required and pass WC validation.
            // Disabled + cascade dead → empty state would block checkout,
            // so relax required.
            default => $cascadeWorks,
        };

        $geMuni = $cascadeWorks
            ? ['required' => $munMode === FieldMode::REQUIRED, 'hidden' => false]
            : ['required' => false, 'hidden' => true];

        $cityLabel    = $cascadeWorks ? __('Settlement', 'codeon-core') : __('City', 'codeon-core');
        $cityRequired = $settleMode === FieldMode::REQUIRED;

        $locales['GE'] = array_replace_recursive(
            $locales['GE'] ?? [],
            [
                'state' => [
                    'required' => $stateRequired,
                    'label'    => __('Region', 'codeon-core'),
                ],
                'municipality' => $geMuni,
                'city' => [
                    'required' => $cityRequired,
                    'label'    => $cityLabel,
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
        // Guard: never inject the municipality field if its mode is Disabled.
        if (FieldMode::isDisabled(FieldMode::FIELD_MUNICIPALITY)) {
            return $fields;
        }

        $key      = $prefix . 'municipality';
        $stateKey = $prefix . 'state';
        $cityKey  = $prefix . 'city';

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
                    'required' => FieldMode::isRequired(FieldMode::FIELD_MUNICIPALITY),
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

        // Hide CSS still useful even when cascade is dead — it carries
        // the country/company/address_2/postcode hides plus the geo-
        // disabled hides for region/settlement.
        $extra = $this->buildHideCss();
        if ($extra !== '') {
            wp_add_inline_style('codeon-core-checkout', $extra);
        }

        // Skip the cascade JS payload entirely when Municipality is
        // disabled — the script would be a no-op (no muni element to
        // bind to) but we save a request + parse cost.
        if (FieldMode::isDisabled(FieldMode::FIELD_MUNICIPALITY)) {
            return;
        }

        wp_enqueue_script(
            'codeon-core-checkout-cascade',
            CODEON_CORE_URL . 'assets/js/checkout-cascade.js',
            ['jquery', 'wc-checkout'],
            CODEON_CORE_VERSION,
            true
        );
        wp_localize_script('codeon-core-checkout-cascade', 'CodeOnGeo', [
            'restUrl'    => esc_url_raw(rest_url('codeon-geo/v1/')),
            'nonce'      => wp_create_nonce('wp_rest'),
            'munToState' => $this->buildMunToStateMap(),
            'i18n'    => [
                'select'           => __('Select municipality…', 'codeon-core'),
                'selectSettlement' => __('Select settlement…', 'codeon-core'),
                'loading'          => __('Loading…', 'codeon-core'),
                'noResults'        => __('No matches', 'codeon-core'),
                'pickMuniFirst'    => __('— pick a Municipality first —', 'codeon-core'),
            ],
        ]);
    }
}
