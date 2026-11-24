<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDetail extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'order_id',
        'transportation_id',
        'delivery_preference_id',
        'delivery_speed_id',
        'minutes',
        'pickup_latitude',
        'pickup_longitude',
        'dropoff_latitude',
        'dropoff_longitude',
        'pickup_address',
        'dropoff_address',
        'package_size',
        'pickup_notes',
        'dropoff_notes',
        'store_name',
        'shopping_request',
        'is_already_paid',
        'expected_order_value',
        'invoice_image_path',
        'sender_name',
        'sender_phone',
        'receiver_name',
        'receiver_phone',
        'inside_the_package',
    ];

    protected function casts(): array
    {
        return [
            'transportation_id' => 'integer',
            'delivery_preference_id' => 'integer',
            'delivery_speed_id' => 'integer',
            'minutes' => 'integer',
            'pickup_latitude' => 'decimal:7',
            'pickup_longitude' => 'decimal:7',
            'dropoff_latitude' => 'decimal:7',
            'dropoff_longitude' => 'decimal:7',
            'is_already_paid' => 'boolean',
            'expected_order_value' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function transportation(): BelongsTo
    {
        return $this->belongsTo(ServiceTransportation::class, 'transportation_id');
    }

    public function deliveryPreference(): BelongsTo
    {
        return $this->belongsTo(DeliveryPreference::class, 'delivery_preference_id');
    }

    public function deliverySpeed(): BelongsTo
    {
        return $this->belongsTo(DeliverySpeed::class, 'delivery_speed_id');
    }
}
