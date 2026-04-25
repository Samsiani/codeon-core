<?php

declare(strict_types=1);

namespace CodeOn\Framework\Admin;

/**
 * Frozen value object describing one cell in the dashboard health grid OR
 * one tab dot in the navigation.
 *
 * Tone is the visual hint — the framework's CSS maps each tone to a token
 * colour. `actionUrl` + `actionLabel` are optional; when present, the card
 * renders as a clickable surface, otherwise it stays informational only.
 *
 * Frozen so plugins can construct one inline inside a Manifest callback
 * without worrying about downstream mutation.
 */
final class HealthCard
{
    public const TONE_OK    = 'ok';
    public const TONE_WARN  = 'warn';
    public const TONE_ERR   = 'err';
    public const TONE_MUTED = 'muted';

    public function __construct(
        public readonly string $title,
        public readonly string $tone,
        public readonly string $label,
        public readonly string $detail = '',
        public readonly string $actionUrl = '',
        public readonly string $actionLabel = '',
    ) {
    }

    public static function ok(string $title, string $label, string $detail = ''): self
    {
        return new self($title, self::TONE_OK, $label, $detail);
    }

    public static function warn(string $title, string $label, string $detail = ''): self
    {
        return new self($title, self::TONE_WARN, $label, $detail);
    }

    public static function err(string $title, string $label, string $detail = ''): self
    {
        return new self($title, self::TONE_ERR, $label, $detail);
    }

    public static function muted(string $title, string $label, string $detail = ''): self
    {
        return new self($title, self::TONE_MUTED, $label, $detail);
    }

    public function withAction(string $url, string $label): self
    {
        return new self($this->title, $this->tone, $this->label, $this->detail, $url, $label);
    }
}
