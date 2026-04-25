<?php
/**
 * Resolves the display label for a region/municipality/settlement based on
 * the merchant's display-mode setting and the active WordPress locale.
 *
 * Settings live in `codeon_core_settings` and look like:
 *   [
 *     'display_mode'     => 'auto' | 'ka' | 'en' | 'bilingual',
 *     'simplified_latin' => true,
 *   ]
 *
 * 'auto' resolves to:
 *   - ka_GE locale → Georgian only
 *   - any other locale → Bilingual (Georgian + transliteration in parens)
 *
 * @package CodeOn\Core\Locations\Data
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\Data;

final class DisplayFormatter
{
    /** @var array{display_mode:string, simplified_latin:bool} */
    private array $settings;

    public function __construct(array $settings)
    {
        $this->settings = [
            'display_mode'     => (string) ($settings['display_mode'] ?? 'auto'),
            'simplified_latin' => (bool) ($settings['simplified_latin'] ?? true),
        ];
    }

    public static function fromOptions(): self
    {
        $opts = get_option('codeon_core_settings', []);
        return new self(is_array($opts) ? $opts : []);
    }

    /**
     * @param array{name_ka:string, name_en?:string} $row
     */
    public function label(array $row): string
    {
        $ka = (string) $row['name_ka'];
        $en = isset($row['name_en']) ? (string) $row['name_en'] : '';

        return match ($this->resolveMode()) {
            'ka'        => $ka,
            'en'        => $en !== '' ? $en : Transliterator::toLatin($ka, $this->settings['simplified_latin']),
            'bilingual' => $en !== ''
                ? $ka . ' (' . $en . ')'
                : Transliterator::bilingual($ka, $this->settings['simplified_latin']),
            default     => $ka,
        };
    }

    private function resolveMode(): string
    {
        $mode = $this->settings['display_mode'];
        if ($mode === 'auto') {
            return determine_locale() === 'ka_GE' ? 'ka' : 'bilingual';
        }
        if (!in_array($mode, ['ka', 'en', 'bilingual'], true)) {
            return 'ka';
        }
        return $mode;
    }
}
