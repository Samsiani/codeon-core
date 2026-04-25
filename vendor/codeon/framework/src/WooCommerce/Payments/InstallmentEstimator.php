<?php

declare(strict_types=1);

namespace CodeOn\Framework\WooCommerce\Payments;

/**
 * Monthly-payment estimator widget for installment gateways.
 *
 * Pure math + presentation, zero bank branding. Each installment
 * micro-plugin (TBC Inst, BoG Inst) calls `render()` from inside
 * its own gateway's `payment_fields()` (or PDP badge) with its
 * own APR, term list, and brand name. The estimator never reaches
 * across plugins to combine banks — that's intentional per the
 * "no cross-bank aggregation" rule.
 *
 * The figures rendered here are an *estimate* (standard
 * amortisation). The bank's own application page, which the
 * customer reaches after clicking "Place order", carries the
 * legally-binding numbers.
 */
final class InstallmentEstimator
{
    /** Standard fallback term lengths (months). */
    public const DEFAULT_MONTHS = [6, 9, 12, 18, 24, 30, 36, 48];
    public const DEFAULT_APR = 26.0;
    public const DEFAULT_PICK = 24;

    /**
     * Standard amortisation: `M = P * r / (1 - (1+r)^-n)`.
     * 0% APR campaigns short-circuit to a flat split.
     */
    public static function monthlyPayment(float $principal, int $months, float $annualRatePct): float
    {
        if ($principal <= 0 || $months <= 0) {
            return 0.0;
        }
        $r = ($annualRatePct / 100) / 12;
        if ($r <= 0.0) {
            return $principal / $months;
        }
        $factor = (1 + $r) ** $months;
        return $principal * $r * $factor / ($factor - 1);
    }

    /**
     * Render the estimator card.
     *
     * @param float          $principal     Cart / order amount in GEL.
     * @param float          $apr           Effective annual percentage rate.
     * @param array<int,int> $months        Allowed term lengths (asc).
     * @param int            $defaultMonths Pre-selected term.
     * @param string         $brandName     "Bank of Georgia" / "TBC Bank" — bank-specific, supplied by caller.
     * @param string         $textdomain    Plugin textdomain so translations work in the calling plugin's catalog.
     * @param string|null    $iconUrl       Optional brand icon URL.
     */
    public static function render(
        float $principal,
        float $apr,
        array $months,
        int $defaultMonths,
        string $brandName,
        string $textdomain = 'codeon-framework',
        ?string $iconUrl = null
    ): string {
        if ($months === []) {
            $months = self::DEFAULT_MONTHS;
        }
        sort($months, SORT_NUMERIC);
        $minM    = (int) min($months);
        $maxM    = (int) max($months);
        $default = in_array($defaultMonths, $months, true)
            ? $defaultMonths
            : (int) $months[intdiv(count($months), 2)];

        $rows = [];
        foreach ($months as $m) {
            $rows[(int) $m] = self::monthlyPayment($principal, (int) $m, $apr);
        }
        $startMonthly = $rows[$default];
        $monthsJson = wp_json_encode($rows, JSON_UNESCAPED_SLASHES);

        $iconHtml = '';
        if ($iconUrl !== null && $iconUrl !== '') {
            $iconHtml = sprintf(
                '<img src="%s" alt="%s" class="codeon-est-brand-icon" loading="lazy"/>',
                esc_url($iconUrl),
                esc_attr($brandName)
            );
        }

        ob_start();
        ?>
        <div class="codeon-est-card"
            data-codeon-estimator
            data-principal="<?php echo esc_attr((string) $principal); ?>"
            data-apr="<?php echo esc_attr((string) $apr); ?>"
            data-months='<?php echo esc_attr((string) $monthsJson); ?>'
            data-default="<?php echo esc_attr((string) $default); ?>">
            <div class="codeon-est-head">
                <div class="codeon-est-title-block">
                    <span class="codeon-est-eyebrow"><?php echo esc_html(__('Estimated monthly payment', $textdomain)); ?></span>
                    <div class="codeon-est-amount-row">
                        <span class="codeon-est-amount" data-codeon-est-monthly>
                            <?php echo esc_html(self::formatMoney($startMonthly)); ?>
                        </span>
                        <span class="codeon-est-currency">₾</span>
                        <span class="codeon-est-permo"><?php echo esc_html(__('/ month', $textdomain)); ?></span>
                    </div>
                    <div class="codeon-est-term-line">
                        <?php
                        printf(
                            /* translators: 1: months count, 2: total payable */
                            esc_html(__('over %1$s · total %2$s ₾', $textdomain)),
                            sprintf('<strong data-codeon-est-months>%d</strong> ' . esc_html(__('months', $textdomain)), $default), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            sprintf('<strong data-codeon-est-total>%s</strong>', esc_html(self::formatMoney($startMonthly * $default))) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        );
                        ?>
                    </div>
                </div>
                <?php echo $iconHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped ?>
            </div>

            <div class="codeon-est-pills" role="radiogroup" aria-label="<?php echo esc_attr(__('Pick a term', $textdomain)); ?>">
                <?php foreach ($months as $m) : ?>
                    <?php $isActive = ((int) $m === $default); ?>
                    <button type="button"
                        class="codeon-est-pill <?php echo $isActive ? 'is-active' : ''; ?>"
                        data-codeon-est-pill="<?php echo esc_attr((string) $m); ?>"
                        aria-pressed="<?php echo $isActive ? 'true' : 'false'; ?>">
                        <?php echo (int) $m; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <input type="range"
                class="codeon-est-slider"
                min="<?php echo esc_attr((string) $minM); ?>"
                max="<?php echo esc_attr((string) $maxM); ?>"
                step="1"
                value="<?php echo esc_attr((string) $default); ?>"
                data-codeon-est-slider
                aria-label="<?php echo esc_attr(__('Months', $textdomain)); ?>"/>

            <div class="codeon-est-meta">
                <span class="codeon-est-meta-item">
                    <?php
                    printf(
                        /* translators: %s effective annual percent rate */
                        esc_html(__('Effective rate from %s%% APR', $textdomain)),
                        esc_html(rtrim(rtrim(number_format($apr, 2, '.', ''), '0'), '.'))
                    );
                    ?>
                </span>
                <span class="codeon-est-meta-sep">·</span>
                <span class="codeon-est-meta-item"><?php echo esc_html(__('Apply in under a minute', $textdomain)); ?></span>
                <span class="codeon-est-meta-sep">·</span>
                <span class="codeon-est-meta-item"><?php echo esc_html(__('Decision in real time', $textdomain)); ?></span>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }
}
