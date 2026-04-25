<?php
/**
 * CLI smoke test — exercises the data layer outside of WP.
 * Run from the plugin root: php build/smoke-test.php
 *
 * Stubs the WP functions our data classes touch (none, currently —
 * Repository/Transliterator/DisplayFormatter are pure PHP) so we can
 * verify them without booting WordPress.
 */

declare(strict_types=1);

// Define the constants the bootstrap defines so includes work standalone.
define('CODEON_CORE_PATH', __DIR__ . '/../');
define('ABSPATH', '/tmp/wp/');

// Stub the small handful of WP functions our pure-data classes use.
if (!function_exists('determine_locale')) {
    function determine_locale(): string { return 'ka_GE'; }
}
if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed { return $default; }
}

require __DIR__ . '/../vendor/autoload.php';

use CodeOn\Core\Locations\Data\DisplayFormatter;
use CodeOn\Core\Locations\Data\Repository;
use CodeOn\Core\Locations\Data\Transliterator;

$ok = 0;
$fail = 0;

function check(string $label, bool $cond, string $detail = ''): void {
    global $ok, $fail;
    if ($cond) {
        $ok++;
        printf("  ✓ %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    } else {
        $fail++;
        printf("  ✗ %s%s\n", $label, $detail !== '' ? "  ($detail)" : '');
    }
}

// ---- Repository ----
echo "Repository\n";
$repo = Repository::instance();
$meta = $repo->meta();
check('meta has counts', isset($meta['region_count'], $meta['municipality_count'], $meta['settlement_count']));
check('13 regions',  $meta['region_count']       === 13, (string) $meta['region_count']);
check('77 muns',     $meta['municipality_count'] === 77, (string) $meta['municipality_count']);
check('4394 settlements', $meta['settlement_count'] === 4394, (string) $meta['settlement_count']);

$regionsAll = $repo->regions(true);
$regionsClean = $repo->regions(false);
check('regions(true)  returns 13', count($regionsAll)   === 13);
check('regions(false) drops occupied', count($regionsClean) === 11, count($regionsClean) . ' vs expected 11');

$kakheti = $repo->region('kakheti');
check('region("kakheti") found', $kakheti !== null);
check('Kakheti has WC code KA', ($kakheti['wc_state_code'] ?? '') === 'KA');

$telavi = $repo->municipality('telavi');
check('municipality("telavi") found', $telavi !== null);

$telaviSettles = $repo->settlementsOf('telavi');
check('Telavi has settlements', count($telaviSettles) > 0, count($telaviSettles) . ' settlements');

$found = false;
foreach ($telaviSettles as $s) {
    if ($s['name_ka'] === 'კონდოლი') { $found = true; break; }
}
check('"კონდოლი" exists in Telavi', $found);

$byCode = $repo->regionByWcCode('KA');
check('regionByWcCode("KA") → kakheti', ($byCode['id'] ?? '') === 'kakheti');

$hits = $repo->searchSettlements('კონდ', 5);
check('search "კონდ" returns hits', count($hits) > 0, count($hits) . ' results');

// ---- Transliterator ----
echo "\nTransliterator\n";
check('კონდოლი → Kondoli (simplified)',
    Transliterator::toLatin('კონდოლი', true) === 'Kondoli',
    Transliterator::toLatin('კონდოლი', true));
check('კონდოლი → Kʼondoli (canonical)',
    Transliterator::toLatin('კონდოლი', false) === 'Kʼondoli',
    Transliterator::toLatin('კონდოლი', false));
check('თბილისი → Tbilisi',
    Transliterator::toLatin('თბილისი', true) === 'Tbilisi',
    Transliterator::toLatin('თბილისი', true));
check('საქართველო → Sakartvelo',
    Transliterator::toLatin('საქართველო', true) === 'Sakartvelo',
    Transliterator::toLatin('საქართველო', true));
check('bilingual format',
    Transliterator::bilingual('კონდოლი', true) === 'კონდოლი (Kondoli)',
    Transliterator::bilingual('კონდოლი', true));

// ---- DisplayFormatter ----
echo "\nDisplayFormatter\n";
$row = ['name_ka' => 'კონდოლი'];

$f1 = new DisplayFormatter(['display_mode' => 'ka']);
check('mode=ka → Georgian only', $f1->label($row) === 'კონდოლი');

$f2 = new DisplayFormatter(['display_mode' => 'en', 'simplified_latin' => true]);
check('mode=en → transliteration', $f2->label($row) === 'Kondoli');

$f3 = new DisplayFormatter(['display_mode' => 'bilingual']);
check('mode=bilingual', $f3->label($row) === 'კონდოლი (Kondoli)');

$rowEn = ['name_ka' => 'კახეთი', 'name_en' => 'Kakheti'];
$f4 = new DisplayFormatter(['display_mode' => 'en']);
check('mode=en uses name_en if present', $f4->label($rowEn) === 'Kakheti');

$f5 = new DisplayFormatter(['display_mode' => 'bilingual']);
check('bilingual prefers ka(en) over transliteration when name_en exists',
    $f5->label($rowEn) === 'კახეთი (Kakheti)');

echo "\n";
printf("Result: %d passed, %d failed\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
