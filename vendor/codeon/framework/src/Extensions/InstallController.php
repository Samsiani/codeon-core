<?php

declare(strict_types=1);

namespace CodeOn\Framework\Extensions;

use CodeOn\Framework\License\LicenseClient;

/**
 * AJAX handler for the Extensions tab "Unlock" flow.
 *
 * Three independent gates run before any code touches the
 * filesystem: nonce + capability check, signed validate-license
 * call against codeon.ge, and an entitlement check that the
 * license actually unlocks the requested plugin (its module is in
 * the plugin's gate). Only then do we ask the codeon.ge update
 * manifest for a download URL and hand it to WP's
 * {@see \Plugin_Upgrader}, which downloads + unzips + activates
 * the plugin via WP_Filesystem.
 *
 * The download URL we receive carries the merchant's license key
 * + site URL — the streamer's license-key auth path re-validates
 * the same gates server-side, so an attacker who tampers with the
 * URL still hits a 4xx instead of an unauthenticated download.
 *
 * Per-plugin license keys are stored in the option named
 * `<pluginSlug>_license_key` so the freshly-installed plugin's
 * own LicenseAdapter picks them up on first boot. No global key
 * store — each plugin owns its key.
 */
final class InstallController
{
    public const ACTION = 'codeon_install';
    public const NONCE_ACTION = 'codeon-extensions-install';

    public function __construct(
        private readonly CatalogClient $catalog,
        private readonly LicenseClient $licenseClient,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        // 1. Nonce + capability — guard the endpoint before any work.
        $this->guardRequest();

        $pluginId   = isset($_POST['plugin_id']) ? sanitize_text_field((string) $_POST['plugin_id']) : '';
        $pluginSlug = isset($_POST['plugin_slug']) ? sanitize_text_field((string) $_POST['plugin_slug']) : '';
        $licenseKey = isset($_POST['license_key']) ? trim((string) $_POST['license_key']) : '';

        if ($pluginId === '' || $pluginSlug === '' || $licenseKey === '') {
            $this->failure('missing_fields', __('Plugin id, slug, and license key are required.', 'codeon-framework'));
        }

        // 2. Cross-check the requested plugin against our cached catalog.
        $catalog = $this->catalog->fetch();
        if ($catalog === null) {
            $this->failure('catalog_unavailable', __('The CodeOn catalog is temporarily unreachable. Try again in a minute.', 'codeon-framework'));
        }
        $plugin = $catalog->findByPluginId($pluginId);
        if ($plugin === null || $plugin->pluginSlug !== $pluginSlug) {
            $this->failure('unknown_plugin', __('That plugin is not in the catalog. Refresh the Extensions tab and try again.', 'codeon-framework'));
        }

        // 3. Validate the license against codeon.ge. Server returns a
        // signed response we verify with the framework's pinned
        // public key. validate-license also binds this site's domain
        // to the license on first call (no separate activation step).
        $validation = $this->licenseClient->validate(
            licenseKey: $licenseKey,
            siteUrl: home_url('/'),
            pluginVersion: '0.0.0'
        );
        if (!$validation['ok']) {
            $this->failure('license_rejected', $validation['error'] ?? __('License validation failed.', 'codeon-framework'));
        }
        $response = (array) ($validation['response'] ?? []);
        $status   = (string) ($response['status'] ?? '');
        if ($status !== 'active' && $status !== 'grace') {
            $this->failure(
                'license_inactive',
                sprintf(
                    /* translators: %s — codeon.ge license status */
                    __('License is "%s". Contact support@codeon.ge to resolve.', 'codeon-framework'),
                    $status
                )
            );
        }

        // 4. Entitlement: the licensed module must be one of the
        // SKUs that grant access to this plugin's ZIP. The catalog
        // already lists those SKUs as `products[]`; we accept any
        // returned module that matches.
        $modules        = (array) ($response['modules'] ?? []);
        $eligibleSkuIds = array_map(
            static fn ($p) => $p->id,
            $plugin->products
        );
        // The catalog products live as hyphenated SKU ids (tbc-card),
        // while validate-license returns the underscore form
        // (tbc_card). Compare in both directions so the gate is
        // resilient to either spelling — the underscore form is the
        // long-term plugin contract.
        $hasMatch = false;
        foreach ($modules as $mod) {
            $candidate = (string) $mod;
            $hyphen    = str_replace('_', '-', $candidate);
            if (in_array($candidate, $eligibleSkuIds, true) || in_array($hyphen, $eligibleSkuIds, true)) {
                $hasMatch = true;
                break;
            }
        }
        if (!$hasMatch) {
            $this->failure(
                'plugin_not_in_license',
                __('That license does not include this plugin. Check the receipt for the correct key.', 'codeon-framework')
            );
        }

        // 5. Ask the update manifest for the canonical download URL.
        $download = $this->fetchDownloadUrl($pluginId, $licenseKey);
        if ($download === null) {
            $this->failure('manifest_failed', __('Could not resolve the download URL. Try again or contact support.', 'codeon-framework'));
        }

        // 6. Run Plugin_Upgrader. WP loads its filesystem class on
        // demand; we wrap include + WP_Filesystem so the entry path
        // is self-contained.
        $installResult = $this->runUpgrader($download['url']);
        if (is_wp_error($installResult)) {
            /** @var \WP_Error $installResult */
            $this->failure('install_failed', $installResult->get_error_message());
        }

        // 7. Activate (if not already active from a prior install) +
        // persist the license key under the per-plugin convention.
        $pluginFile = $this->resolveInstalledPluginFile($pluginSlug);
        if ($pluginFile === null) {
            $this->failure('install_unverifiable', __('The plugin was downloaded but we could not locate its main file. Activate it manually from Plugins.', 'codeon-framework'));
        }
        if (!is_plugin_active($pluginFile)) {
            $activation = activate_plugin($pluginFile);
            if (is_wp_error($activation)) {
                $this->failure('activation_failed', $activation->get_error_message());
            }
        }

        update_option($pluginSlug . '_license_key', $licenseKey, false);

        wp_send_json_success([
            'plugin_slug' => $pluginSlug,
            'plugin_file' => $pluginFile,
            'version'     => $download['version'],
            'redirect'    => add_query_arg(['page' => $pluginSlug], admin_url('admin.php')),
        ]);
    }

    // ---------------------------------------------------------------------

    private function guardRequest(): void
    {
        if (!current_user_can('install_plugins') || !current_user_can('activate_plugins')) {
            wp_send_json_error(
                ['code' => 'forbidden', 'message' => __('You do not have permission to install plugins.', 'codeon-framework')],
                403
            );
        }
        $nonce = isset($_POST['_ajax_nonce']) ? (string) $_POST['_ajax_nonce'] : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(
                ['code' => 'bad_nonce', 'message' => __('Security check failed. Reload the Extensions tab and try again.', 'codeon-framework')],
                403
            );
        }
    }

    /**
     * @return array{url:string, version:string}|null
     */
    private function fetchDownloadUrl(string $pluginId, string $licenseKey): ?array
    {
        $url = add_query_arg(
            [
                'license_key'     => $licenseKey,
                'site_url'        => home_url('/'),
                'current_version' => '0.0.0',
            ],
            'https://codeon.ge/api/v1/updates/' . rawurlencode($pluginId)
        );

        $response = wp_remote_get($url, [
            'timeout' => 12,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['download_url']) || empty($body['version'])) {
            return null;
        }
        return [
            'url'     => (string) $body['download_url'],
            'version' => (string) $body['version'],
        ];
    }

    /**
     * Wraps {@see \Plugin_Upgrader::install()} so the include path is
     * self-contained. Returns a WP_Error on failure or an array on
     * success (we ignore the array — upgrader populates it inline).
     */
    private function runUpgrader(string $packageUrl): mixed
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

        // WP_Filesystem must be initialised inside the AJAX request
        // before Plugin_Upgrader writes anything.
        if (!\WP_Filesystem()) {
            return new \WP_Error(
                'fs_init_failed',
                __('WordPress filesystem is unavailable. Try uploading the plugin manually from Plugins → Add new.', 'codeon-framework')
            );
        }

        $upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
        $result = $upgrader->install($packageUrl, [
            'overwrite_package' => false,
        ]);

        if ($result === false) {
            $errors = $upgrader->skin instanceof \WP_Upgrader_Skin
                ? $upgrader->skin->get_errors()
                : null;
            if ($errors instanceof \WP_Error && $errors->has_errors()) {
                return $errors;
            }
            return new \WP_Error(
                'install_unknown',
                __('Plugin install failed without a specific error. Check the WP debug log.', 'codeon-framework')
            );
        }

        return $result;
    }

    /**
     * After install, look up the freshly-unpacked plugin's main
     * file (e.g. `fina-sync/fina-sync.php`) so we can activate it.
     */
    private function resolveInstalledPluginFile(string $pluginSlug): ?string
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // get_plugins() returns an associative array keyed by
        // `<folder>/<file>.php`. Match the folder we just installed.
        foreach (get_plugins() as $file => $data) {
            if (str_starts_with($file, $pluginSlug . '/')) {
                return $file;
            }
        }
        return null;
    }

    /**
     * @return never
     */
    private function failure(string $code, string $message, int $status = 400): void
    {
        wp_send_json_error(
            ['code' => $code, 'message' => $message],
            $status
        );
    }
}
