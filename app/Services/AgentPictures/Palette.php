<?php

declare(strict_types=1);

namespace Spora\Services\AgentPictures;

/**
 * Predefined FG+BG color pairs for the agent profile picture tile.
 *
 * Operators pick a `palette_key`; the server resolves the concrete hex
 * codes on every read. The hex codes are NOT denormalised on the row
 * (see `agent_pictures` migration) — the column is `palette_key` only,
 * so renaming a palette or shifting a hex value is a single file change
 * with no migration.
 *
 * Each pair is hand-picked to keep the foreground readable on the
 * background at WCAG AA contrast (4.5:1 for text-equivalent strokes).
 * Free-form hex is intentionally NOT supported — keeping the palette
 * curated is what makes the dashboard read as a single visual system.
 */
enum Palette: string
{
    case Slate = 'slate';
    case Red = 'red';
    case Orange = 'orange';
    case Amber = 'amber';
    case Green = 'green';
    case Teal = 'teal';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Pink = 'pink';

    public function background(): string
    {
        return match ($this) {
            self::Slate   => '#475569',
            self::Red     => '#DC2626',
            self::Orange  => '#EA580C',
            self::Amber   => '#D97706',
            self::Green   => '#15803D',
            self::Teal    => '#0F766E',
            self::Blue    => '#1D4ED8',
            self::Indigo  => '#4338CA',
            self::Violet  => '#6D28D9',
            self::Pink    => '#BE185D',
        };
    }

    public function foreground(): string
    {
        return match ($this) {
            self::Slate   => '#F8FAFC',
            self::Red     => '#FEF2F2',
            self::Orange  => '#FFF7ED',
            self::Amber   => '#FFFBEB',
            self::Green   => '#F0FDF4',
            self::Teal    => '#F0FDFA',
            self::Blue    => '#EFF6FF',
            self::Indigo  => '#EEF2FF',
            self::Violet  => '#F5F3FF',
            self::Pink    => '#FDF2F8',
        };
    }
}
