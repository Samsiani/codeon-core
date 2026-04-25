<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce;

use CodeOn\Framework\License\LicenseStore;
use CodeOn\Framework\Logging\Logger;

if (!class_exists('WC_Shipping_Method')) {
    return;
}

/**
 * Base class for every CodeOn WooCommerce shipping method.
 *
 * Mirror of {@see AbstractGateway} for the shipping side of the
 * catalog. The license/module gate runs before the method publishes
 * any rates: a paused or revoked license simply hides the method
 * from checkout. Rate calculation, package matching, and the actual
 * courier API call all stay in the concrete subclass — the shared
 * scaffolding is just license enforcement and logging.
 */
abstract class AbstractShippingMethod extends \WC_Shipping_Method
{
    public function __construct(
        protected readonly LicenseStore $licenseStore,
        protected readonly Logger $logger,
        int $instance_id = 0,
    ) {
        parent::__construct($instance_id);
        $this->id           = $this->methodKey();
        $this->method_title = $this->methodTitleText();
        $this->method_description = $this->methodDescriptionText();
        $this->supports     = ['shipping-zones', 'instance-settings'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', $this->methodTitleText());
        $this->enabled = $this->get_option('enabled', 'yes');
    }

    abstract protected function methodKey(): string;
    abstract protected function moduleSlug(): string;
    abstract protected function methodTitleText(): string;
    abstract protected function methodDescriptionText(): string;

    public function is_available($package): bool
    {
        if (!parent::is_available($package)) {
            return false;
        }
        $status = $this->licenseStore->effectiveStatus();
        if ($status !== 'active' && $status !== 'grace') {
            return false;
        }
        $modules = $this->licenseStore->modules();
        $candidates = [
            $this->moduleSlug(),
            str_replace('-', '_', $this->moduleSlug()),
            str_replace('_', '-', $this->moduleSlug()),
        ];
        foreach ($modules as $entry) {
            if (in_array((string) $entry, $candidates, true)) {
                return true;
            }
        }
        return false;
    }
}
