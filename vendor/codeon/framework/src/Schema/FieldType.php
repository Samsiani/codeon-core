<?php

declare(strict_types=1);

namespace CodeOn\Framework\Schema;

/**
 * The complete set of field types the framework's renderer knows how to draw.
 *
 * Adding a type means:
 *   1. New case here.
 *   2. A render branch in {@see FieldRenderer::renderRow()}.
 *   3. A default sanitizer in {@see FieldValidator::defaultSanitizerFor()}.
 *   4. An entry in docs/FIELD_SCHEMA.md.
 *
 * MAP_PICKER and RAW are intentionally last — they exist for plugins that need
 * an escape hatch (a custom partial or a Google-Maps-bound coord pair) without
 * forcing the framework to model every conceivable widget.
 */
enum FieldType: string
{
    case TEXT        = 'text';
    case PASSWORD    = 'password';
    case URL         = 'url';
    case NUMBER      = 'number';
    case SELECT      = 'select';
    case MULTISELECT = 'multiselect';
    case RADIO       = 'radio';
    case RADIO_CARDS = 'radio_cards';
    case CHECKBOX    = 'checkbox';
    case TEXTAREA    = 'textarea';
    case HEADING     = 'heading';
    case RAW         = 'raw';
    case MAP_PICKER  = 'map_picker';
}
