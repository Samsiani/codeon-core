<?php
/**
 * Settings tab — display mode, language, occupied territories toggle,
 * required-field toggles. Uses the framework's Schema/Field DSL so we
 * write zero HTML and the framework handles validation, persistence,
 * and the success notice.
 *
 * @package CodeOn\Core\Locations\Settings
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\Settings;

defined('ABSPATH') || exit;

use CodeOn\Framework\Admin\Tab;
use CodeOn\Framework\Schema\Field;
use CodeOn\Framework\Storage\SettingsRepository;

final class LocationsTab extends Tab
{
    public function __construct(private readonly SettingsRepository $repo) {}

    public function slug(): string
    {
        return 'general';
    }

    public function label(): string
    {
        return __('General', 'codeon-core');
    }

    public function repository(): SettingsRepository
    {
        return $this->repo;
    }

    /**
     * Override the framework's default render to inject a Tbilisi-mode
     * notice banner above the form. Without this, a merchant who
     * enabled Tbilisi mode on the sibling tab and then comes here
     * would see the field-mode dropdowns and not realise their
     * choices are being silently overridden by Tbilisi mode.
     */
    public function render(string $nonceAction): void
    {
        if (TbilisiMode::isActive()) {
            $url = admin_url('admin.php?page=codeon-core&tab=tbilisi');
            ?>
            <div class="notice notice-warning inline" style="margin: 0 0 16px;">
                <p>
                    <strong><?php esc_html_e('Tbilisi mode is active.', 'codeon-core'); ?></strong>
                    <?php esc_html_e('The Region / Municipality / Settlement field modes below are being overridden — checkout uses the Tbilisi-area picker instead. Adjust on the Tbilisi tab to change the area scope, or disable Tbilisi mode there to use these settings again.', 'codeon-core'); ?>
                    <a href="<?php echo esc_url($url); ?>" class="button button-secondary" style="margin-left: 8px;">
                        <?php esc_html_e('Open Tbilisi tab', 'codeon-core'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
        parent::render($nonceAction);
    }

    public function schema(): array
    {
        return [
            Field::heading('h_master', __('Status', 'codeon-core')),

            Field::checkbox('locations_enabled', __('Enable Georgian Locations cascade at checkout', 'codeon-core'))
                ->default(true)
                ->description(__('Master switch for the Locations feature. When ON, the Region → Municipality → Settlement cascade is wired into WooCommerce checkout (classic + block), the address-format on emails / My Account is replaced, and the typeahead REST endpoints are registered. When OFF, none of the above runs and WooCommerce uses its standard built-in checkout fields. Existing order data is never altered either way.', 'codeon-core')),

            Field::heading('h_display', __('Display', 'codeon-core')),

            Field::select('display_mode', __('Label language', 'codeon-core'))
                ->options([
                    'auto'      => __('Automatic — Georgian if site is in Georgian, otherwise Bilingual', 'codeon-core'),
                    'ka'        => __('Always Georgian (კონდოლი)', 'codeon-core'),
                    'en'        => __('Always English (Kondoli) — transliterated', 'codeon-core'),
                    'bilingual' => __('Bilingual — კონდოლი (Kondoli)', 'codeon-core'),
                ])
                ->default('auto')
                ->description(__('How village names appear in the checkout dropdown, on emails, and in the admin. Settlements only have Georgian names natively — English mode uses the Georgian National Romanization System.', 'codeon-core')),

            Field::checkbox('simplified_latin', __('Simplified Latin (no apostrophes)', 'codeon-core'))
                ->default(true)
                ->description(__('Use "k", "p", "t" instead of "kʼ", "pʼ", "tʼ". Most merchants prefer simplified.', 'codeon-core'))
                ->showWhen('display_mode', 'in', ['en', 'bilingual', 'auto']),

            Field::heading('h_geo_fields', __('Geographic fields at checkout', 'codeon-core'),
                __('Pick a mode for each field. Disabled = not shown at all. Optional = shown, blank allowed. Required = shown, customer must fill it. Existing order data is never altered when you change these.', 'codeon-core')),

            Field::select('region_field_mode', __('Region (state) field', 'codeon-core'))
                ->options(FieldMode::choices())
                ->default(FieldMode::DISABLED)
                ->description(__('When Disabled, Region is auto-set from the chosen Municipality (no manual pick needed). When Optional or Required, customers see a Region dropdown and can pick directly — Municipality dropdown then narrows to that region.', 'codeon-core')),

            Field::select('municipality_field_mode', __('Municipality field', 'codeon-core'))
                ->options(FieldMode::choices())
                ->default(FieldMode::REQUIRED)
                ->description(__('Municipality is the main cascade anchor. Disabling it falls Settlement back to a plain text field (no cascade). Recommended: Required for accurate shipping.', 'codeon-core')),

            Field::select('settlement_field_mode', __('Settlement (city / town / village) field', 'codeon-core'))
                ->options(FieldMode::choices())
                ->default(FieldMode::REQUIRED)
                ->description(__('When Municipality is Disabled, Settlement reverts to the standard WooCommerce city text input — this mode then only controls whether that text field is required.', 'codeon-core')),

            Field::heading('h_scope', __('Geographic scope', 'codeon-core')),

            Field::checkbox('show_occupied', __('Include occupied territories', 'codeon-core'))
                ->default(false)
                ->description(__('Adds Abkhazia and the Tskhinvali region to the regions dropdown. Hidden by default since most stores cannot ship there.', 'codeon-core')),

            Field::heading('h_visibility', __('Other checkout field visibility', 'codeon-core'),
                __('Hide standard WooCommerce checkout fields you don\'t need. Hidden fields still record their values (auto-derived where applicable) so tax, shipping zones, and reports continue to work.', 'codeon-core')),

            Field::checkbox('hide_country_field', __('Hide Country / Region field', 'codeon-core'))
                ->default(false)
                ->description(__('Useful for Georgia-only shops. Field stays in the form (so it submits "Georgia") but is invisible.', 'codeon-core')),

            Field::checkbox('hide_company_field', __('Hide Company name field', 'codeon-core'))
                ->default(false),

            Field::checkbox('hide_address_2_field', __('Hide Address line 2 (apartment/suite) field', 'codeon-core'))
                ->default(false),

            Field::checkbox('hide_postcode_field', __('Hide Postcode / ZIP field', 'codeon-core'))
                ->default(false)
                ->description(__('Useful for Georgia where postcodes are rarely required. WC still validates a value if you don\'t hide it.', 'codeon-core')),
        ];
    }

    public function afterSave(array $saved): void
    {
        // The States filter reads codeon_core_settings on every checkout
        // page load, so no cache-bust needed. But WC caches the formatted
        // states for the admin dropdown — flush so the new display mode
        // is visible immediately.
        if (function_exists('WC') && \WC()->countries) {
            // No public flush method, but rebuilding the singleton's
            // internal states cache happens per-request. The admin will
            // pick up the new values on next page load.
        }
    }
}
