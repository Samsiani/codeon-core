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

        $ge = [];
        foreach ($repo->regions(includeOccupied: $showOccupied) as $region) {
            $ge[$region['wc_state_code']] = $formatter->label($region);
        }
        $states['GE'] = $ge;
        return $states;
    }
}
