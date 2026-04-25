<?php

declare(strict_types=1);

namespace CodeOn\Framework\Admin;

use CodeOn\Framework\Plugin\Manifest;

/**
 * Enqueues codeon-admin.css/.js, but only on pages owned by a registered
 * CodeOn manifest. Plugins extend with their own assets via the
 * `codeon/admin/enqueue` action which fires only when the framework's
 * predicate has already gated the request.
 *
 * The CSS and JS files live inside the framework package (vendored under
 * `vendor/codeon/framework/assets/`); this class resolves the URL relative
 * to the package directory regardless of where the host plugin vendors it.
 */
final class Assets
{
    public function __construct(private readonly Manifest $manifest)
    {
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hookSuffix): void
    {
        if (!$this->isCodeonPage($hookSuffix)) {
            return;
        }

        $base = $this->packageUrl();
        $version = $this->manifest->version !== '' ? $this->manifest->version : null;

        wp_enqueue_style(
            'codeon-framework-admin',
            $base . 'assets/css/codeon-admin.css',
            [],
            $version
        );
        wp_enqueue_script(
            'codeon-framework-admin',
            $base . 'assets/js/codeon-admin.js',
            [],
            $version,
            true
        );
        wp_localize_script(
            'codeon-framework-admin',
            'CodeOnFramework',
            [
                'pluginSlug' => $this->manifest->slug,
                'i18n'       => [
                    'confirmDestructive' => __('This action cannot be undone. Continue?', 'codeon-framework'),
                    'copied'             => __('Copied to clipboard.', 'codeon-framework'),
                ],
            ]
        );

        do_action('codeon/admin/enqueue', $hookSuffix, $this->manifest);
    }

    public function isCodeonPage(string $hookSuffix): bool
    {
        if ($hookSuffix === '') {
            return false;
        }
        return in_array($hookSuffix, $this->manifest->hookSuffixes(), true);
    }

    private function packageUrl(): string
    {
        // The framework lives at <plugin>/vendor/codeon/framework/. Walk up
        // from this file's directory to compute the URL the WordPress loader
        // can resolve.
        $packageDir = dirname(__DIR__, 2);
        $contentDir = WP_CONTENT_DIR;
        $contentUrl = content_url();

        if (str_starts_with($packageDir, $contentDir)) {
            $relative = substr($packageDir, strlen($contentDir));
            return trailingslashit($contentUrl . $relative);
        }
        // Fallback for unusual installs (mu-plugins, symlinked vendor):
        // use a filter so the plugin can override.
        $fallback = (string) apply_filters(
            'codeon/admin/assets_url',
            content_url('/plugins/codeon-framework/')
        );
        return trailingslashit($fallback);
    }
}
