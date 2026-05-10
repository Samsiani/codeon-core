<?php
/**
 * Tbilisi-area override mode resolver.
 *
 * When `tbilisi_only_mode` is on, the entire Region → Municipality →
 * Settlement cascade collapses to either:
 *
 *   - SCOPE_ONLY  : no geo dropdowns at all. State / muni / city are
 *                   silently set to Tbilisi behind the scenes so order
 *                   meta stays consistent for WC reports + shipping
 *                   zones. Customer just types the street address.
 *
 *   - SCOPE_PLUS  : a single "Area" dropdown (placeholder "— Choose
 *                   area —") replaces the whole cascade. Options are
 *                   "Tbilisi" + each merchant-picked surrounding
 *                   settlement. Picking an area auto-fills the hidden
 *                   state + muni fields via a localized lookup map.
 *
 * Tbilisi mode wins over the General-tab `locations_enabled` master
 * switch AND over the General-tab field modes — the user agreed
 * (2026-05-11) that Tbilisi mode is a "force on, override everything"
 * rule. Country / Company / Address-2 / Postcode hide toggles still
 * apply because those are unrelated to the geo cascade.
 *
 * Constants TBILISI_REGION_ID and TBILISI_MUNI_ID match the IDs in
 * data/locations.php (Tbilisi region 'tbilisi' / muni 'tbilisi',
 * WC state code 'TB').
 *
 * @package CodeOn\Core\Locations\Settings
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\Settings;

defined('ABSPATH') || exit;

use CodeOn\Core\Locations\Data\DisplayFormatter;
use CodeOn\Core\Locations\Data\Repository;

final class TbilisiMode
{
    public const SCOPE_ONLY = 'tbilisi_only';
    public const SCOPE_PLUS = 'tbilisi_plus_areas';

    public const TBILISI_REGION_ID    = 'tbilisi';
    public const TBILISI_MUNI_ID      = 'tbilisi';
    public const TBILISI_STATE_CODE   = 'TB';
    public const TBILISI_AREA_KEY     = 'tbilisi';
    public const TBILISI_DISPLAY_NAME = 'Tbilisi';

    public static function isActive(): bool
    {
        $opts = (array) get_option('codeon_core_settings', []);
        return !empty($opts['tbilisi_only_mode']);
    }

    public static function scope(): string
    {
        $opts = (array) get_option('codeon_core_settings', []);
        $value = (string) ($opts['tbilisi_scope'] ?? self::SCOPE_ONLY);
        return in_array($value, [self::SCOPE_ONLY, self::SCOPE_PLUS], true)
            ? $value
            : self::SCOPE_ONLY;
    }

    /**
     * Raw settlement IDs picked by the merchant as "surroundings".
     * @return list<int>
     */
    public static function surroundingSettlementIds(): array
    {
        $opts = (array) get_option('codeon_core_settings', []);
        $raw  = (array) ($opts['tbilisi_surrounding_settlements'] ?? []);
        $out  = [];
        foreach ($raw as $id) {
            $int = (int) $id;
            if ($int > 0) {
                $out[] = $int;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * The customer-facing list of areas the picker offers. Always leads
     * with Tbilisi, then each merchant-picked surrounding settlement
     * (deduplicated, invalid IDs filtered out, Tbilisi-region settlements
     * skipped because they're already covered by the leading Tbilisi entry).
     *
     * @return list<array{key:string, label:string, state:string, muni_id:string, settlement_id:int|null, settlement_name:string}>
     */
    public static function areaList(): array
    {
        $repo = Repository::instance();
        $fmt  = DisplayFormatter::fromOptions();

        $areas = [];

        // Tbilisi as a whole — always first, derived from the region's
        // own labels so localisation flows through DisplayFormatter.
        $tb = $repo->region(self::TBILISI_REGION_ID);
        $areas[] = [
            'key'             => self::TBILISI_AREA_KEY,
            'label'           => $tb !== null
                ? $fmt->label(['name_ka' => $tb['name_ka'], 'name_en' => $tb['name_en']])
                : self::TBILISI_DISPLAY_NAME,
            'state'           => self::TBILISI_STATE_CODE,
            'muni_id'         => self::TBILISI_MUNI_ID,
            'settlement_id'   => null,
            'settlement_name' => self::TBILISI_DISPLAY_NAME,
        ];

        if (self::scope() !== self::SCOPE_PLUS) {
            return $areas;
        }

        foreach (self::surroundingSettlementIds() as $sid) {
            $s = $repo->settlement($sid);
            if ($s === null) {
                continue;
            }
            // Skip Tbilisi-region settlements — already in scope via the
            // leading Tbilisi entry. Harmless dedup so a merchant who
            // accidentally adds a Tbilisi district doesn't see it twice.
            if (($s['region_id'] ?? '') === self::TBILISI_REGION_ID) {
                continue;
            }
            $region = $repo->region((string) $s['region_id']);
            if ($region === null) {
                continue;
            }
            $mun = $repo->municipality((string) $s['municipality_id']);

            // The dataset's name_ka already appends "(<muni>)" for
            // disambiguated settlements (ka.wikipedia convention), so
            // the formatter-resolved label is already self-contained.
            $areas[] = [
                'key'             => 's' . (int) $s['id'],
                'label'           => $fmt->label($s),
                'state'           => (string) $region['wc_state_code'],
                'muni_id'         => (string) ($mun['id'] ?? ''),
                'settlement_id'   => (int) $s['id'],
                'settlement_name' => (string) $s['name_ka'],
            ];
        }

        return $areas;
    }

    /**
     * Resolve a customer's picked area key back to the underlying
     * (state code, muni id, settlement name) tuple. Returns null when
     * the key is unknown — caller should treat that as a validation
     * failure and reject the order.
     *
     * @return array{state:string, muni_id:string, settlement_id:int|null, settlement_name:string}|null
     */
    public static function resolveAreaKey(string $key): ?array
    {
        foreach (self::areaList() as $area) {
            if ($area['key'] === $key) {
                return [
                    'state'           => $area['state'],
                    'muni_id'         => $area['muni_id'],
                    'settlement_id'   => $area['settlement_id'],
                    'settlement_name' => $area['settlement_name'],
                ];
            }
        }
        return null;
    }

    /**
     * The set of WC state codes the country/state dropdown should keep
     * when Tbilisi mode is active. Used by the States filter to trim the
     * options list — though in practice the state dropdown is always
     * hidden in Tbilisi mode, this still matters for any third-party
     * code that reads WC's GE states list.
     *
     * @return list<string>
     */
    public static function allowedStateCodes(): array
    {
        $codes = [self::TBILISI_STATE_CODE];
        foreach (self::areaList() as $area) {
            if ($area['state'] !== '' && !in_array($area['state'], $codes, true)) {
                $codes[] = $area['state'];
            }
        }
        return $codes;
    }
}
