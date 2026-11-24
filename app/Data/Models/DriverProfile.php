<?php

namespace App\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverProfile extends Model
{
    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_INCOMPLETE,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'user_id',
        'national_id_number',
        'national_id_front_path',
        'national_id_back_path',
        'personal_photo_path',
        'driving_license_front_path',
        'emergency_contact_name',
        'emergency_contact_phone',
        'bank_name',
        'iban',
        'ziina_payment_link',
        'service_area',
        'verification_status',
        'verification_note',
        'submitted_at',
        'verified_at',
        'is_online',
        'last_latitude',
        'last_longitude',
        'last_online_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'is_online' => 'boolean',
            'last_latitude' => 'decimal:7',
            'last_longitude' => 'decimal:7',
            'last_online_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canGoOnline(): bool
    {
        return $this->verification_status === self::STATUS_APPROVED;
    }
}
