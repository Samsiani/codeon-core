<?php
/**
 * Persist structured location data on each order.
 *
 * The customer's checkout selections are validated against our dataset
 * (the `city` field is one of the settlement names we offered, the
 * `municipality` is one of the muns we offered, etc.), and we save:
 *
 *   _billing_geo_region_id            "kakheti"
 *   _billing_geo_municipality_id      "telavi"
 *   _billing_geo_municipality_label   "თელავის მუნიციპალიტეტი"   (denormalized for safety)
 *   _billing_geo_settlement_id        2473
 *   _billing_geo_settlement_name      "კონდოლი"                  (denormalized)
 *
 * Plus the same on shipping. HPOS-compatible (we use $order->update_meta_data
 * + save, not direct postmeta writes).
 *
 * @package CodeOn\Core\Locations\WooIntegration
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\WooIntegration;

defined('ABSPATH') || exit;

use CodeOn\Core\Locations\Data\Repository;

final class OrderMeta
{
    public function register(): void
    {
        add_action('woocommerce_checkout_create_order', [$this, 'persist'], 10, 2);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function persist(\WC_Order $order, array $data): void
    {
        if (($data['billing_country'] ?? '') !== 'GE') {
            return;
        }

        $repo = Repository::instance();
        $this->persistContext($order, $data, 'billing', $repo);

        if (!empty($data['ship_to_different_address'])) {
            $this->persistContext($order, $data, 'shipping', $repo);
        } else {
            // Mirror billing into shipping so reports / shipping zones see
            // the same structured data even when "ship to different
            // address" is unchecked.
            $this->mirror($order, 'billing', 'shipping');
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function persistContext(\WC_Order $order, array $data, string $ctx, Repository $repo): void
    {
        $stateCode = (string) ($data["{$ctx}_state"] ?? '');
        $munId     = (string) ($data["{$ctx}_municipality"] ?? '');
        $cityName  = (string) ($data["{$ctx}_city"] ?? '');

        // Defense-in-depth: state field is hidden in the cascade UX, so it
        // should already be auto-set by JS. If it isn't (JS disabled, browser
        // quirks), derive it from the chosen municipality so the order still
        // has a state code for tax/shipping calculations and reports.
        $mun = $munId !== '' ? $repo->municipality($munId) : null;
        if ($stateCode === '' && $mun !== null) {
            $munRegion = $repo->region($mun['region_id']);
            if ($munRegion !== null) {
                $stateCode = $munRegion['wc_state_code'];
                $setter = "set_{$ctx}_state";
                if (method_exists($order, $setter)) {
                    $order->{$setter}($stateCode);
                }
            }
        }

        $region = $stateCode !== '' ? $repo->regionByWcCode($stateCode) : null;
        if ($region === null) {
            return;
        }
        $order->update_meta_data("_{$ctx}_geo_region_id", $region['id']);

        if ($mun !== null) {
            $order->update_meta_data("_{$ctx}_geo_municipality_id", $mun['id']);
            $order->update_meta_data("_{$ctx}_geo_municipality_label", $mun['name_ka']);
        }

        // Resolve settlement by name within the chosen municipality
        // (cheaper than a global search and validates the customer
        // didn't free-type a name).
        if ($mun !== null && $cityName !== '') {
            foreach ($mun['settlements'] as $s) {
                if ($s['name_ka'] === $cityName) {
                    $order->update_meta_data("_{$ctx}_geo_settlement_id", (int) $s['id']);
                    $order->update_meta_data("_{$ctx}_geo_settlement_name", $s['name_ka']);
                    break;
                }
            }
        }
    }

    private function mirror(\WC_Order $order, string $from, string $to): void
    {
        foreach (['region_id', 'municipality_id', 'municipality_label', 'settlement_id', 'settlement_name'] as $key) {
            $value = $order->get_meta("_{$from}_geo_{$key}");
            if ($value !== '') {
                $order->update_meta_data("_{$to}_geo_{$key}", $value);
            }
        }
    }
}
