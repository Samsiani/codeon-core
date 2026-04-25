<?php

declare(strict_types=1);

namespace CodeOn\Framework\Admin;

use CodeOn\Framework\Plugin\Manifest;

/**
 * Standard header band rendered above every codeon-* admin page.
 *
 * Plugin name + version on the left, optional global status pill on the
 * right (driven by whichever HealthCard the manifest declares as
 * `headerStatusCard`). Identical chrome across every plugin so the visual
 * identity carries.
 */
final class Header
{
    public function __construct(private readonly Manifest $manifest)
    {
    }

    public function render(?HealthCard $statusCard = null): void
    {
        echo '<header class="codeon-header">';
        echo '<div class="codeon-header-brand">';
        if ($this->manifest->iconUrl !== '') {
            printf(
                '<img class="codeon-header-icon" src="%s" alt="" width="24" height="24" />',
                esc_url($this->manifest->iconUrl)
            );
        }
        echo '<div class="codeon-header-text">';
        echo '<h1 class="codeon-header-title">' . esc_html($this->manifest->menuTitle) . '</h1>';
        if ($this->manifest->version !== '') {
            echo '<span class="codeon-header-version">v' . esc_html($this->manifest->version) . '</span>';
        }
        echo '</div></div>';

        echo '<div class="codeon-header-meta">';
        if ($statusCard !== null) {
            printf(
                '<span class="codeon-pill codeon-tone-%s" title="%s">%s</span>',
                esc_attr($statusCard->tone),
                esc_attr($statusCard->detail),
                esc_html($statusCard->label)
            );
        }
        echo '</div>';
        echo '</header>';
    }
}
