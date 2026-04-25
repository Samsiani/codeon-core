<?php

declare(strict_types=1);

namespace CodeOn\Framework\Schema;

use Closure;

/**
 * Fluent builder describing one row of a settings form.
 *
 * A {@see Tab::schema()} returns an array of these. The renderer walks them
 * top-to-bottom and emits one form-table row per field. Validation, sanitization,
 * and conditional visibility are all declared here so a plugin never writes
 * HTML for its admin pages.
 *
 * The chained modifiers return $this so a schema reads as one statement per
 * field — the order matters only for visual layout, never for storage.
 */
final class Field
{
    private string $description = '';
    private mixed $default = null;
    /** @var array<string,string> */
    private array $options = [];
    /** @var (Closure():array<string,string>)|null */
    private ?Closure $optionsCallback = null;
    /** @var array<string,string> */
    private array $optionHelp = [];
    /** @var Closure[] */
    private array $sanitizers = [];
    /** @var Closure[] */
    private array $validators = [];
    private ?string $placeholder = null;
    private ?string $autocomplete = null;
    private bool $wide = false;
    private bool $writeOnly = false;
    /** @var array{path:string,op:string,value:mixed}|null */
    private ?array $showWhen = null;
    /** @var array<string,mixed> */
    private array $extras = [];

    private function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly FieldType $type,
    ) {
    }

    public static function text(string $path, string $label = ''): self
    {
        return new self($path, $label, FieldType::TEXT);
    }

    public static function password(string $path, string $label = ''): self
    {
        // Default to write-only — most credentials shouldn't echo back.
        return (new self($path, $label, FieldType::PASSWORD))->writeOnly();
    }

    public static function url(string $path, string $label = ''): self
    {
        return new self($path, $label, FieldType::URL);
    }

    public static function number(string $path, string $label = ''): self
    {
        return new self($path, $label, FieldType::NUMBER);
    }

    public static function select(string $path, string $label = ''): self
    {
        return new self($path, $label, FieldType::SELECT);
    }

    public static function multiselect(string $path, string $label = ''): self
    {
        return new self($path, $label, FieldType::MULTISELECT);
    }

    public static function radio(string $path, string $label = ''): self
    {
        return new self($path, $label, FieldType::RADIO);
    }

    public static function radioCards(string $path, string $label = ''): self
    {
        return new self($path, $label, FieldType::RADIO_CARDS);
    }

    public static function checkbox(string $path, string $label = ''): self
    {
        return new self($path, $label, FieldType::CHECKBOX);
    }

    public static function textarea(string $path, string $label = ''): self
    {
        return new self($path, $label, FieldType::TEXTAREA);
    }

    public static function heading(string $id, string $label, string $description = ''): self
    {
        return (new self($id, $label, FieldType::HEADING))->description($description);
    }

    /** Render arbitrary HTML inside a row. The callable receives the current value (always null). */
    public static function raw(string $id, Closure $renderer): self
    {
        $f = new self($id, '', FieldType::RAW);
        $f->extras['renderer'] = $renderer;

        return $f;
    }

    public static function mapPicker(string $latPath, string $lngPath, string $mapKeyPath): self
    {
        $f = new self($latPath, '', FieldType::MAP_PICKER);
        $f->extras['lat_path'] = $latPath;
        $f->extras['lng_path'] = $lngPath;
        $f->extras['map_key_path'] = $mapKeyPath;

        return $f;
    }

    // ---------------------------------------------------------------------
    // Modifiers
    // ---------------------------------------------------------------------

    public function description(string $text): self
    {
        $this->description = $text;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        return $this;
    }

    /** @param array<string,string> $options */
    public function options(array $options): self
    {
        $this->options = $options;
        return $this;
    }

    /** @param Closure():array<string,string> $cb */
    public function optionsCallback(Closure $cb): self
    {
        $this->optionsCallback = $cb;
        return $this;
    }

    /** @param array<string,string> $help */
    public function optionHelp(array $help): self
    {
        $this->optionHelp = $help;
        return $this;
    }

    public function placeholder(string $text): self
    {
        $this->placeholder = $text;
        return $this;
    }

    public function autocomplete(string $value): self
    {
        $this->autocomplete = $value;
        return $this;
    }

    public function wide(): self
    {
        $this->wide = true;
        return $this;
    }

    public function writeOnly(): self
    {
        $this->writeOnly = true;
        return $this;
    }

    public function showWhen(string $path, string $op, mixed $value): self
    {
        $this->showWhen = ['path' => $path, 'op' => $op, 'value' => $value];
        return $this;
    }

    public function sanitize(Closure $cb): self
    {
        $this->sanitizers[] = $cb;
        return $this;
    }

    public function validate(Closure $cb): self
    {
        $this->validators[] = $cb;
        return $this;
    }

    public function with(string $key, mixed $value): self
    {
        $this->extras[$key] = $value;
        return $this;
    }

    // ---------------------------------------------------------------------
    // Read-only accessors used by Renderer / Validator
    // ---------------------------------------------------------------------

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    /** @return array<string,string> */
    public function getOptions(): array
    {
        if ($this->optionsCallback !== null) {
            return ($this->optionsCallback)();
        }
        return $this->options;
    }

    /** @return array<string,string> */
    public function getOptionHelp(): array
    {
        return $this->optionHelp;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function getAutocomplete(): ?string
    {
        return $this->autocomplete;
    }

    public function isWide(): bool
    {
        return $this->wide;
    }

    public function isWriteOnly(): bool
    {
        return $this->writeOnly;
    }

    /** @return array{path:string,op:string,value:mixed}|null */
    public function getShowWhen(): ?array
    {
        return $this->showWhen;
    }

    /** @return Closure[] */
    public function getSanitizers(): array
    {
        return $this->sanitizers;
    }

    /** @return Closure[] */
    public function getValidators(): array
    {
        return $this->validators;
    }

    public function extra(string $key, mixed $default = null): mixed
    {
        return $this->extras[$key] ?? $default;
    }
}
