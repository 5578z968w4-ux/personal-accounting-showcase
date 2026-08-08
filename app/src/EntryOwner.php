<?php

declare(strict_types=1);

final class EntryOwner
{
    public const PROFILE_A = 'profile_a';
    public const PROFILE_B = 'profile_b';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::PROFILE_A => '展示對象 A',
            self::PROFILE_B => '展示對象 B',
        ];
    }

    public static function normalize(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::PROFILE_A;
        }

        $owner = trim((string) $value);
        return match ($owner) {
            self::PROFILE_A, '展示對象 A' => self::PROFILE_A,
            self::PROFILE_B, '展示對象 B' => self::PROFILE_B,
            default => throw new InvalidArgumentException('invalid_entry_owner'),
        };
    }

    public static function label(mixed $value): string
    {
        try {
            $owner = self::normalize($value);
        } catch (InvalidArgumentException) {
            return (string) $value;
        }

        return self::labels()[$owner];
    }
}
