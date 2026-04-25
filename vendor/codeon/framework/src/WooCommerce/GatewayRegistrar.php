<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce;

/**
 * Tiny static helper around WooCommerce's two registration filters.
 *
 * Hides the `class_exists` guard plus the `woocommerce_payment_gateways` /
 * `woocommerce_blocks_payment_method_type_registration` boilerplate so
 * a typical gateway micro-plugin's entry file collapses to:
 *
 *     GatewayRegistrar::register(TbcCardGateway::class);
 *     GatewayRegistrar::registerBlocks(TbcCardBlocks::class);
 */
final class GatewayRegistrar
{
    /**
     * Append one or more `WC_Payment_Gateway` subclass names to WC's
     * gateway list. No-op if WC isn't loaded.
     *
     * @param class-string ...$gatewayClasses
     */
    public static function register(string ...$gatewayClasses): void
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }
        add_filter('woocommerce_payment_gateways', static function (mixed $gateways) use ($gatewayClasses): array {
            $list = is_array($gateways) ? $gateways : [];
            foreach ($gatewayClasses as $class) {
                if (!in_array($class, $list, true)) {
                    $list[] = $class;
                }
            }
            return $list;
        });
    }

    /**
     * Register PaymentMethodType subclasses with the WC Blocks
     * registry. Each subclass should extend
     * `Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType`.
     *
     * @param class-string ...$blockClasses
     */
    public static function registerBlocks(string ...$blockClasses): void
    {
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            static function ($registry) use ($blockClasses): void {
                if (!is_object($registry) || !method_exists($registry, 'register')) {
                    return;
                }
                foreach ($blockClasses as $class) {
                    if (class_exists($class)) {
                        $registry->register(new $class());
                    }
                }
            }
        );
    }
}
