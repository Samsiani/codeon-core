<?php

declare(strict_types=1);

namespace CodeOn\Framework\Schema;

use Closure;
use CodeOn\Framework\Storage\SettingsRepository;
use WP_Error;

/**
 * Pure function: takes a Field[] schema + the raw $_POST['codeon'] payload,
 * returns the cleaned key/value pairs ready to be persisted.
 *
 * The validator never writes to a repository directly. The caller (the Tab's
 * save flow inside Page::dispatch) takes the result and pushes it through
 * the chosen adapter — that keeps storage and validation independently
 * testable, and lets the same schema run against any storage adapter.
 *
 * Each field gets its type's default sanitizer first, then any chained
 * sanitize() callbacks, then any validate() callbacks. A failing validator
 * leaves the *previous* stored value in place for that field — partial
 * failures never wipe data.
 */
final class FieldValidator
{
    /**
     * @param Field[]              $schema
     * @param array<string,mixed>  $posted   The framework's `codeon[...]` array.
     * @param SettingsRepository   $repo     Existing values used to honour write-only password fields.
     *
     * @return array{clean:array<string,mixed>,errors:array<string,WP_Error>}
     */
    public static function process(array $schema, array $posted, SettingsRepository $repo): array
    {
        $clean = [];
        $errors = [];

        foreach ($schema as $field) {
            if (in_array($field->type, [FieldType::HEADING, FieldType::RAW], true)) {
                continue;
            }

            $raw = $posted[$field->path] ?? null;

            // Write-only password: blank submission means "keep stored value".
            if ($field->type === FieldType::PASSWORD && $field->isWriteOnly()) {
                if ($raw === null || $raw === '') {
                    continue;
                }
            }

            // Conditional show_when fields that are currently hidden should not
            // overwrite their stored value with an absent payload.
            $sw = $field->getShowWhen();
            if ($sw !== null && $raw === null) {
                continue;
            }

            // Multiselect with no checkboxes ticked posts as null but means [].
            if ($field->type === FieldType::MULTISELECT && $raw === null) {
                $raw = [];
            }

            $sanitizers = array_merge([self::defaultSanitizerFor($field->type)], $field->getSanitizers());
            $value = $raw;
            foreach ($sanitizers as $cb) {
                if ($cb instanceof Closure) {
                    $value = $cb($value, $field);
                }
            }

            $error = null;
            foreach ($field->getValidators() as $cb) {
                $result = $cb($value, $field, $repo);
                if ($result instanceof WP_Error) {
                    $error = $result;
                    break;
                }
            }

            if ($error !== null) {
                $errors[$field->path] = $error;
                continue;
            }

            $clean[$field->path] = $value;
        }

        return ['clean' => $clean, 'errors' => $errors];
    }

    public static function defaultSanitizerFor(FieldType $type): Closure
    {
        return match ($type) {
            FieldType::PASSWORD =>
                static fn ($v) => is_string($v) ? trim($v) : '',
            FieldType::URL =>
                static fn ($v) => is_string($v) ? esc_url_raw(trim($v)) : '',
            FieldType::NUMBER =>
                static fn ($v) => is_numeric($v) ? $v + 0 : 0,
            FieldType::CHECKBOX =>
                static fn ($v) => in_array($v, [1, '1', true, 'true', 'on'], true),
            FieldType::SELECT, FieldType::RADIO, FieldType::RADIO_CARDS =>
                static fn ($v, Field $f) => self::clampToOptions((string) ($v ?? ''), $f),
            FieldType::MULTISELECT =>
                static fn ($v, Field $f) => self::filterToOptions(is_array($v) ? $v : [], $f),
            FieldType::TEXTAREA =>
                static fn ($v) => is_string($v) ? sanitize_textarea_field($v) : '',
            default =>
                static fn ($v) => is_scalar($v) ? sanitize_text_field((string) $v) : '',
        };
    }

    private static function clampToOptions(string $value, Field $field): string
    {
        $opts = array_map('strval', array_keys($field->getOptions()));
        if (in_array($value, $opts, true)) {
            return $value;
        }
        $default = $field->getDefault();
        if ($default !== null && in_array((string) $default, $opts, true)) {
            return (string) $default;
        }
        return $opts[0] ?? '';
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private static function filterToOptions(array $values, Field $field): array
    {
        $opts = array_map('strval', array_keys($field->getOptions()));
        $clean = [];
        foreach ($values as $v) {
            $s = (string) $v;
            if (in_array($s, $opts, true)) {
                $clean[] = $s;
            }
        }
        return array_values(array_unique($clean));
    }
}
