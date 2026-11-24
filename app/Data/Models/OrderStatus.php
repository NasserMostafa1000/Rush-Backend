<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'code', 'label'];

    public const PENDING_PAYMENT = 1;

    public const CONFIRMED = 2;

    public const DRIVER_ASSIGNED = 3;

    public const PICKED_UP = 4;

    public const DELIVERED = 5;

    public const CANCELLED = 6;

    public const AWAITING_CASH = 7;

    public const AWAITING_DRIVER = 8;

    /** Customer may cancel only before a driver accepts the order. */
    public const CANCELLABLE = [
        self::PENDING_PAYMENT,
        self::AWAITING_CASH,
        self::CONFIRMED,
        self::AWAITING_DRIVER,
    ];

    public static function isCancellable(int $id): bool
    {
        return in_array($id, self::CANCELLABLE, true);
    }

    public static function idFromCode(string $code): int
    {
        $code = strtolower(trim($code));

        return match ($code) {
            'pending_payment' => self::PENDING_PAYMENT,
            'confirmed' => self::CONFIRMED,
            'driver_assigned' => self::DRIVER_ASSIGNED,
            'picked_up' => self::PICKED_UP,
            'delivered' => self::DELIVERED,
            'cancelled' => self::CANCELLED,
            'awaiting_cash' => self::AWAITING_CASH,
            'awaiting_driver' => self::AWAITING_DRIVER,
            'waiting_for_driver' => self::AWAITING_DRIVER,
            default => throw new \InvalidArgumentException("Unknown order status: {$code}"),
        };
    }

    public static function codeFromId(int $id): string
    {
        return match ($id) {
            self::PENDING_PAYMENT => 'pending_payment',
            self::CONFIRMED => 'confirmed',
            self::DRIVER_ASSIGNED => 'driver_assigned',
            self::PICKED_UP => 'picked_up',
            self::DELIVERED => 'delivered',
            self::CANCELLED => 'cancelled',
            self::AWAITING_CASH => 'awaiting_cash',
            self::AWAITING_DRIVER => 'awaiting_driver',
            default => 'pending_payment',
        };
    }
}
