<?php
/**
 * Bundle generator for the Georgian locations dataset.
 *
 * Reads the canonical hierarchy.json from /Users/george/Documents/georgian-data/
 * and writes two artifacts inside the plugin:
 *
 *   data/locations.php       — pure PHP literal returning the nested array.
 *                              Opcache friendly; no JSON parse at runtime.
 *   data/locations.min.json  — compact JSON served via REST + Cache-Control 24h.
 *
 * Run from the plugin root:
 *   php build/sync-from-georgian-data.php
 *
 * Idempotent. Adds wc_state_code to each region (aligning with WC's existing
 * GE state codes) and stamps the build date so DiagnosticsTab can show
 * "dataset built YYYY-MM-DD".
 */

declare(strict_types=1);

const SOURCE = '/Users/george/Documents/georgian-data/output/hierarchy.json';
const OUT_PHP  = __DIR__ . '/../data/locations.php';
const OUT_JSON = __DIR__ . '/../data/locations.min.json';

// Canonical mapping of our region IDs to WooCommerce's existing GE state
// codes. WC core ships these 12 — we keep them so existing orders that
// stored state="TB" still resolve correctly. We add "TS" for Tskhinvali.
const WC_STATE_CODES = [
    'tbilisi'                       => 'TB',
    'abkhazia'                      => 'AB',
    'adjara'                        => 'AJ',
    'guria'                         => 'GU',
    'imereti'                       => 'IM',
    'kakheti'                       => 'KA',
    'mtskheta-mtianeti'             => 'MM',
    'racha-lechkhumi-kvemo-svaneti' => 'RL',
    'samegrelo-zemo-svaneti'        => 'SZ',
    'samtskhe-javakheti'            => 'SJ',
    'kvemo-kartli'                  => 'KK',
    'shida-kartli'                  => 'SK',
    'tskhinvali-region'             => 'TS',
];

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

if (!is_readable(SOURCE)) {
    fail('hierarchy.json not found at ' . SOURCE);
}

$json = file_get_contents(SOURCE);
if ($json === false) {
    fail('Could not read hierarchy.json');
}
$src = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

// Trim nested data to only what the runtime needs. Drops Wikipedia URLs,
// genitive forms, and category names — they're useful in the source repo
// but bloat the bundle and aren't read by Locations\Data\Repository.
$regionsOut       = [];
$totalSettlements = 0;
$totalMuns        = 0;
foreach ($src as $region) {
    $rid = $region['id'];
    if (!isset(WC_STATE_CODES[$rid])) {
        fail("Region '{$rid}' has no WC state code mapping. Add it to WC_STATE_CODES.");
    }

    $munsOut = [];
    foreach ($region['municipalities'] as $mun) {
        $totalMuns++;
        $settlementsOut = [];
        foreach ($mun['settlements'] as $s) {
            $totalSettlements++;
            $settlementsOut[] = [
                'id'              => (int) $s['id'],
                'name_ka'         => (string) $s['name_ka'],
                'type'            => (string) $s['type'],
                'is_admin_center' => (bool) $s['is_admin_center'],
            ];
        }
        $munsOut[] = [
            'id'                 => (string) $mun['id'],
            'name_ka'            => (string) $mun['name_ka'],
            'name_en'            => (string) $mun['name_en'],
            'admin_center_ka'    => (string) $mun['admin_center_ka'],
            'admin_center_type'  => (string) $mun['admin_center_type'],
            'occupied'           => (bool) $mun['occupied'],
            'settlements'        => $settlementsOut,
        ];
    }
    $regionsOut[] = [
        'id'             => (string) $region['id'],
        'name_ka'        => (string) $region['name_ka'],
        'name_en'        => (string) $region['name_en'],
        'wc_state_code'  => WC_STATE_CODES[$rid],
        'type'           => (string) $region['type'],
        'occupied'       => (bool) $region['occupied'],
        'order'          => (int) $region['order'],
        'municipalities' => $munsOut,
    ];
}

$bundle = [
    '_meta' => [
        'version'          => '0.1.0',
        'built_at'         => gmdate('c'),
        'source'           => 'ka.wikipedia.org',
        'region_count'     => count($regionsOut),
        'municipality_count' => $totalMuns,
        'settlement_count' => $totalSettlements,
    ],
    'regions' => $regionsOut,
];

// PHP bundle — var_export gives us a parseable literal without JSON
// decoding overhead at runtime.
$phpHeader = <<<PHP
<?php
/**
 * AUTO-GENERATED. Do not edit by hand.
 * Regenerate with: php build/sync-from-georgian-data.php
 *
 * Source: /Users/george/Documents/georgian-data/output/hierarchy.json
 * Built:  {$bundle['_meta']['built_at']}
 *
 * @package CodeOn\\Core
 */

return
PHP;
$phpBody = var_export($bundle, true) . ";\n";

if (file_put_contents(OUT_PHP, $phpHeader . ' ' . $phpBody) === false) {
    fail('Could not write data/locations.php');
}

// Compact JSON for REST. JSON_UNESCAPED_UNICODE keeps Georgian readable
// in the wire response, JSON_UNESCAPED_SLASHES shrinks the payload.
$jsonOut = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($jsonOut === false) {
    fail('Could not encode JSON: ' . json_last_error_msg());
}
if (file_put_contents(OUT_JSON, $jsonOut) === false) {
    fail('Could not write data/locations.min.json');
}

printf(
    "✓ %d regions, %d municipalities, %d settlements\n  → %s (%s)\n  → %s (%s)\n",
    $bundle['_meta']['region_count'],
    $bundle['_meta']['municipality_count'],
    $bundle['_meta']['settlement_count'],
    realpath(OUT_PHP),
    sizeFmt(filesize(OUT_PHP)),
    realpath(OUT_JSON),
    sizeFmt(filesize(OUT_JSON)),
);

function sizeFmt(int $bytes): string
{
    $u = ['B', 'KB', 'MB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i ? 1 : 0) . ' ' . $u[$i];
}
