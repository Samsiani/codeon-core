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
     * Cache-bust version for the estimator stylesheet + script.
     * Bumped when assets/css/codeon-estimator.css or assets/js/codeon-estimator.js change.
     */
    private const ASSET_VERSION = '0.3.12';

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
        self::enqueueAssets();

        if ($months === []) {
            $months = self::DEFAULT_MONTHS;
        }
        sort($months, SORT_NUMERIC);
        $months  = array_values(array_map('intval', $months));
        $count   = count($months);
        $minM    = (int) $months[0];
        $maxM    = (int) $months[$count - 1];
        $default = in_array($defaultMonths, $months, true)
            ? $defaultMonths
            : (int) $months[intdiv($count, 2)];

        $startMonthly = self::monthlyPayment($principal, $default, $apr);
        $monthsJson = wp_json_encode($months, JSON_UNESCAPED_SLASHES);

        // Initial fill of the slider's orange progress portion, computed
        // linearly across the value range so the SSR'd track is correct
        // before JS runs. JS keeps it in sync afterwards.
        $initialProgress = ($maxM > $minM)
            ? (($default - $minM) / ($maxM - $minM)) * 100.0
            : 50.0;

        // `$brandName` and `$iconUrl` are intentionally unused in the new
        // minimal layout — the consumer's existing call sites still pass them
        // for API compatibility, but the design no longer renders an icon.
        unset($brandName, $iconUrl);

        ob_start();
        ?>
        <div class="codeon-est-card"
            data-codeon-estimator
            data-principal="<?php echo esc_attr((string) $principal); ?>"
            data-apr="<?php echo esc_attr((string) $apr); ?>"
            data-months='<?php echo esc_attr((string) $monthsJson); ?>'
            data-default="<?php echo esc_attr((string) $default); ?>"
            style="--codeon-est-progress: <?php echo esc_attr(number_format($initialProgress, 4, '.', '')); ?>%">

            <div class="codeon-est-summary">
                <div class="codeon-est-stat">
                    <strong class="codeon-est-stat-num" data-codeon-est-months><?php echo (int) $default; ?></strong>
                    <span class="codeon-est-stat-unit"><?php echo esc_html(__('Month', $textdomain)); ?></span>
                </div>
                <div class="codeon-est-stat codeon-est-stat--amount">
                    <strong class="codeon-est-stat-num" data-codeon-est-monthly><?php echo esc_html(self::formatMoney($startMonthly)); ?></strong>
                    <span class="codeon-est-stat-currency">₾</span>
                    <span class="codeon-est-stat-unit"><?php echo esc_html(__('Per Month', $textdomain)); ?></span>
                </div>
            </div>

            <input type="range"
                class="codeon-est-slider"
                min="<?php echo esc_attr((string) $minM); ?>"
                max="<?php echo esc_attr((string) $maxM); ?>"
                step="1"
                value="<?php echo esc_attr((string) $default); ?>"
                data-codeon-est-slider
                aria-label="<?php echo esc_attr(__('Months', $textdomain)); ?>"/>

            <div class="codeon-est-marks" role="radiogroup" aria-label="<?php echo esc_attr(__('Pick a term', $textdomain)); ?>">
                <?php foreach ($months as $m) : ?>
                    <?php
                    $isActive = ($m === $default);
                    $fraction = ($maxM > $minM) ? ($m - $minM) / ($maxM - $minM) : 0.5;
                    ?>
                    <button type="button"
                        class="codeon-est-mark<?php echo $isActive ? ' is-active' : ''; ?>"
                        data-codeon-est-pill="<?php echo esc_attr((string) $m); ?>"
                        aria-pressed="<?php echo $isActive ? 'true' : 'false'; ?>"
                        style="left: calc(var(--codeon-est-thumb-w) / 2 + (100% - var(--codeon-est-thumb-w)) * <?php echo esc_attr(number_format($fraction, 6, '.', '')); ?>);"><?php echo (int) $m; ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    /**
     * Enqueue the estimator stylesheet + script.
     *
     * Called from `render()` so consumer plugins don't have to wire anything
     * — the moment a gateway calls `InstallmentEstimator::render()` from its
     * `payment_fields()` or PDP badge, the assets become available on the page.
     *
     * Safe to call multiple times: `wp_enqueue_*` is idempotent on handle.
     * Safe to call late (during `the_content` or `payment_fields()` output) —
     * styles/scripts queued at that point are still printed in the footer.
     */
    private static function enqueueAssets(): void
    {
        if (!function_exists('wp_enqueue_style') || !function_exists('wp_enqueue_script')) {
            return;
        }

        $base = self::assetsBaseUrl();
        if ($base === '') {
            return;
        }

        wp_enqueue_style(
            'codeon-estimator',
            $base . 'css/codeon-estimator.css',
            [],
            self::ASSET_VERSION
        );

        wp_enqueue_script(
            'codeon-estimator',
            $base . 'js/codeon-estimator.js',
            [],
            self::ASSET_VERSION,
            true
        );
    }

    /**
     * Resolve the absolute URL of the framework's `assets/` directory.
     *
     * The framework lives at `<plugin>/vendor/codeon/framework/` inside any
     * consumer plugin, so we walk up three levels from this file
     * (`src/WooCommerce/Payments/InstallmentEstimator.php` → framework root)
     * and append `assets/`.
     */
    private static function assetsBaseUrl(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!function_exists('plugin_dir_url')) {
            return $cached = '';
        }
        // plugin_dir_url(__FILE__) → .../<plugin>/vendor/codeon/framework/src/WooCommerce/Payments/
        $here = plugin_dir_url(__FILE__);
        // dirname N=3 strips Payments/, WooCommerce/, src/ — leaves the framework root.
        $frameworkRoot = rtrim((string) dirname($here, 3), '/');
        return $cached = $frameworkRoot . '/assets/';
    }
}
