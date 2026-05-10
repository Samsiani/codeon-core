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
use CodeOn\Core\Locations\Settings\TbilisiMode;
use CodeOn\Framework\Storage\SettingsRepository;

final class ClassicCheckoutFields
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function register(): void
    {
        add_filter('woocommerce_default_address_fields', [$this, 'extendDefaultFields']);
        add_filter('woocommerce_get_country_locale', [$this, 'extendGeorgiaLocale']);

        add_filter('woocommerce_billing_fields',  [$this, 'extendBillingFields'], 20);
        add_filter('woocommerce_shipping_fields', [$this, 'extendShippingFields'], 20);

        // Final-pass override at priority 100000 so we beat Woodmart's
        // checkout-fields-manager (which hooks at 99999 and rewrites the
        // entire field array, otherwise stripping our muni / area
        // / locale tweaks).
        add_filter('woocommerce_checkout_fields', [$this, 'enforceFinalFieldSetup'], 100000);

        // Enqueue cascade JS only on checkout / account edit-address pages.
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);

        // Tbilisi-mode-only filters: force the saved-address pre-fill
        // for state/city to Tbilisi values so a returning customer with
        // a non-Tbilisi saved address doesn't smuggle their old state
        // through the hidden input.
        add_filter('woocommerce_checkout_get_value', [$this, 'tbilisiForcedValue'], 10, 2);

        // Translate the picked Area key back to the canonical state +
        // settlement name on submit. Otherwise OrderMeta would persist
        // 'tbilisi' or 's2473' as the city, which breaks reports.
        add_filter('woocommerce_checkout_posted_data', [$this, 'tbilisiResolveAreaKey']);
    }

    /**
     * Override the saved-address value for state/city when Tbilisi mode
     * is active. Without this, a returning customer's pre-Tbilisi-mode
     * address would render in the hidden field and submit with the
     * wrong state code on next order.
     *
     * @param mixed  $value
     * @param string $field
     */
    public function tbilisiForcedValue(mixed $value, string $field): mixed
    {
        if (!TbilisiMode::isActive()) {
            return $value;
        }
        if ($field === 'billing_state' || $field === 'shipping_state') {
            return TbilisiMode::TBILISI_STATE_CODE;
        }
        // Mode A: hidden city defaults to "Tbilisi". Mode B: leave
        // city alone — the customer must pick an area.
        if (TbilisiMode::scope() === TbilisiMode::SCOPE_ONLY
            && ($field === 'billing_city' || $field === 'shipping_city')) {
            return TbilisiMode::TBILISI_DISPLAY_NAME;
        }
        return $value;
    }

    /**
     * Mode B (Tbilisi + surroundings): the city field's submitted value
     * is an area key like 'tbilisi' or 's2473'. Translate that back to
     * the canonical (state code, settlement name) tuple so OrderMeta /
     * reports / shipping zones see real values.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function tbilisiResolveAreaKey(array $data): array
    {
        if (!TbilisiMode::isActive() || TbilisiMode::scope() !== TbilisiMode::SCOPE_PLUS) {
            return $data;
        }
        foreach (['billing', 'shipping'] as $ctx) {
            $cityKey = $ctx . '_city';
            if (!isset($data[$cityKey]) || !is_string($data[$cityKey]) || $data[$cityKey] === '') {
                continue;
            }
            $resolved = TbilisiMode::resolveAreaKey($data[$cityKey]);
            if ($resolved === null) {
                continue;
            }
            $data[$cityKey]               = $resolved['settlement_name'];
            $data[$ctx . '_state']        = $resolved['state'];
            $data[$ctx . '_municipality'] = $resolved['muni_id'];
        }
        return $data;
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
        // Tbilisi-area mode wins over EVERY other location rule.
        if (TbilisiMode::isActive()) {
            return $this->enforceTbilisiFieldSetup($checkoutFields);
        }

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
                    $checkoutFields[$group][$cityKey]['options']  = ['' => __('Choose Settlement', 'codeon-core')];
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
     * Tbilisi-mode field setup. Replaces the Region → Municipality →
     * Settlement cascade with either a hidden auto-fill (Tbilisi only)
     * or a single Area dropdown (Tbilisi + surroundings).
     *
     * Mode A (SCOPE_ONLY):
     *   - state hidden, defaulted to TB
     *   - municipality not added
     *   - city hidden, defaulted to "Tbilisi"
     *
     * Mode B (SCOPE_PLUS):
     *   - state hidden (auto-filled by JS once an Area is picked)
     *   - municipality not added
     *   - city converted to a single Area select labelled "Area"
     *
     * @param array<string, array<string, array<string,mixed>>> $checkoutFields
     * @return array<string, array<string, array<string,mixed>>>
     */
    private function enforceTbilisiFieldSetup(array $checkoutFields): array
    {
        $opts    = (array) get_option('codeon_core_settings', []);
        $scope   = TbilisiMode::scope();
        $isPlus  = $scope === TbilisiMode::SCOPE_PLUS;
        $areas   = TbilisiMode::areaList();

        // Build the Area dropdown options. Always leads with the empty
        // placeholder, then Tbilisi, then each surrounding settlement.
        $areaOptions = ['' => __('— Choose area —', 'codeon-core')];
        foreach ($areas as $a) {
            $areaOptions[$a['key']] = $a['label'];
        }

        // Standard WC field hides (non-geo) still respected.
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

            // Drop the muni field if a previous boot injected it.
            unset($checkoutFields[$group][$group . '_municipality']);

            // WC-field hides.
            foreach ($hideMap as $suffix => $hide) {
                if (!$hide) continue;
                $key = $group . $suffix;
                if (isset($checkoutFields[$group][$key])) {
                    $checkoutFields[$group][$key]['class'] = array_merge(
                        (array) ($checkoutFields[$group][$key]['class'] ?? []),
                        ['codeon-hidden-row']
                    );
                    if ($suffix !== '_country') {
                        $checkoutFields[$group][$key]['required'] = false;
                    }
                }
            }

            $stateKey = $group . '_state';
            $cityKey  = $group . '_city';

            // State (Region): always hidden in Tbilisi mode. Kept on the
            // form so WC tax/shipping has a value, defaulted to TB and
            // overwritten by JS when the customer picks an area in
            // Mode B.
            if (isset($checkoutFields[$group][$stateKey])) {
                $checkoutFields[$group][$stateKey]['priority'] = 45;
                $checkoutFields[$group][$stateKey]['class']    = array_merge(
                    (array) ($checkoutFields[$group][$stateKey]['class'] ?? []),
                    ['codeon-hidden-row', 'codeon-tbilisi-state']
                );
                $checkoutFields[$group][$stateKey]['default']  = TbilisiMode::TBILISI_STATE_CODE;
                $checkoutFields[$group][$stateKey]['required'] = true;
            }

            // City: in Mode A hidden + defaulted to Tbilisi. In Mode B
            // becomes the Area picker.
            if (isset($checkoutFields[$group][$cityKey])) {
                $checkoutFields[$group][$cityKey]['priority'] = 47;
                if ($isPlus) {
                    $checkoutFields[$group][$cityKey]['type']     = 'select';
                    $checkoutFields[$group][$cityKey]['label']    = __('Area', 'codeon-core');
                    $checkoutFields[$group][$cityKey]['required'] = true;
                    $checkoutFields[$group][$cityKey]['options']  = $areaOptions;
                    $checkoutFields[$group][$cityKey]['class']    = array_merge(
                        (array) ($checkoutFields[$group][$cityKey]['class'] ?? []),
                        ['form-row-wide', 'codeon-tbilisi-area']
                    );
                } else {
                    $checkoutFields[$group][$cityKey]['class'] = array_merge(
                        (array) ($checkoutFields[$group][$cityKey]['class'] ?? []),
                        ['codeon-hidden-row', 'codeon-tbilisi-city']
                    );
                    $checkoutFields[$group][$cityKey]['default']  = TbilisiMode::TBILISI_DISPLAY_NAME;
                    $checkoutFields[$group][$cityKey]['required'] = true;
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

        $out = ['' => __('Choose Municipality', 'codeon-core')];
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

        // Tbilisi mode hides geography dropdowns wholesale. Mode A
        // hides every geo field; Mode B hides Region + Municipality
        // and keeps City as the Area picker.
        if (TbilisiMode::isActive()) {
            $tbHides = [
                '#billing_state_field',
                '#shipping_state_field',
                '.wc-block-components-address-form__state',
                '#billing_municipality_field',
                '#shipping_municipality_field',
                '.wc-block-components-address-form__codeon\\/municipality',
                '[id*="codeon-municipality"]',
            ];
            if (TbilisiMode::scope() === TbilisiMode::SCOPE_ONLY) {
                $tbHides = array_merge($tbHides, [
                    '#billing_city_field',
                    '#shipping_city_field',
                    '.wc-block-components-address-form__city',
                    '.wc-block-components-address-form__codeon\\/settlement',
                    '[id*="codeon-settlement"]',
                ]);
            }
            $rules[] = implode(', ', $tbHides) . ' { display: none !important; }';
        }

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
        // Skip in Tbilisi mode — Tbilisi mode dictates its own visibility
        // and would conflict with FieldMode in Mode B (where the city
        // field MUST stay visible as the Area picker even if General-tab
        // Settlement mode says Disabled).
        if (TbilisiMode::isActive()) {
            return implode("\n", $rules);
        }
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
        // Tbilisi mode short-circuits the cascade entirely — let
        // enforceFinalFieldSetup do all the field surgery.
        if (TbilisiMode::isActive()) {
            return $fields;
        }

        $munMode    = FieldMode::resolve(FieldMode::FIELD_MUNICIPALITY);
        $settleMode = FieldMode::resolve(FieldMode::FIELD_SETTLEMENT);
        $cascadeWorks = $munMode !== FieldMode::DISABLED;

        // Convert city to a select only when cascade is viable AND the
        // settlement field is shown. Otherwise leave WC's vanilla text
        // input untouched.
        if (isset($fields['city']) && $cascadeWorks && $settleMode !== FieldMode::DISABLED) {
            $fields['city']['type']    = 'select';
            $fields['city']['options'] = ['' => __('Choose Settlement', 'codeon-core')];
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
                    'options'  => ['' => __('Choose Municipality', 'codeon-core')],
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
        // Tbilisi-mode locale: state is always required (defaulted to
        // TB and hidden), city is required (Area picker in Mode B,
        // hidden+defaulted in Mode A). Municipality is irrelevant
        // because the field isn't on the form.
        if (TbilisiMode::isActive()) {
            $isPlus = TbilisiMode::scope() === TbilisiMode::SCOPE_PLUS;
            $locales['GE'] = array_replace_recursive(
                $locales['GE'] ?? [],
                [
                    'state' => [
                        'required'    => true,
                        'label'       => __('Region', 'codeon-core'),
                        'placeholder' => __('Choose Region', 'codeon-core'),
                        'hidden'      => true,
                    ],
                    'municipality' => ['hidden' => true, 'required' => false],
                    'city' => [
                        'required'    => true,
                        'label'       => $isPlus ? __('Area', 'codeon-core') : __('City', 'codeon-core'),
                        'placeholder' => $isPlus ? __('— Choose area —', 'codeon-core') : '',
                    ],
                ]
            );
            return $locales;
        }

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
                    'required'    => $stateRequired,
                    'label'       => __('Region', 'codeon-core'),
                    'placeholder' => __('Choose Region', 'codeon-core'),
                ],
                'municipality' => $geMuni + ['placeholder' => __('Choose Municipality', 'codeon-core')],
                'city' => [
                    'required'    => $cityRequired,
                    'label'       => $cityLabel,
                    'placeholder' => $cascadeWorks
                        ? __('Choose Settlement', 'codeon-core')
                        : __('City', 'codeon-core'),
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
        // Tbilisi mode never wants a municipality field on the form.
        if (TbilisiMode::isActive()) {
            unset($fields[$prefix . 'municipality']);
            return $fields;
        }
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
                    'options'  => ['' => __('Choose Municipality', 'codeon-core')],
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

        // Hide CSS still useful — it carries the country/company/
        // address_2/postcode hides plus the geo-disabled hides for
        // region/settlement and now also the Tbilisi-mode hides.
        $extra = $this->buildHideCss();
        if ($extra !== '') {
            wp_add_inline_style('codeon-core-checkout', $extra);
        }

        // Tbilisi mode replaces the cascade. Mode A needs no script.
        // Mode B ships the area-picker JS so picking an Area auto-fills
        // the hidden state field.
        if (TbilisiMode::isActive()) {
            if (TbilisiMode::scope() === TbilisiMode::SCOPE_PLUS) {
                wp_enqueue_script(
                    'codeon-core-tbilisi-area',
                    CODEON_CORE_URL . 'assets/js/tbilisi-area.js',
                    ['jquery', 'wc-checkout'],
                    CODEON_CORE_VERSION,
                    true
                );
                wp_localize_script('codeon-core-tbilisi-area', 'CodeOnTbilisi', [
                    'areas' => array_map(
                        static fn(array $a) => [
                            'key'     => $a['key'],
                            'state'   => $a['state'],
                            'muni_id' => $a['muni_id'],
                            'city'    => $a['settlement_name'],
                        ],
                        TbilisiMode::areaList()
                    ),
                ]);
            }
            return;
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
                'select'           => __('Choose Municipality', 'codeon-core'),
                'selectSettlement' => __('Choose Settlement', 'codeon-core'),
                'loading'          => __('Loading…', 'codeon-core'),
                'noResults'        => __('No matches', 'codeon-core'),
                'pickMuniFirst'    => __('Choose Settlement', 'codeon-core'),
            ],
        ]);
    }
}
