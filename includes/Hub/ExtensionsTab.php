<?php
/**
 * Core's Extensions tab.
 *
 * For now this just relabels — the framework's renderToolbar is private
 * so we can't customize its copy without a framework patch. M3 will
 * upstream a PR to make those methods protected, then this subclass can
 * carry richer marketing copy as the framework HUB_ARCHITECTURE.md §2
 * envisions. Meanwhile the framework's toolbar text is perfectly OK.
 *
 * The card grid, install modal, bucketise logic, and AJAX install flow
 * all inherit as-is from {@see \CodeOn\Framework\Extensions\ExtensionsTab}.
 *
 * @package CodeOn\Core
 */

declare(strict_types=1);

namespace CodeOn\Core\Hub;

use CodeOn\Framework\Extensions\ExtensionsTab as FrameworkExtensionsTab;

final class ExtensionsTab extends FrameworkExtensionsTab
{
    public function label(): string
    {
        return __('Extensions', 'codeon-core');
    }
}
