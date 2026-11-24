<?php

namespace App\Business\Support;

use Illuminate\Support\Str;

class RushId
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** RSH- + 6 chars (e.g. RSH-A3K9MX) */
    public static function generate(): string
    {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'RSH-'.$suffix;
    }

    public static function isValid(string $id): bool
    {
        return (bool) preg_match('/^RSH-[A-Z0-9]{6}$/', Str::upper($id));
    }
}
