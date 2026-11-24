<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverVehicle extends Model
{
    protected $fillable = [
        'user_id',
        'service_transportation_id',
        'model',
        'color',
        'plate_number',
        'vehicle_image_path',
        'document_path',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'service_transportation_id' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transportation(): BelongsTo
    {
        return $this->belongsTo(ServiceTransportation::class, 'service_transportation_id');
    }
}
