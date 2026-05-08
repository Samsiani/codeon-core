<?php
/**
 * Locations feature boot — wires every WC-side hook.
 *
 * Called from Plugin::boot() on woocommerce_loaded(20). All WC-dependent
 * code lives here so Plugin::boot() can be safely called even when WC is
 * not active.
 *
 * @package CodeOn\Core\Locations
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations;

defined('ABSPATH') || exit;

use CodeOn\Core\Locations\Rest\Controller as RestController;
use CodeOn\Core\Locations\WooIntegration\AddressFormat;
use CodeOn\Core\Locations\WooIntegration\BlockCheckoutFields;
use CodeOn\Core\Locations\WooIntegration\ClassicCheckoutFields;
use CodeOn\Core\Locations\WooIntegration\OrderMeta;
use CodeOn\Core\Locations\WooIntegration\States;
use CodeOn\Framework\Storage\SettingsRepository;

final class Boot
{
    public static function register(SettingsRepository $settings): void
    {
        // Master switch (LocationsTab → "Enable Georgian Locations cascade at
        // checkout"). Default true so existing merchants keep current behavior
        // on update. When false, NONE of the WC-side hooks below register —
        // checkout falls back to vanilla WooCommerce fields and the typeahead
        // REST endpoints are not exposed.
        if ($settings->get('locations_enabled', true) !== true) {
            return;
        }

        (new States($settings))->register();
        (new ClassicCheckoutFields($settings))->register();
        (new BlockCheckoutFields())->register();
        (new AddressFormat())->register();
        (new OrderMeta())->register();
        (new RestController($settings))->register();
    }
}
