<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $cacheKey = 'app_setting:'.$key;

        return Cache::remember($cacheKey, 60, function () use ($key, $default) {
            $row = static::query()->find($key);

            return $row?->value ?? $default;
        });
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_scalar($value) || $value === null ? (string) $value : json_encode($value)]
        );

        Cache::forget('app_setting:'.$key);
    }

    public static function floatValue(string $key, float $default = 0.0): float
    {
        return (float) static::getValue($key, $default);
    }

    public static function intValue(string $key, int $default = 0): int
    {
        return (int) static::getValue($key, $default);
    }
}
