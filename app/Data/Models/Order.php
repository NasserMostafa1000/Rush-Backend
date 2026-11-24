<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'driver_id',
        'service_id',
        'status_id',
        'total',
        'subtotal',
        'discount_amount',
        'tip_amount',
        'loyalty_discount_amount',
        'loyalty_points_redeemed',
        'promo_code_id',
        'payment_method',
        'payer',
        'pickup_timing',
        'scheduled_pickup_at',
        'distance_km',
        'estimated_pickup_minutes',
        'estimated_delivery_minutes',
        'rating',
        'rating_comment',
        'delivery_proof_path',
        'picked_up_at',
        'delivered_at',
        'cancelled_at',
        'cancellation_reason',
        'refund_status',
        'ordered_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tip_amount' => 'decimal:2',
            'loyalty_discount_amount' => 'decimal:2',
            'distance_km' => 'decimal:2',
            'loyalty_points_redeemed' => 'integer',
            'estimated_pickup_minutes' => 'integer',
            'estimated_delivery_minutes' => 'integer',
            'rating' => 'integer',
            'ordered_at' => 'datetime',
            'scheduled_pickup_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'service_id' => 'integer',
            'status_id' => 'integer',
            'user_id' => 'integer',
            'driver_id' => 'integer',
            'promo_code_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(OrderDetail::class, 'order_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class, 'order_id')->orderBy('occurred_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'order_id');
    }
}
