<?php

declare(strict_types=1);

namespace CodeOn\Framework\Admin;

use CodeOn\Framework\License\LicenseAdapter;
use CodeOn\Framework\License\LicenseTabPresenter;
use CodeOn\Framework\Storage\SettingsRepository;

/**
 * Concrete framework tab that renders the standard License & Updates UI.
 *
 * UX contract (v0.1.3):
 *  - No internal "License status" heading inside the section. The
 *    chrome header band already shows the status pill — duplicating
 *    the label inside the section is noise.
 *  - When the licence is ACTIVE, the "License key" input is hidden;
 *    swapping keys is a deliberate two-step (Release → input appears
 *    → re-Activate). Action buttons (Refresh / Release) live directly
 *    under the meta dl so the merchant doesn't have to scroll.
 *  - When the licence is NOT active (inactive / expired / grace), the
 *    key input + Activate button appear in the same section so the
 *    common "fix it" flow is one screen, not two.
 *  - The "Plan includes" section enumerates every plugin slug the
 *    license unlocks (`LicenseAdapter::features()`). Multi-plugin
 *    bundles read each label from `\CodeOn\Framework\License\KnownPlugins`.
 */
final class LicenseTab extends Tab
{
    private LicenseTabPresenter $presenter;

    public function __construct(
        private readonly LicenseAdapter $adapter,
        private readonly string $slug = 'license',
        private readonly ?string $label = null,
    ) {
        $this->presenter = new LicenseTabPresenter($adapter);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function label(): string
    {
        return $this->label ?? __('License & Updates', 'codeon-framework');
    }

    public function dotTone(): ?string
    {
        return match ($this->adapter->status()) {
            LicenseAdapter::STATUS_ACTIVE   => HealthCard::TONE_OK,
            LicenseAdapter::STATUS_GRACE    => HealthCard::TONE_WARN,
            LicenseAdapter::STATUS_EXPIRED,
            LicenseAdapter::STATUS_INACTIVE => HealthCard::TONE_ERR,
            default                         => null,
        };
    }

    public function repository(): ?SettingsRepository
    {
        // License storage lives inside the plugin's existing License stack,
        // not in a settings option. The adapter handles persistence.
        return null;
    }

    public function actions(): array
    {
        return [
            'activate' => function (array $payload): void {
                $key = isset($payload['license_key']) ? trim((string) $payload['license_key']) : '';
                if ($key === '') {
                    Notices::add(__('Enter a license key.', 'codeon-framework'), 'error');
                    return;
                }
                $r = $this->adapter->activate($key);
                Notices::add($r['message'], $r['ok'] ? 'success' : 'error');
            },
            'release'  => function (): void {
                $r = $this->adapter->release();
                Notices::add($r['message'], $r['ok'] ? 'success' : 'error');
            },
            'refresh'  => function (): void {
                $r = $this->adapter->refresh();
                Notices::add($r['message'], $r['ok'] ? 'success' : 'error');
            },
        ];
    }

    public function render(string $nonceAction): void
    {
        $snap = $this->adapter->snapshot();
        $status = $this->adapter->status();
        $isActive = $status === LicenseAdapter::STATUS_ACTIVE;
        // Grace counts as "currently usable" for the action-form
        // branch (we don't want to hide the Release/Refresh buttons),
        // but expired/revoked is "blocked" — those drop the meta dl
        // and surface a renewal prompt instead.
        $isBlocked = $status === LicenseAdapter::STATUS_EXPIRED;

        // One section: status meta + actions + (conditional) key input.
        echo '<section class="codeon-section codeon-license-section">';

        if ($isBlocked) {
            // Honor user request (2026-05-21): when license is fully
            // expired or revoked, replace the "Plan: Active" meta dl
            // with an unambiguous notice. The action form below still
            // renders so the merchant can paste a fresh key.
            $message = match (true) {
                !empty($snap['last_error'])
                    => sprintf(
                        __('License is no longer active. %s', 'codeon-framework'),
                        (string) $snap['last_error']
                    ),
                default
                    => __(
                        'License is no longer active. Plugin functionality is suspended until you renew or paste a new key.',
                        'codeon-framework'
                    ),
            };
            echo '<div class="codeon-license-blocked notice notice-error inline" style="margin:0 0 12px;padding:10px 14px;">';
            echo '<p style="margin:0;">' . esc_html($message) . '</p>';
            if (!empty($snap['key_masked'])) {
                echo '<p style="margin:6px 0 0;color:#646970;font-size:12.5px;">';
                echo esc_html(sprintf(
                    __('Previously bound key: %s', 'codeon-framework'),
                    (string) $snap['key_masked']
                ));
                echo '</p>';
            }
            echo '</div>';
        } else {
            echo '<dl class="codeon-license-meta">';
            if (!empty($snap['key_masked'])) {
                $this->renderMetaRow(__('Key', 'codeon-framework'), (string) $snap['key_masked']);
            }
            if (!empty($snap['plan'])) {
                $this->renderMetaRow(__('Plan', 'codeon-framework'), (string) $snap['plan']);
            }
            if (!empty($snap['bound_domain'])) {
                $this->renderMetaRow(__('Bound domain', 'codeon-framework'), (string) $snap['bound_domain']);
            }
            if (!empty($snap['expires_at'])) {
                $this->renderMetaRow(
                    __('Expires', 'codeon-framework'),
                    date_i18n(get_option('date_format'), (int) $snap['expires_at'])
                );
            }
            if (!empty($snap['last_check'])) {
                $this->renderMetaRow(
                    __('Last checked', 'codeon-framework'),
                    human_time_diff((int) $snap['last_check']) . ' ' . __('ago', 'codeon-framework')
                );
            }
            if (!empty($snap['last_error'])) {
                $this->renderMetaRow(__('Last error', 'codeon-framework'), (string) $snap['last_error']);
            }
            echo '</dl>';
        }

        $this->renderActionForm($nonceAction, $isActive, $this->pluginSlug);

        echo '</section>';

        $features = $this->adapter->features();
        if ($features !== []) {
            echo '<section class="codeon-section">';
            echo '<h2 class="codeon-section-h2">' . esc_html__('Plan includes', 'codeon-framework') . '</h2>';
            echo '<ul class="codeon-feature-list">';
            foreach ($features as $f) {
                echo '<li>' . esc_html($f) . '</li>';
            }
            echo '</ul>';
            echo '</section>';
        }
    }

    private function renderMetaRow(string $label, string $value): void
    {
        echo '<div class="codeon-license-meta-row">';
        echo '<dt>' . esc_html($label) . '</dt>';
        echo '<dd>' . esc_html($value) . '</dd>';
        echo '</div>';
    }

    private function renderActionForm(string $nonceAction, bool $isActive, string $pluginSlug = ''): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="codeon-license-form">';
        wp_nonce_field($nonceAction);
        echo '<input type="hidden" name="action" value="codeon_tab_action" />';
        echo '<input type="hidden" name="codeon_tab" value="' . esc_attr($this->slug()) . '" />';
        // Plugin-slug discriminator — same purpose as the one
        // FieldRenderer::renderForm() injects (v0.3.4). Without it,
        // every co-installed framework consumer's
        // admin_post_codeon_tab_action handler runs on every License-
        // tab Refresh / Release / Activate click; the first one wins,
        // calls guardWriteRequest, and wp_die's on a nonce minted for
        // a different plugin's form. v0.3.4 fixed the Save form but
        // missed this one.
        //
        // v0.3.7+: take the slug from `Page::render()` directly. The
        // legacy "strip codeon_admin_ prefix" path was wrong for
        // plugins that override `Manifest::nonce()` to a non-default
        // value (codeon-payments → codeon_payments_admin, fina-sync
        // → fina_sync_admin) — the regex didn't match, the wrong slug
        // ended up in the field, `Page::isOurPost()` returned false,
        // every handler bailed, admin-post.php produced an empty
        // page (the "Refresh now / Release this domain → blank
        // screen" bug). Fallback regex stays for callers that still
        // pass only the nonce action.
        $resolvedSlug = $pluginSlug !== ''
            ? $pluginSlug
            : (string) preg_replace('/^codeon_admin_/', '', $nonceAction);
        echo '<input type="hidden" name="codeon_plugin_slug" value="' . esc_attr($resolvedSlug) . '" />';

        if (!$isActive) {
            echo '<label class="codeon-license-key-label" for="codeon_license_key">'
                . esc_html__('License key', 'codeon-framework')
                . '</label>';
            echo '<input type="text" id="codeon_license_key" name="codeon[license_key]" value="" class="regular-text codeon-input codeon-license-key-input" autocomplete="off" />';
        }

        echo '<div class="codeon-license-actions">';
        if (!$isActive) {
            printf(
                '<button type="submit" name="codeon_action" value="activate" class="button button-primary codeon-button">%s</button>',
                esc_html__('Activate / Save', 'codeon-framework')
            );
        }
        printf(
            '<button type="submit" name="codeon_action" value="refresh" class="button codeon-button">%s</button>',
            esc_html__('Refresh now', 'codeon-framework')
        );
        printf(
            '<button type="submit" name="codeon_action" value="release" class="button button-link-delete codeon-button" data-codeon-confirm="%s">%s</button>',
            esc_attr__('Release this domain binding? You will need to re-activate to use the license here again.', 'codeon-framework'),
            esc_html__('Release this domain', 'codeon-framework')
        );
        echo '</div>';
        echo '</form>';
    }
}
