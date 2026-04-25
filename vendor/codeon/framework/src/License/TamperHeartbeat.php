<?php

declare(strict_types=1);

namespace CodeOn\Framework\License;

use Closure;

/**
 * Daily WP-Cron heartbeat that lets codeon.ge know when this plugin
 * is running with a tampered watermark stamp.
 *
 * The cron tick is silent on healthy installs. On installs where the
 * plugin's `BuildStampContract::verify()` returns false we POST to
 * `/api/v1/tamper-report` with `blocking=false` + `timeout=5`, wrapped
 * in try/catch so a network failure can never panic an already-
 * degraded recovery-mode install.
 *
 * The plugin is responsible for deciding what "tampered" means; this
 * class just reads a callable predicate. Constructor params:
 *   - $pluginId: codeon.ge PluginId (`fina-sync`, `quickshipper-delivery`, …).
 *   - $pluginSlug: WP folder name; doubles as the cron hook prefix.
 *   - $pluginVersion: shipped with each report for forensics.
 *   - $isTampered: () => bool. Plugin's recovery-mode check.
 *   - $licenseKeyGetter: optional () => string closure, returns the
 *     merchant's license key (empty when none). Receiving a closure
 *     instead of a typed LicenseStore lets plugins keep their own
 *     storage shape without coupling.
 *   - $buildIdConstant: per-plugin constant name carrying the watermark UUID.
 */
final class TamperHeartbeat
{
    public const ENDPOINT = 'https://codeon.ge/api/v1/tamper-report';

    /** @var callable():bool */
    private $isTampered;

    /** @var Closure():string|null */
    private ?Closure $licenseKeyGetter;

    public function __construct(
        private readonly string $pluginId,
        private readonly string $pluginSlug,
        private readonly string $pluginVersion,
        callable $isTampered,
        ?Closure $licenseKeyGetter = null,
        private readonly string $buildIdConstant = 'CODEON_BUILD_ID',
    ) {
        $this->isTampered = $isTampered;
        $this->licenseKeyGetter = $licenseKeyGetter;
    }

    public function register(): void
    {
        $hook = $this->cronHook();
        add_action($hook, [$this, 'tick']);
        if (!wp_next_scheduled($hook)) {
            // Once daily is plenty: we don't need bursts of telemetry
            // from a single broken install, and the cron's natural
            // jitter spreads load.
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $hook);
        }
    }

    public function unregister(): void
    {
        $hook = $this->cronHook();
        $next = wp_next_scheduled($hook);
        if ($next !== false) {
            wp_unschedule_event($next, $hook);
        }
    }

    public function tick(): void
    {
        try {
            $tampered = (bool) call_user_func($this->isTampered);
            if (!$tampered) {
                return;
            }
            $body = [
                'plugin_id'      => $this->pluginId,
                'domain'         => home_url('/'),
                'plugin_version' => $this->pluginVersion,
            ];
            $reportedBuild = $this->buildIdValue();
            if ($reportedBuild !== '') {
                $body['reported_build_id'] = $reportedBuild;
            }
            if ($this->licenseKeyGetter !== null) {
                $key = (string) ($this->licenseKeyGetter)();
                if ($key !== '') {
                    $body['license_key'] = $key;
                }
            }
            wp_remote_post(self::ENDPOINT, [
                'timeout'  => 5,
                'blocking' => false,
                'headers'  => ['Content-Type' => 'application/json'],
                'body'     => wp_json_encode($body),
            ]);
        } catch (\Throwable $e) {
            // Heartbeat must never throw — recovery-mode installs are
            // already in a degraded state and a fatal here would
            // compound the problem.
            error_log('[CodeOn TamperHeartbeat] suppressed: ' . $e->getMessage());
        }
    }

    private function cronHook(): string
    {
        return $this->pluginSlug . '/tamper_heartbeat';
    }

    private function buildIdValue(): string
    {
        if (!defined($this->buildIdConstant)) {
            return '';
        }
        return (string) constant($this->buildIdConstant);
    }
}
