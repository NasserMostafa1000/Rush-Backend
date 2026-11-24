<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceTransportation extends Model
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

    public const CAR = 1;

    public const MOTORCYCLE = 2;
}
