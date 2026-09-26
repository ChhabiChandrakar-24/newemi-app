<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function toPaise(int|string $amount): int
    {
        $normalized = trim((string) $amount);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        [$rupees, $paise] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $rupees * 100) + (int) str_pad($paise, 2, '0');
    }

    public static function fromPaise(int $paise): string
    {
        return sprintf('%d.%02d', intdiv($paise, 100), abs($paise % 100));
    }
}
