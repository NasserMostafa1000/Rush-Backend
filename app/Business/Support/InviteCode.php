<?php

namespace App\Business\Support;

use App\Data\Models\User;
use Illuminate\Support\Str;

final class InviteCode
{
    public static function generate(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $code = strtoupper(Str::random(8));
            if (! User::query()->where('invite_code', $code)->exists()) {
                return $code;
            }
        }

        return strtoupper(bin2hex(random_bytes(4)));
    }
}
