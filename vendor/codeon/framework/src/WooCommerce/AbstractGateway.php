<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce;

use CodeOn\Framework\License\LicenseStore;
use CodeOn\Framework\Logging\Logger;

// The framework is WC-agnostic at the package level. This file is
// only autoloaded when WooCommerce has already loaded its own
// payment-gateway base class, otherwise the `extends WC_Payment_Gateway`
// statement would fatal on plugin boot. Each concrete gateway
// subclass is also gated behind `class_exists('WC_Payment_Gateway')`
// at its own use site.
if (!class_exists('WC_Payment_Gateway')) {
    return;
}

/**
 * Base class for every CodeOn WooCommerce payment gateway.
 *
 * Captures the boilerplate every concrete bank gateway shares so
 * micro-plugins (codeon-tbc-payments, codeon-bog-payments, …) only
 * implement the parts that actually differ:
 *   - payment processing
 *   - bank-specific admin settings
 *   - icon filename
 *
 * The license/module gate is enforced via {@see is_available()};
 * a paused or revoked license disables the gateway server-side
 * before checkout ever queries it. The same gate covers refunds —
 * a license that doesn't carry the `refund` feature on the card
 * module simply won't advertise refunds support to WC.
 */
abstract class AbstractGateway extends \WC_Payment_Gateway
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_PRODUCTION = 'production';

    /** @var string Base URL for icon assets — typically plugins_url('assets/icons/', __FILE__). */
    protected string $iconBaseUrl = '';

    /** Lazy-initialised in the constructor — see resolveLicenseStore(). */
    protected LicenseStore $licenseStore;

    /** Lazy-initialised in the constructor — see resolveLogger(). */
    protected Logger $logger;

    /**
     * WooCommerce instantiates registered gateway classes via
     * `new $class()` (see WC_Payment_Gateways::init_payment_gateways()),
     * so the constructor MUST accept zero arguments. Subclasses
     * provide their plugin's identity by overriding `pluginSlug()`,
     * which the framework uses to construct the LicenseStore + Logger.
     * Subclasses can also override the `resolve*` methods for full
     * control of how the dependencies are built.
     */
    public function __construct()
    {
        $this->licenseStore = $this->resolveLicenseStore();
        $this->logger       = $this->resolveLogger();
        $this->iconBaseUrl  = rtrim($this->resolveIconBaseUrl(), '/');

        $this->id                 = $this->gatewayId();
        // Memory rule: every CodeOn gateway must declare has_fields=true,
        // otherwise WC silently drops the .payment_box div and any
        // custom checkout HTML never renders. (See feedback note in
        // CLAUDE.md re. blank description + has_fields=false bug.)
        $this->has_fields         = true;
        $this->method_title       = $this->methodTitleText();
        $this->method_description = $this->methodDescriptionText();
        $this->supports           = ['products'];

        $iconFile = $this->iconFilename();
        if ($iconFile !== null && $iconFile !== '' && $this->iconBaseUrl !== '') {
            $this->icon = $this->iconBaseUrl . '/' . ltrim($iconFile, '/');
        }

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled     = $this->get_option('enabled');
        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');

        if ($this->licenseFeatureEnabled('refund')) {
            $this->supports[] = 'refunds';
        }

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    // ─── Subclass contract ──────────────────────────────────────────

    /**
     * The WordPress plugin slug (the folder name + textdomain on the
     * WP side, e.g. `codeon-tbc-card-payment`). The framework uses
     * this as the LicenseStore's storage key and as the Logger
     * channel by default. Subclasses MUST override this so each
     * plugin gets its own license/log namespace.
     */
    abstract protected function pluginSlug(): string;

    abstract protected function gatewayId(): string;

    /**
     * The catalog SKU id (license module) that gates this gateway —
     * one of the values returned by validate-license's `modules[]`,
     * e.g. `tbc-card` / `tbc_card`. Both spellings are accepted at
     * gate evaluation time (hyphen vs underscore wire format).
     */
    abstract protected function moduleSlug(): string;

    abstract protected function methodTitleText(): string;

    abstract protected function methodDescriptionText(): string;

    /**
     * Filename inside `$iconBaseUrl` to render next to the gateway
     * title at checkout. Return null to skip.
     */
    protected function iconFilename(): ?string
    {
        return null;
    }

    // ─── Dependency resolution ──────────────────────────────────────

    /**
     * Default LicenseStore is constructed from the plugin slug, which
     * makes WP options stored under `<slug>_license_*`. Subclasses can
     * override entirely (e.g. `return LicenseGate::store();`) when the
     * plugin has its own LicenseStore wiring.
     */
    protected function resolveLicenseStore(): LicenseStore
    {
        return new LicenseStore($this->pluginSlug());
    }

    /**
     * Default Logger channel is the plugin slug. Override if the
     * plugin uses a different channel name.
     */
    protected function resolveLogger(): Logger
    {
        return new Logger($this->pluginSlug());
    }

    /**
     * Where icon files live. Subclasses override to point at their
     * plugin's bundled `assets/icons/` directory — typically
     * `plugins_url('assets/icons/', $plugin_main_file)`. Empty by
     * default so a missing override is silent rather than fatal.
     */
    protected function resolveIconBaseUrl(): string
    {
        return '';
    }

    /**
     * Bank-specific admin settings, merged after the shared fields.
     *
     * @return array<string, array<string,mixed>>
     */
    abstract protected function extraFormFields(): array;

    // ─── Form fields ────────────────────────────────────────────────

    public function init_form_fields(): void
    {
        $shared = [
            'enabled' => [
                'title'   => __('Enable / Disable', 'codeon-framework'),
                'type'    => 'checkbox',
                'label'   => $this->methodTitleText(),
                'default' => 'no',
            ],
            'title' => [
                'title'       => __('Title', 'codeon-framework'),
                'type'        => 'text',
                'description' => __('Shown to customers at checkout.', 'codeon-framework'),
                'default'     => $this->methodTitleText(),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'codeon-framework'),
                'type'        => 'textarea',
                'description' => __('Shown to customers at checkout, below the title.', 'codeon-framework'),
                'default'     => '',
                'desc_tip'    => true,
            ],
            'environment' => [
                'title'       => __('Environment', 'codeon-framework'),
                'type'        => 'select',
                'description' => __('Use sandbox while integrating; switch to production when go-live is approved.', 'codeon-framework'),
                'options'     => [
                    self::ENV_SANDBOX    => __('Sandbox (test)', 'codeon-framework'),
                    self::ENV_PRODUCTION => __('Production', 'codeon-framework'),
                ],
                'default'  => self::ENV_SANDBOX,
                'desc_tip' => true,
            ],
            'debug' => [
                'title'   => __('Debug logging', 'codeon-framework'),
                'type'    => 'checkbox',
                'label'   => __('Log full request/response payloads (secrets redacted).', 'codeon-framework'),
                'default' => 'no',
            ],
        ];

        $this->form_fields = array_merge($shared, $this->extraFormFields());
    }

    // ─── Availability ───────────────────────────────────────────────

    public function is_available(): bool
    {
        if (!parent::is_available()) {
            return false;
        }
        return $this->licenseModuleActive();
    }

    // ─── Helpers for subclasses ─────────────────────────────────────

    protected function environment(): string
    {
        $value = $this->get_option('environment', self::ENV_SANDBOX);
        return self::ENV_PRODUCTION === $value ? self::ENV_PRODUCTION : self::ENV_SANDBOX;
    }

    protected function isSandbox(): bool
    {
        return self::ENV_SANDBOX === $this->environment();
    }

    protected function debugEnabled(): bool
    {
        return 'yes' === $this->get_option('debug', 'no');
    }

    /**
     * Deterministic HMAC bound to an order. Round-trip with the bank
     * so the webhook handler can reject spoofed callbacks. 20 hex
     * chars = 80 bits — fine for short-lived order-binding tags and
     * stays under TBC's 25-char `extra` field cap.
     */
    protected function callbackHmac(\WC_Order $order): string
    {
        $material = sprintf('%d|%s|%s', $order->get_id(), $order->get_order_key(), $this->gatewayId());
        $full = hash_hmac('sha256', $material, wp_salt('auth'));
        return substr($full, 0, 20);
    }

    protected function verifyCallbackHmac(\WC_Order $order, string $hmac): bool
    {
        $short = $this->callbackHmac($order);
        $full = hash_hmac(
            'sha256',
            sprintf('%d|%s|%s', $order->get_id(), $order->get_order_key(), $this->gatewayId()),
            wp_salt('auth')
        );
        return hash_equals($short, $hmac) || hash_equals($full, $hmac);
    }

    protected function logChannel(): string
    {
        return str_replace('codeon_', '', $this->gatewayId());
    }

    protected function logError(string $message, array $context = []): void
    {
        $this->logger->error('[' . $this->logChannel() . '] ' . $message, $context);
    }

    protected function logInfo(string $message, array $context = []): void
    {
        $this->logger->info('[' . $this->logChannel() . '] ' . $message, $context);
    }

    protected function logDebug(string $message, array $context = []): void
    {
        if ($this->debugEnabled()) {
            $this->logger->debug('[' . $this->logChannel() . '] ' . $message, $context);
        }
    }

    /** Generates the REST callback URL the bank should POST to. */
    protected function callbackUrl(string $route): string
    {
        return rest_url('codeon/v1/' . ltrim($route, '/'));
    }

    /**
     * Is the license currently entitled to this gateway's module? A
     * cached read of the LicenseStore — never hits the network from
     * within `is_available()` (called on every checkout repaint).
     */
    protected function licenseModuleActive(): bool
    {
        $status = $this->licenseStore->effectiveStatus();
        if ($status !== 'active' && $status !== 'grace') {
            return false;
        }
        $modules = $this->licenseStore->modules();
        return $this->moduleListMatches($modules, $this->moduleSlug());
    }

    /**
     * Does the snapshot's `features[<module>]` list include the
     * given feature flag? Used to gate refunds support.
     */
    protected function licenseFeatureEnabled(string $feature): bool
    {
        $snap = $this->licenseStore->getSnapshot();
        $features = is_array($snap['features'] ?? null) ? $snap['features'] : [];
        $module = $this->moduleSlug();
        $candidates = [$module, str_replace('-', '_', $module), str_replace('_', '-', $module)];
        foreach ($candidates as $key) {
            if (!isset($features[$key])) {
                continue;
            }
            $list = (array) $features[$key];
            if (in_array($feature, $list, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match an SKU-id ($module) against the snapshot's modules list.
     * codeon.ge stores `tbc-card` (hyphen) and ships `tbc_card`
     * (underscore) over the wire — accept either spelling.
     *
     * @param array<int,string> $modules
     */
    protected function moduleListMatches(array $modules, string $module): bool
    {
        $candidates = [$module, str_replace('-', '_', $module), str_replace('_', '-', $module)];
        foreach ($modules as $entry) {
            if (in_array((string) $entry, $candidates, true)) {
                return true;
            }
        }
        return false;
    }
}
