<?php

namespace App\Data\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected $fillable = [
        'public_id',
        'invite_code',
        'invited_by_user_id',
        'loyalty_points',
        'license_image_path',
        'profile_image_path',
        'push_notifications_enabled',
        'invite_reward_granted',
        'name',
        'email',
        'phone',
        'password',
        'user_role_id',
        'preferred_language',
        'os_provider_id',
        'google_id',
        'apple_id',
        'phone_verified_at',
        'last_login_at',
        'latitude',
        'longitude',
        'h3_index',
        'location_updated_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google_id',
        'apple_id',
    ];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'location_updated_at' => 'datetime',
            'password' => 'hashed',
            'user_role_id' => 'integer',
            'os_provider_id' => 'integer',
            'loyalty_points' => 'integer',
            'push_notifications_enabled' => 'boolean',
            'invite_reward_granted' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(UserRole::class, 'user_role_id');
    }

    public function osProvider(): BelongsTo
    {
        return $this->belongsTo(OsProvider::class, 'os_provider_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by_user_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function savedAddresses(): HasMany
    {
        return $this->hasMany(SavedAddress::class);
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function driverVehicles(): HasMany
    {
        return $this->hasMany(DriverVehicle::class);
    }

    public function userTypeCode(): string
    {
        return UserRole::codeFromId((int) $this->user_role_id);
    }

    public function isDriver(): bool
    {
        return (int) $this->user_role_id === UserRole::DRIVER;
    }

    public function authProviderCode(): string
    {
        return OsProvider::codeFromId((int) $this->os_provider_id);
    }
}
