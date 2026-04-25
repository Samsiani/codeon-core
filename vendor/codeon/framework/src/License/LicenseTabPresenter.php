<?php

declare(strict_types=1);

namespace CodeOn\Framework\License;

use CodeOn\Framework\Admin\HealthCard;

/**
 * Formats raw {@see LicenseAdapter} output into the bits the License tab
 * needs to render — status pill, copy strings, action button colours.
 *
 * Pure presentation. No I/O.
 */
final class LicenseTabPresenter
{
    public function __construct(private readonly LicenseAdapter $adapter)
    {
    }

    public function statusCard(): HealthCard
    {
        $tone = match ($this->adapter->status()) {
            LicenseAdapter::STATUS_ACTIVE   => HealthCard::TONE_OK,
            LicenseAdapter::STATUS_GRACE    => HealthCard::TONE_WARN,
            LicenseAdapter::STATUS_EXPIRED,
            LicenseAdapter::STATUS_INACTIVE => HealthCard::TONE_ERR,
            default                         => HealthCard::TONE_MUTED,
        };

        $snapshot = $this->adapter->snapshot();

        return new HealthCard(
            title: __('License', 'codeon-framework'),
            tone: $tone,
            label: $this->statusLabel(),
            detail: (string) ($snapshot['last_error'] ?? '')
        );
    }

    public function statusLabel(): string
    {
        return match ($this->adapter->status()) {
            LicenseAdapter::STATUS_ACTIVE   => __('Active', 'codeon-framework'),
            LicenseAdapter::STATUS_GRACE    => __('Grace period', 'codeon-framework'),
            LicenseAdapter::STATUS_EXPIRED  => __('Expired', 'codeon-framework'),
            LicenseAdapter::STATUS_INACTIVE => __('Inactive', 'codeon-framework'),
            default                         => __('Unknown', 'codeon-framework'),
        };
    }

    public function adapter(): LicenseAdapter
    {
        return $this->adapter;
    }
}
