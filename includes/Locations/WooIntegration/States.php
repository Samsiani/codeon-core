<?php
/**
 * Override WooCommerce's GE state list with our 13 regions.
 *
 * WC core ships 12 GE states with codes TB/AB/AJ/GU/IM/KA/MM/RL/SZ/SJ/KK/SK.
 * We preserve every one of those codes (so existing orders that stored
 * state="TB" continue to validate) and add `TS` for the Tskhinvali region.
 * Display names follow the merchant's display-mode setting.
 *
 * @package CodeOn\Core\Locations\WooIntegration
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\WooIntegration;

defined('ABSPATH') || exit;

use CodeOn\Core\Locations\Data\DisplayFormatter;
use CodeOn\Core\Locations\Data\Repository;
use CodeOn\Core\Locations\Settings\TbilisiMode;
use CodeOn\Framework\Storage\SettingsRepository;

final class States
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function register(): void
    {
        add_filter('woocommerce_states', [$this, 'overrideGeorgia'], 20);
    }

    /**
     * @param array<string, array<string,string>> $states
     * @return array<string, array<string,string>>
     */
    public function overrideGeorgia(array $states): array
    {
        $repo      = Repository::instance();
        $formatter = DisplayFormatter::fromOptions();
        $opts      = (array) get_option('codeon_core_settings', []);
        $showOccupied = (bool) ($opts['show_occupied'] ?? false);

        // NOTE: we deliberately do NOT trim the state list when Tbilisi
        // mode is active. The state list backs admin screens too (the
        // most visible being Settings → WC → Shipping → "Add region"
        // when defining a shipping zone). Filtering the list there
        // would hide 10 of the 13 GE regions from the merchant. Tbilisi
        // mode hides the state FIELD at checkout via CSS + auto-fills
        // the value — that's all that's needed; the catalog of GE
        // regions should stay intact globally.
        $ge = [];
        foreach ($repo->regions(includeOccupied: $showOccupied) as $region) {
            $ge[$region['wc_state_code']] = $formatter->label($region);
        }
        $states['GE'] = $ge;
        return $states;
    }
}
