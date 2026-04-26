<?php
/**
 * Mkhedruli → Latin transliterator.
 *
 * Implements the Georgian National System of Romanization (1998).
 * Used to render English-mode and bilingual labels for villages whose
 * Wikipedia entry has no en.wiki article (which is most of them).
 *
 * Two output styles, gated by the `simplified_latin` setting:
 *   - simplified (default): k, p, t, q, ts, ch  — readable for Georgian customers
 *   - canonical:            kʼ, pʼ, tʼ, qʼ, tsʼ, chʼ — strict ISO compliance
 *
 * The 33-letter alphabet is small enough that a strtr() call costs
 * nothing; we still memoise per-string so checkout AJAX returning
 * 250 villages doesn't recompute the same 30 settlement names.
 *
 * @package CodeOn\Core\Locations\Data
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\Data;

defined('ABSPATH') || exit;

final class Transliterator
{
    /** @var array<string,string> */
    private const CANONICAL = [
        'ა' => 'a',  'ბ' => 'b',  'გ' => 'g',  'დ' => 'd',  'ე' => 'e',
        'ვ' => 'v',  'ზ' => 'z',  'თ' => 't',  'ი' => 'i',  'კ' => 'kʼ',
        'ლ' => 'l',  'მ' => 'm',  'ნ' => 'n',  'ო' => 'o',  'პ' => 'pʼ',
        'ჟ' => 'zh', 'რ' => 'r',  'ს' => 's',  'ტ' => 'tʼ', 'უ' => 'u',
        'ფ' => 'p',  'ქ' => 'k',  'ღ' => 'gh', 'ყ' => 'qʼ', 'შ' => 'sh',
        'ჩ' => 'ch', 'ც' => 'ts', 'ძ' => 'dz', 'წ' => 'tsʼ','ჭ' => 'chʼ',
        'ხ' => 'kh', 'ჯ' => 'j',  'ჰ' => 'h',
    ];

    /** @var array<string,string> */
    private const SIMPLIFIED = [
        'ა' => 'a',  'ბ' => 'b',  'გ' => 'g',  'დ' => 'd',  'ე' => 'e',
        'ვ' => 'v',  'ზ' => 'z',  'თ' => 't',  'ი' => 'i',  'კ' => 'k',
        'ლ' => 'l',  'მ' => 'm',  'ნ' => 'n',  'ო' => 'o',  'პ' => 'p',
        'ჟ' => 'zh', 'რ' => 'r',  'ს' => 's',  'ტ' => 't',  'უ' => 'u',
        'ფ' => 'p',  'ქ' => 'k',  'ღ' => 'gh', 'ყ' => 'q',  'შ' => 'sh',
        'ჩ' => 'ch', 'ც' => 'ts', 'ძ' => 'dz', 'წ' => 'ts', 'ჭ' => 'ch',
        'ხ' => 'kh', 'ჯ' => 'j',  'ჰ' => 'h',
    ];

    /** @var array<string,string> */
    private static array $cache = [];

    public static function toLatin(string $georgian, bool $simplified = true): string
    {
        $cacheKey = ($simplified ? 's:' : 'c:') . $georgian;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }
        $table = $simplified ? self::SIMPLIFIED : self::CANONICAL;
        $latin = strtr($georgian, $table);
        // Capitalize first letter — matches "Kondoli", not "kondoli".
        if ($latin !== '') {
            $latin = mb_strtoupper(mb_substr($latin, 0, 1)) . mb_substr($latin, 1);
        }
        return self::$cache[$cacheKey] = $latin;
    }

    /**
     * Bilingual label: "კონდოლი (Kondoli)".
     */
    public static function bilingual(string $georgian, bool $simplified = true): string
    {
        $latin = self::toLatin($georgian, $simplified);
        return $georgian . ' (' . $latin . ')';
    }
}
