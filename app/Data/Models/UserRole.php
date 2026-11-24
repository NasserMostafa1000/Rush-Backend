<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserRole extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'code', 'name'];

    public const PERSONAL = 1;

    public const STORE = 2;

    public const DRIVER = 3;

    public const ADMIN = 4;

    /** @var list<string> */
    public const CODES = ['personal', 'store', 'driver', 'admin'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'user_role_id');
    }

    public static function idFromCode(string $code): int
    {
        $code = strtolower(trim($code));

        return match ($code) {
            'personal' => self::PERSONAL,
            'store' => self::STORE,
            'driver' => self::DRIVER,
            'admin' => self::ADMIN,
            default => throw new \InvalidArgumentException("Unknown user role: {$code}"),
        };
    }

    public static function codeFromId(int $id): string
    {
        return match ($id) {
            self::PERSONAL => 'personal',
            self::STORE => 'store',
            self::DRIVER => 'driver',
            self::ADMIN => 'admin',
            default => 'personal',
        };
    }
}
