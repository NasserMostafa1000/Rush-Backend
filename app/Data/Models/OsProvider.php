<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OsProvider extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'code', 'name'];

    public const GOOGLE = 1;

    public const APPLE = 2;

    public const PHONE = 3;

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'os_provider_id');
    }

    public static function idFromCode(string $code): int
    {
        $code = strtolower(trim($code));

        return match ($code) {
            'google' => self::GOOGLE,
            'apple' => self::APPLE,
            'phone' => self::PHONE,
            default => throw new \InvalidArgumentException("Unknown OS provider: {$code}"),
        };
    }

    public static function codeFromId(int $id): string
    {
        return match ($id) {
            self::GOOGLE => 'google',
            self::APPLE => 'apple',
            self::PHONE => 'phone',
            default => 'phone',
        };
    }
}
