<?php

namespace App\Business\Services;

use App\Business\Exceptions\ApiException;
use App\Data\Models\DriverProfile;
use App\Data\Models\User;

class LocationService
{
    public function __construct(private readonly H3Service $h3)
    {
    }

    /**
     * Persist GPS on users (+ sync driver_profiles when driver) and compute H3 index.
     *
     * @return array{latitude: string, longitude: string, h3_index: string, location_updated_at: string|null}
     */
    public function update(User $user, float $latitude, float $longitude): array
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw ApiException::make('location_invalid', 'latitude/longitude out of range.', 422);
        }

        $h3Index = $this->h3->index($latitude, $longitude);

        $user->forceFill([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'h3_index' => $h3Index,
            'location_updated_at' => now(),
        ])->save();

        if ($user->isDriver()) {
            DriverProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'last_latitude' => $latitude,
                    'last_longitude' => $longitude,
                ],
            );
        }

        return $this->present($user->fresh());
    }

    /**
     * @return array{latitude: string|null, longitude: string|null, h3_index: string|null, location_updated_at: string|null}
     */
    public function present(User $user): array
    {
        return [
            'latitude' => $user->latitude !== null ? (string) $user->latitude : null,
            'longitude' => $user->longitude !== null ? (string) $user->longitude : null,
            'h3_index' => $user->h3_index,
            'location_updated_at' => optional($user->location_updated_at)?->toIso8601String(),
        ];
    }
}
