<?php
/**
 * Public REST controller for the Locations dataset.
 *
 * Endpoints under /wp-json/codeon-geo/v1/:
 *   GET /regions                                   list of regions
 *   GET /regions/{id}/municipalities               muns of a region
 *   GET /municipalities/{id}/settlements           settlements of a mun
 *   GET /search?q=…&limit=20                       typeahead across all settlements
 *
 * All endpoints are public (no auth) — the dataset itself is public
 * info. Cached 24h via Cache-Control. Display-mode aware via ?display=ka|en|bilingual.
 *
 * @package CodeOn\Core\Locations\Rest
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\Rest;

defined('ABSPATH') || exit;

use CodeOn\Core\Locations\Data\DisplayFormatter;
use CodeOn\Core\Locations\Data\Repository;
use CodeOn\Framework\Storage\SettingsRepository;
use WP_REST_Request;
use WP_REST_Response;

final class Controller
{
    private const NAMESPACE = 'codeon-geo/v1';

    public function __construct(private readonly SettingsRepository $settings) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $args = [
            'display' => [
                'type'    => 'string',
                'enum'    => ['ka', 'en', 'bilingual', 'auto'],
                'default' => 'auto',
            ],
        ];
        register_rest_route(self::NAMESPACE, '/regions', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => $args,
            'callback'            => [$this, 'regions'],
        ]);
        register_rest_route(self::NAMESPACE, '/regions/(?P<id>[a-z0-9-]+)/municipalities', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => $args + [
                'id' => ['type' => 'string', 'required' => true],
            ],
            'callback'            => [$this, 'municipalities'],
        ]);
        register_rest_route(self::NAMESPACE, '/municipalities/(?P<id>[a-z0-9-]+)/settlements', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => $args + [
                'id' => ['type' => 'string', 'required' => true],
            ],
            'callback'            => [$this, 'settlements'],
        ]);
        register_rest_route(self::NAMESPACE, '/search', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'args'                => $args + [
                'q'     => ['type' => 'string', 'required' => true],
                'limit' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
            'callback'            => [$this, 'search'],
        ]);
    }

    public function regions(WP_REST_Request $req): WP_REST_Response
    {
        $repo = Repository::instance();
        $fmt  = $this->formatter($req);
        $opts = (array) get_option('codeon_core_settings', []);
        $showOccupied = (bool) ($opts['show_occupied'] ?? false);

        $out = [];
        foreach ($repo->regions(includeOccupied: $showOccupied) as $region) {
            $out[] = [
                'id'             => $region['id'],
                'wc_state_code'  => $region['wc_state_code'],
                'name'           => $fmt->label($region),
                'name_ka'        => $region['name_ka'],
                'name_en'        => $region['name_en'],
                'occupied'       => $region['occupied'],
            ];
        }
        return $this->cached(rest_ensure_response($out));
    }

    public function municipalities(WP_REST_Request $req): WP_REST_Response
    {
        $repo = Repository::instance();
        $fmt  = $this->formatter($req);
        $opts = (array) get_option('codeon_core_settings', []);
        $showOccupied = (bool) ($opts['show_occupied'] ?? false);

        $regionId = (string) $req['id'];

        // Allow lookup by region slug OR WC state code (TB / KA / etc).
        if ($repo->region($regionId) === null && $repo->regionByWcCode(strtoupper($regionId)) !== null) {
            $regionId = $repo->regionByWcCode(strtoupper($regionId))['id'];
        }

        $out = [];
        foreach ($repo->municipalitiesOf($regionId, includeOccupied: $showOccupied) as $m) {
            $out[] = [
                'id'                 => $m['id'],
                'name'               => $fmt->label($m),
                'name_ka'            => $m['name_ka'],
                'name_en'            => $m['name_en'],
                'admin_center_ka'    => $m['admin_center_ka'],
                'admin_center_type'  => $m['admin_center_type'],
                'occupied'           => $m['occupied'],
                'settlement_count'   => $m['settlement_count'],
            ];
        }
        return $this->cached(rest_ensure_response($out));
    }

    public function settlements(WP_REST_Request $req): WP_REST_Response
    {
        $repo = Repository::instance();
        $fmt  = $this->formatter($req);
        $munId = (string) $req['id'];
        $list = $repo->settlementsOf($munId);

        $out = [];
        foreach ($list as $s) {
            $out[] = [
                'id'              => (int) $s['id'],
                'name'            => $fmt->label($s),
                'name_ka'         => $s['name_ka'],
                'type'            => $s['type'],
                'is_admin_center' => (bool) $s['is_admin_center'],
            ];
        }
        return $this->cached(rest_ensure_response($out));
    }

    public function search(WP_REST_Request $req): WP_REST_Response
    {
        $repo = Repository::instance();
        $fmt  = $this->formatter($req);
        $opts = (array) get_option('codeon_core_settings', []);
        $showOccupied = (bool) ($opts['show_occupied'] ?? false);

        $hits = $repo->searchSettlements(
            (string) $req['q'],
            (int) $req['limit'],
            $showOccupied
        );

        $out = [];
        foreach ($hits as $s) {
            $out[] = [
                'id'              => (int) $s['id'],
                'name'            => $fmt->label($s),
                'name_ka'         => $s['name_ka'],
                'type'            => $s['type'],
                'municipality_id' => $s['municipality_id'],
                'region_id'       => $s['region_id'],
            ];
        }
        // Search is per-query so don't long-cache; 5 min.
        $resp = rest_ensure_response($out);
        $resp->header('Cache-Control', 'public, max-age=300');
        return $resp;
    }

    private function formatter(WP_REST_Request $req): DisplayFormatter
    {
        $display = (string) ($req['display'] ?? 'auto');
        $opts = (array) get_option('codeon_core_settings', []);
        return new DisplayFormatter([
            'display_mode'     => $display === 'auto' ? ($opts['display_mode'] ?? 'auto') : $display,
            'simplified_latin' => $opts['simplified_latin'] ?? true,
        ]);
    }

    private function cached(WP_REST_Response $resp): WP_REST_Response
    {
        // Dataset only changes on plugin updates → safe to cache hard.
        $resp->header('Cache-Control', 'public, max-age=86400, immutable');
        return $resp;
    }
}
