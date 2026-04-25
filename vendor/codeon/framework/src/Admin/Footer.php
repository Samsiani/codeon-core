<?php

declare(strict_types=1);

namespace CodeOn\Framework\Admin;

use CodeOn\Framework\Plugin\Manifest;

/**
 * Standard footer band rendered below every codeon-* admin page.
 *
 * Shows the build watermark (so support can match a screenshot to a
 * download_audits row) and a "powered by CodeOn" link. The watermark line
 * only renders when the host plugin defines a CODEON_BUILD_ID constant —
 * which it always should in production.
 */
final class Footer
{
    public function __construct(private readonly Manifest $manifest)
    {
    }

    public function render(): void
    {
        echo '<footer class="codeon-footer">';
        echo '<div class="codeon-footer-meta">';
        if (defined('CODEON_BUILD_ID')) {
            $build = (string) constant('CODEON_BUILD_ID');
            if ($build !== '' && $build !== '__CODEON_BUILD_ID__') {
                printf(
                    '<span class="codeon-footer-build" title="%s">build %s</span>',
                    esc_attr__('Per-license build identifier — include this when contacting support.', 'codeon-framework'),
                    esc_html(substr($build, 0, 8))
                );
            }
        }
        printf(
            '<a class="codeon-footer-brand" href="%s" target="_blank" rel="noopener">%s</a>',
            esc_url($this->manifest->supportUrl !== '' ? $this->manifest->supportUrl : 'https://codeon.ge'),
            esc_html__('Powered by CodeOn', 'codeon-framework')
        );
        echo '</div>';
        echo '</footer>';
    }
}
