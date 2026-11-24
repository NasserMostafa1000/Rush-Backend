<?php

namespace App\Business\Services;

use App\Business\Exceptions\ApiException;
use App\Data\Models\SavedAddress;
use App\Data\Models\User;

class SavedAddressService
{
    public function listFor(User $user): array
    {
        return [
            'addresses' => SavedAddress::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (SavedAddress $a) => $this->present($a))
                ->values()
                ->all(),
        ];
    }

    public function create(User $user, array $data): array
    {
        $address = SavedAddress::query()->create([
            'user_id' => $user->id,
            'label' => strtolower((string) $data['label']),
            'address' => $data['address'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'notes' => $data['notes'] ?? null,
        ]);

        return ['address' => $this->present($address)];
    }

    public function update(User $user, int $id, array $data): array
    {
        $address = $this->findOwned($user, $id);
        $address->forceFill(array_filter([
            'label' => isset($data['label']) ? strtolower((string) $data['label']) : null,
            'address' => $data['address'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($v) => $v !== null))->save();

        return ['address' => $this->present($address->fresh())];
    }

    public function delete(User $user, int $id): array
    {
        $this->findOwned($user, $id)->delete();

        return ['ok' => true];
    }

    private function findOwned(User $user, int $id): SavedAddress
    {
        $address = SavedAddress::query()
            ->where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (! $address) {
            throw ApiException::make('not_found', 'Address not found.', 404);
        }

        return $address;
    }

    private function present(SavedAddress $a): array
    {
        return [
            'id' => $a->id,
            'label' => $a->label,
            'address' => $a->address,
            'latitude' => (string) $a->latitude,
            'longitude' => (string) $a->longitude,
            'notes' => $a->notes,
            'created_at' => $a->created_at?->utc()->toIso8601String(),
        ];
    }
}
