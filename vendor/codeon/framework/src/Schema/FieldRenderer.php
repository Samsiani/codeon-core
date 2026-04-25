<?php

declare(strict_types=1);

namespace CodeOn\Framework\Schema;

use CodeOn\Framework\Storage\SettingsRepository;

/**
 * Walks a Field[] schema and emits the form-table HTML.
 *
 * The renderer is intentionally a pure function of (schema, repository) — it
 * never reads $_POST and never writes options. Save flow lives in
 * {@see FieldValidator} so the two halves can be tested independently.
 *
 * The HTML it produces is the same shape WordPress uses for its core settings
 * pages (`<table class="form-table">` rows), then progressively enhanced with
 * .codeon-* classes on the wrapper so the framework's CSS can re-style it
 * without touching WordPress's own selectors.
 */
final class FieldRenderer
{
    /**
     * @param Field[] $schema
     */
    public static function renderForm(
        array $schema,
        ?SettingsRepository $repo,
        string $tabSlug,
        string $nonceAction,
        string $submitLabel = ''
    ): void {
        if ($submitLabel === '') {
            $submitLabel = function_exists('__')
                ? \__('Save changes', 'codeon-framework')
                : 'Save changes';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="codeon-form">';
        wp_nonce_field($nonceAction);
        echo '<input type="hidden" name="action" value="codeon_save_tab" />';
        echo '<input type="hidden" name="codeon_tab" value="' . esc_attr($tabSlug) . '" />';
        // Slug discriminator — without this, every co-installed framework
        // consumer's Page::handleSave fires on the same admin_post action
        // and the FIRST registered Page's nonceAction check wins (or fails)
        // regardless of which plugin's form was actually submitted. The
        // slug pulled from the nonce action lets handleSave bail when
        // this Page isn't the target.
        $slug = (string) preg_replace('/^codeon_admin_/', '', $nonceAction);
        echo '<input type="hidden" name="codeon_plugin_slug" value="' . esc_attr($slug) . '" />';

        self::renderRows($schema, $repo);

        echo '<p class="submit codeon-submit"><button type="submit" class="button button-primary codeon-button">'
            . esc_html($submitLabel)
            . '</button></p>';
        echo '</form>';
    }

    /**
     * @param Field[] $schema
     */
    public static function renderRows(array $schema, ?SettingsRepository $repo): void
    {
        echo '<table class="form-table codeon-form-table" role="presentation"><tbody>';
        foreach ($schema as $field) {
            self::renderRow($field, $repo);
        }
        echo '</tbody></table>';
    }

    private static function renderRow(Field $field, ?SettingsRepository $repo): void
    {
        $value = $repo?->get($field->path, $field->getDefault()) ?? $field->getDefault();
        $rowAttrs = self::rowAttrs($field);

        if ($field->type === FieldType::HEADING) {
            echo '<tr' . $rowAttrs . ' class="codeon-row codeon-row-heading">';
            echo '<th colspan="2" scope="row"><h2 class="codeon-section-h2">' . esc_html($field->label) . '</h2>';
            if ($field->getDescription() !== '') {
                echo '<p class="description codeon-section-description">' . wp_kses_post($field->getDescription()) . '</p>';
            }
            echo '</th></tr>';
            return;
        }

        if ($field->type === FieldType::RAW) {
            $renderer = $field->extra('renderer');
            echo '<tr' . $rowAttrs . ' class="codeon-row codeon-row-raw"><td colspan="2">';
            if (is_callable($renderer)) {
                $renderer($value);
            }
            echo '</td></tr>';
            return;
        }

        echo '<tr' . $rowAttrs . ' class="codeon-row codeon-row-' . esc_attr($field->type->value) . '">';
        echo '<th scope="row"><label for="' . esc_attr(self::inputId($field)) . '">' . esc_html($field->label) . '</label></th>';
        echo '<td>';

        match ($field->type) {
            FieldType::TEXT, FieldType::URL => self::renderTextLike($field, (string) ($value ?? '')),
            FieldType::PASSWORD             => self::renderPassword($field, (string) ($value ?? '')),
            FieldType::NUMBER               => self::renderNumber($field, $value),
            FieldType::SELECT               => self::renderSelect($field, $value),
            FieldType::MULTISELECT          => self::renderMultiselect($field, (array) ($value ?? [])),
            FieldType::RADIO                => self::renderRadio($field, $value),
            FieldType::RADIO_CARDS          => self::renderRadioCards($field, $value),
            FieldType::CHECKBOX             => self::renderCheckbox($field, (bool) $value),
            FieldType::TEXTAREA             => self::renderTextarea($field, (string) ($value ?? '')),
            FieldType::MAP_PICKER           => self::renderMapPicker($field, $repo),
            default                         => null,
        };

        if ($field->getDescription() !== '') {
            echo '<p class="description codeon-row-description">' . wp_kses_post($field->getDescription()) . '</p>';
        }
        echo '</td></tr>';
    }

    private static function rowAttrs(Field $field): string
    {
        $sw = $field->getShowWhen();
        if ($sw === null) {
            return '';
        }
        return sprintf(
            ' data-codeon-show="%s" data-codeon-show-op="%s" data-codeon-show-value="%s"',
            esc_attr($sw['path']),
            esc_attr($sw['op']),
            esc_attr(is_scalar($sw['value']) ? (string) $sw['value'] : wp_json_encode($sw['value']))
        );
    }

    private static function inputId(Field $field): string
    {
        return 'codeon_' . str_replace(['.', '[', ']'], '_', $field->path);
    }

    private static function inputName(Field $field): string
    {
        return 'codeon[' . $field->path . ']';
    }

    private static function renderTextLike(Field $field, string $value): void
    {
        printf(
            '<input type="%s" id="%s" name="%s" value="%s" class="%s"%s%s />',
            $field->type === FieldType::URL ? 'url' : 'text',
            esc_attr(self::inputId($field)),
            esc_attr(self::inputName($field)),
            esc_attr($value),
            $field->isWide() ? 'large-text codeon-input codeon-input-wide' : 'regular-text codeon-input',
            $field->getPlaceholder() !== null ? ' placeholder="' . esc_attr($field->getPlaceholder()) . '"' : '',
            $field->getAutocomplete() !== null ? ' autocomplete="' . esc_attr($field->getAutocomplete()) . '"' : ''
        );
    }

    private static function renderPassword(Field $field, string $stored): void
    {
        $hasValue = $stored !== '';
        printf(
            '<input type="password" id="%s" name="%s" value="" class="regular-text codeon-input"%s%s data-codeon-write-only="1" />',
            esc_attr(self::inputId($field)),
            esc_attr(self::inputName($field)),
            $field->getPlaceholder() !== null
                ? ' placeholder="' . esc_attr($field->getPlaceholder()) . '"'
                : ($hasValue
                    ? ' placeholder="' . esc_attr__('•••••• (leave blank to keep)', 'codeon-framework') . '"'
                    : ''),
            $field->getAutocomplete() !== null ? ' autocomplete="' . esc_attr($field->getAutocomplete()) . '"' : ''
        );
    }

    private static function renderNumber(Field $field, mixed $value): void
    {
        printf(
            '<input type="number" id="%s" name="%s" value="%s" class="small-text codeon-input"%s />',
            esc_attr(self::inputId($field)),
            esc_attr(self::inputName($field)),
            esc_attr((string) ($value ?? '')),
            $field->getPlaceholder() !== null ? ' placeholder="' . esc_attr($field->getPlaceholder()) . '"' : ''
        );
    }

    private static function renderSelect(Field $field, mixed $current): void
    {
        echo '<select id="' . esc_attr(self::inputId($field)) . '" name="' . esc_attr(self::inputName($field)) . '" class="codeon-input">';
        foreach ($field->getOptions() as $val => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $val),
                selected((string) $current, (string) $val, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    /** @param array<int,string> $current */
    private static function renderMultiselect(Field $field, array $current): void
    {
        $name = self::inputName($field) . '[]';
        echo '<select multiple id="' . esc_attr(self::inputId($field)) . '" name="' . esc_attr($name) . '" class="codeon-input codeon-input-multiselect" size="6">';
        foreach ($field->getOptions() as $val => $label) {
            $selected = in_array((string) $val, array_map('strval', $current), true) ? ' selected' : '';
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $val),
                $selected,
                esc_html($label)
            );
        }
        echo '</select>';
    }

    private static function renderRadio(Field $field, mixed $current): void
    {
        echo '<fieldset class="codeon-radio-group">';
        foreach ($field->getOptions() as $val => $label) {
            printf(
                '<label class="codeon-radio-row"><input type="radio" name="%s" value="%s"%s /> %s</label>',
                esc_attr(self::inputName($field)),
                esc_attr((string) $val),
                checked((string) $current, (string) $val, false),
                esc_html($label)
            );
        }
        echo '</fieldset>';
    }

    private static function renderRadioCards(Field $field, mixed $current): void
    {
        echo '<fieldset class="codeon-radio-cards">';
        $help = $field->getOptionHelp();
        foreach ($field->getOptions() as $val => $label) {
            $isOn = (string) $current === (string) $val;
            printf(
                '<label class="codeon-radio-card%s"><input type="radio" name="%s" value="%s"%s /><span class="codeon-radio-card-label">%s</span>',
                $isOn ? ' is-on' : '',
                esc_attr(self::inputName($field)),
                esc_attr((string) $val),
                checked((string) $current, (string) $val, false),
                esc_html($label)
            );
            if (isset($help[$val]) && $help[$val] !== '') {
                echo '<span class="codeon-radio-card-help">' . wp_kses_post($help[$val]) . '</span>';
            }
            echo '</label>';
        }
        echo '</fieldset>';
    }

    private static function renderCheckbox(Field $field, bool $checked): void
    {
        printf(
            '<label class="codeon-checkbox-row"><input type="hidden" name="%s" value="0" /><input type="checkbox" id="%s" name="%s" value="1"%s /> %s</label>',
            esc_attr(self::inputName($field)),
            esc_attr(self::inputId($field)),
            esc_attr(self::inputName($field)),
            checked($checked, true, false),
            esc_html($field->extra('checkbox_label', ''))
        );
    }

    private static function renderTextarea(Field $field, string $value): void
    {
        $rows = (int) $field->extra('rows', 4);
        printf(
            '<textarea id="%s" name="%s" rows="%d" class="large-text codeon-input codeon-input-textarea"%s>%s</textarea>',
            esc_attr(self::inputId($field)),
            esc_attr(self::inputName($field)),
            $rows,
            $field->getPlaceholder() !== null ? ' placeholder="' . esc_attr($field->getPlaceholder()) . '"' : '',
            esc_textarea($value)
        );
    }

    private static function renderMapPicker(Field $field, ?SettingsRepository $repo): void
    {
        $latPath = (string) $field->extra('lat_path');
        $lngPath = (string) $field->extra('lng_path');
        $keyPath = (string) $field->extra('map_key_path');
        $lat = (string) ($repo?->get($latPath, '') ?? '');
        $lng = (string) ($repo?->get($lngPath, '') ?? '');
        $mapKey = (string) ($repo?->get($keyPath, '') ?? '');

        echo '<div class="codeon-map-picker" data-codeon-map data-codeon-map-key="' . esc_attr($mapKey) . '">';
        printf(
            '<input type="text" name="codeon[%s]" value="%s" data-codeon-map-lat class="regular-text codeon-input" placeholder="%s" />',
            esc_attr($latPath),
            esc_attr($lat),
            esc_attr__('Latitude', 'codeon-framework')
        );
        printf(
            ' <input type="text" name="codeon[%s]" value="%s" data-codeon-map-lng class="regular-text codeon-input" placeholder="%s" />',
            esc_attr($lngPath),
            esc_attr($lng),
            esc_attr__('Longitude', 'codeon-framework')
        );
        echo '<div class="codeon-map-canvas" data-codeon-map-canvas></div>';
        echo '</div>';
    }
}
