<?php

declare(strict_types=1);

namespace CodeOn\Framework\Admin;

/**
 * Renders a row of {@see HealthCard}s. Used by dashboard pages to show
 * "License / API / Store / Webhook" health at a glance.
 *
 * The grid auto-wraps on small screens via CSS, so the same call works on
 * any number of cards.
 */
final class HealthGrid
{
    /**
     * @param HealthCard[] $cards
     */
    public static function render(array $cards): void
    {
        if ($cards === []) {
            return;
        }
        echo '<div class="codeon-health-grid">';
        foreach ($cards as $card) {
            self::renderOne($card);
        }
        echo '</div>';
    }

    public static function renderOne(HealthCard $card): void
    {
        echo '<div class="codeon-health-card codeon-tone-' . esc_attr($card->tone) . '">';
        echo '<div class="codeon-health-card-title">' . esc_html($card->title) . '</div>';
        echo '<div class="codeon-health-card-label">' . esc_html($card->label) . '</div>';
        if ($card->detail !== '') {
            echo '<div class="codeon-health-card-detail">' . wp_kses_post($card->detail) . '</div>';
        }
        if ($card->actionUrl !== '' && $card->actionLabel !== '') {
            printf(
                '<a href="%s" class="button button-secondary codeon-health-card-action">%s</a>',
                esc_url($card->actionUrl),
                esc_html($card->actionLabel)
            );
        }
        echo '</div>';
    }
}
