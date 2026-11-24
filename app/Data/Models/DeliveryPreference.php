<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryPreference extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'code',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
        ];
    }

    public const CALL_ON_ARRIVAL = 1;

    public const DONT_KNOCK_ON_THE_DOOR = 2;

    public const LEAVE = 3;

    public const LEAVE_AT_RECEPTION = 4;

    public const RING_THE_BELL = 5;
}
