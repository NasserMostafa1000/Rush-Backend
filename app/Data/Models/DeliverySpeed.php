<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;

class DeliverySpeed extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'code', 'label'];

    public const STANDARD = 1;

    public const RUSH = 2;

    /** @var list<string> */
    public const CODES = ['standard', 'rush'];

    public static function idFromCode(string $code): int
    {
        return match (strtolower(trim($code))) {
            'standard' => self::STANDARD,
            'rush' => self::RUSH,
            default => throw new \InvalidArgumentException("Unknown delivery speed: {$code}"),
        };
    }

    public static function codeFromId(int $id): string
    {
        return match ($id) {
            self::STANDARD => 'standard',
            self::RUSH => 'rush',
            default => 'standard',
        };
    }
}
