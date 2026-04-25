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
        $isActive = $this->adapter->status() === LicenseAdapter::STATUS_ACTIVE;

        // One section: status meta + actions + (conditional) key input.
        echo '<section class="codeon-section codeon-license-section">';

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

        $this->renderActionForm($nonceAction, $isActive);

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

    private function renderActionForm(string $nonceAction, bool $isActive): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="codeon-license-form">';
        wp_nonce_field($nonceAction);
        echo '<input type="hidden" name="action" value="codeon_tab_action" />';
        echo '<input type="hidden" name="codeon_tab" value="' . esc_attr($this->slug()) . '" />';

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
