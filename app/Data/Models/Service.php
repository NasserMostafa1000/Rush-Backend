<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'service_name',
        'standard_price',
        'rushing_time_price',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'standard_price' => 'decimal:2',
            'rushing_time_price' => 'decimal:2',
        ];
    }

    public const SEND_A_PACKAGE = 1;

    public const RECEIVE_A_PACKAGE = 2;

    public const PERSONAL_SHOPPING = 3;

    public const REQUEST_DELIVERY = 4;

    /** @deprecated use PERSONAL_SHOPPING */
    public const PACKAGE_FROM_MARKET = 3;
}
