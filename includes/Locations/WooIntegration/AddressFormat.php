<?php
/**
 * Custom Georgian address format for emails, invoices, My Account.
 *
 * WC's default `GE` format is the country fallback — we replace it with
 * a Georgian-friendly layout that includes municipality on its own line.
 *
 * @package CodeOn\Core\Locations\WooIntegration
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\WooIntegration;

defined('ABSPATH') || exit;

final class AddressFormat
{
    public function register(): void
    {
        add_filter('woocommerce_localisation_address_formats', [$this, 'format']);
        add_filter('woocommerce_formatted_address_replacements', [$this, 'replacements'], 10, 2);
        add_filter('woocommerce_order_formatted_billing_address', [$this, 'mergeOrderBilling'], 10, 2);
        add_filter('woocommerce_order_formatted_shipping_address', [$this, 'mergeOrderShipping'], 10, 2);
    }

    /**
     * @param array<string,string> $formats
     * @return array<string,string>
     */
    public function format(array $formats): array
    {
        // {name}
        // {company} (optional)
        // {address_1}
        // {address_2}
        // {city}                 ← settlement
        // {municipality_label}    ← our injected key
        // {state}, {postcode}    ← region + postcode
        // {country}
        $formats['GE'] = "{name}\n{company}\n{address_1}\n{address_2}\n{city}\n{municipality_label}\n{state}, {postcode}\n{country}";
        return $formats;
    }

    /**
     * @param array<string,string> $replacements
     * @param array<string,mixed> $args
     * @return array<string,string>
     */
    public function replacements(array $replacements, array $args): array
    {
        $munLabel = isset($args['municipality_label']) ? (string) $args['municipality_label'] : '';
        $replacements['{municipality_label}'] = $munLabel;
        return $replacements;
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    public function mergeOrderBilling(array $address, \WC_Order $order): array
    {
        $address['municipality_label'] = (string) $order->get_meta('_billing_geo_municipality_label');
        return $address;
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    public function mergeOrderShipping(array $address, \WC_Order $order): array
    {
        $address['municipality_label'] = (string) $order->get_meta('_shipping_geo_municipality_label');
        return $address;
    }
}
