<?php

namespace App\Business\Services;

use App\Business\Exceptions\ApiException;
use App\Data\Models\DriverProfile;
use App\Data\Models\DriverVehicle;
use App\Data\Models\ServiceTransportation;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DriverService
{
    public function __construct(private readonly CloudflareR2Service $r2)
    {
    }

    public function ensureProfile(User $user): DriverProfile
    {
        $this->assertDriver($user);

        return DriverProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['verification_status' => DriverProfile::STATUS_INCOMPLETE],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{driver: array}
     */
    public function updateIdentity(User $user, array $data): array
    {
        $profile = $this->ensureProfile($user);
        $updates = [];

        if (array_key_exists('national_id_number', $data) && $data['national_id_number'] !== null) {
            $updates['national_id_number'] = trim((string) $data['national_id_number']);
        }

        if (array_key_exists('service_area', $data) && $data['service_area'] !== null) {
            $updates['service_area'] = trim((string) $data['service_area']) ?: null;
        }

        if (array_key_exists('emergency_contact_name', $data) && $data['emergency_contact_name'] !== null) {
            $updates['emergency_contact_name'] = trim((string) $data['emergency_contact_name']) ?: null;
        }

        if (array_key_exists('emergency_contact_phone', $data) && $data['emergency_contact_phone'] !== null) {
            $phone = $this->normalizePhone((string) $data['emergency_contact_phone'], allowEmpty: true);
            $updates['emergency_contact_phone'] = $phone;
        }

        if (array_key_exists('bank_name', $data) && $data['bank_name'] !== null) {
            $updates['bank_name'] = trim((string) $data['bank_name']) ?: null;
        }

        if (array_key_exists('iban', $data) && $data['iban'] !== null) {
            $updates['iban'] = strtoupper(preg_replace('/\s+/', '', (string) $data['iban']) ?? '') ?: null;
        }

        if (array_key_exists('ziina_payment_link', $data) && $data['ziina_payment_link'] !== null) {
            $updates['ziina_payment_link'] = trim((string) $data['ziina_payment_link']) ?: null;
        }

        if ($updates !== []) {
            $this->applySensitiveChange($profile, $updates);
        }

        if (array_key_exists('name', $data) && $data['name'] !== null && trim((string) $data['name']) !== '') {
            $user->forceFill(['name' => trim((string) $data['name'])])->save();
        }

        return ['driver' => $this->present($user->fresh())];
    }

    /**
     * @return array{driver: array}
     */
    public function updateDocuments(
        User $user,
        ?UploadedFile $nationalIdFront = null,
        ?UploadedFile $nationalIdBack = null,
        ?UploadedFile $personalPhoto = null,
        ?UploadedFile $drivingLicenseFront = null,
    ): array {
        $profile = $this->ensureProfile($user);
        $updates = [];

        if ($nationalIdFront) {
            $updates['national_id_front_path'] = $this->r2->upload($nationalIdFront, 'drivers/id');
        }
        if ($nationalIdBack) {
            $updates['national_id_back_path'] = $this->r2->upload($nationalIdBack, 'drivers/id');
        }
        if ($personalPhoto) {
            $path = $this->r2->upload($personalPhoto, 'drivers/photos');
            $updates['personal_photo_path'] = $path;
            $user->forceFill(['profile_image_path' => $path])->save();
        }
        if ($drivingLicenseFront) {
            $path = $this->r2->upload($drivingLicenseFront, 'drivers/licenses');
            $updates['driving_license_front_path'] = $path;
            $user->forceFill(['license_image_path' => $path])->save();
        }

        if ($updates === []) {
            throw ApiException::make('documents_required', 'At least one document file is required.', 422);
        }

        $this->applySensitiveChange($profile, $updates);

        return ['driver' => $this->present($user->fresh())];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{driver: array}
     */
    public function upsertVehicle(
        User $user,
        array $data,
        ?UploadedFile $vehicleImage = null,
        ?UploadedFile $document = null,
    ): array {
        $this->ensureProfile($user);

        // Flutter sends transportation name/code; persist as service_transportations.id
        $transportationId = $this->resolveTransportationId($data['transportation'] ?? null);

        $model = trim((string) ($data['model'] ?? ''));
        $plate = strtoupper(trim((string) ($data['plate_number'] ?? '')));
        if ($model === '' || $plate === '') {
            throw ApiException::make('vehicle_fields_required', 'model and plate_number are required.', 422);
        }

        $payload = [
            'service_transportation_id' => $transportationId,
            'model' => $model,
            'color' => isset($data['color']) ? (trim((string) $data['color']) ?: null) : null,
            'plate_number' => $plate,
            'is_current' => true,
        ];

        if ($vehicleImage) {
            $payload['vehicle_image_path'] = $this->r2->upload($vehicleImage, 'drivers/vehicles');
        }
        if ($document) {
            $payload['document_path'] = $this->r2->upload($document, 'drivers/vehicles');
        }

        DB::transaction(function () use ($user, $payload) {
            DriverVehicle::query()
                ->where('user_id', $user->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $current = DriverVehicle::query()
                ->where('user_id', $user->id)
                ->where('plate_number', $payload['plate_number'])
                ->first();

            if ($current) {
                $current->forceFill($payload)->save();
            } else {
                DriverVehicle::query()->create(array_merge($payload, ['user_id' => $user->id]));
            }
        });

        $profile = $this->ensureProfile($user);
        $this->applySensitiveChange($profile, []);

        return ['driver' => $this->present($user->fresh())];
    }

    /**
     * @return array{driver: array}
     */
    public function submitForReview(User $user): array
    {
        $profile = $this->ensureProfile($user);
        $missing = $this->missingRequirements($user, $profile);
        if ($missing !== []) {
            throw ApiException::make(
                'driver_profile_incomplete',
                'Driver profile is incomplete: '.implode(', ', $missing),
                422,
                ['missing' => $missing],
            );
        }

        $profile->forceFill([
            'verification_status' => DriverProfile::STATUS_UNDER_REVIEW,
            'submitted_at' => now(),
            'verification_note' => null,
        ])->save();

        return ['driver' => $this->present($user->fresh())];
    }

    /**
     * @return array{driver: array}
     */
    public function setVerificationStatus(User $driver, string $status, ?string $note = null): array
    {
        $this->assertDriver($driver);
        $status = strtolower(trim($status));
        if (! in_array($status, [DriverProfile::STATUS_APPROVED, DriverProfile::STATUS_REJECTED, DriverProfile::STATUS_UNDER_REVIEW], true)) {
            throw ApiException::make('verification_status_invalid', 'status must be approved, rejected, or under_review.', 422);
        }

        $profile = $this->ensureProfile($driver);
        $profile->forceFill([
            'verification_status' => $status,
            'verification_note' => $note,
            'verified_at' => $status === DriverProfile::STATUS_APPROVED ? now() : null,
        ])->save();

        return ['driver' => $this->present($driver->fresh())];
    }

    public function present(User $user): array
    {
        $this->assertDriver($user);
        $profile = $this->ensureProfile($user);
        $vehicle = DriverVehicle::query()
            ->with('transportation')
            ->where('user_id', $user->id)
            ->where('is_current', true)
            ->first();

        return [
            'verification_status' => $profile->verification_status,
            'can_go_online' => $profile->canGoOnline(),
            'is_online' => (bool) $profile->is_online,
            'last_latitude' => $profile->last_latitude !== null ? (string) $profile->last_latitude : null,
            'last_longitude' => $profile->last_longitude !== null ? (string) $profile->last_longitude : null,
            'last_online_at' => optional($profile->last_online_at)?->toIso8601String(),
            'verification_note' => $profile->verification_note,
            'submitted_at' => optional($profile->submitted_at)?->toIso8601String(),
            'verified_at' => optional($profile->verified_at)?->toIso8601String(),
            'national_id_number' => $profile->national_id_number,
            'national_id_front_path' => $profile->national_id_front_path,
            'national_id_front_url' => $this->r2->url($profile->national_id_front_path),
            'national_id_back_path' => $profile->national_id_back_path,
            'national_id_back_url' => $this->r2->url($profile->national_id_back_path),
            'personal_photo_path' => $profile->personal_photo_path ?: $user->profile_image_path,
            'personal_photo_url' => $this->r2->url($profile->personal_photo_path ?: $user->profile_image_path),
            'driving_license_front_path' => $profile->driving_license_front_path ?: $user->license_image_path,
            'driving_license_front_url' => $this->r2->url($profile->driving_license_front_path ?: $user->license_image_path),
            'emergency_contact_name' => $profile->emergency_contact_name,
            'emergency_contact_phone' => $profile->emergency_contact_phone,
            'bank_name' => $profile->bank_name,
            'iban' => $profile->iban,
            'ziina_payment_link' => $profile->ziina_payment_link,
            'service_area' => $profile->service_area,
            'missing_requirements' => $this->missingRequirements($user, $profile),
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,
                'transportation_id' => (int) $vehicle->service_transportation_id,
                'transportation' => $vehicle->transportation?->code,
                'model' => $vehicle->model,
                'color' => $vehicle->color,
                'plate_number' => $vehicle->plate_number,
                'vehicle_image_path' => $vehicle->vehicle_image_path,
                'vehicle_image_url' => $this->r2->url($vehicle->vehicle_image_path),
                'document_path' => $vehicle->document_path,
                'document_url' => $this->r2->url($vehicle->document_path),
            ] : null,
        ];
    }

    /** @return list<array{id:int,code:string,label:string}> */
    public function listTransportations(): array
    {
        return ServiceTransportation::query()
            ->orderBy('id')
            ->get(['id', 'code', 'label'])
            ->map(fn (ServiceTransportation $t) => [
                'id' => (int) $t->id,
                'code' => $t->code,
                'label' => $t->label,
            ])
            ->all();
    }

    private function applySensitiveChange(DriverProfile $profile, array $updates): void
    {
        if ($profile->verification_status === DriverProfile::STATUS_APPROVED) {
            $updates['verification_status'] = DriverProfile::STATUS_UNDER_REVIEW;
            $updates['verified_at'] = null;
            $updates['submitted_at'] = now();
        } elseif ($profile->verification_status === DriverProfile::STATUS_REJECTED && $updates !== []) {
            $updates['verification_status'] = DriverProfile::STATUS_INCOMPLETE;
            $updates['verified_at'] = null;
        }

        if ($updates !== []) {
            $profile->forceFill($updates)->save();
        } elseif ($profile->verification_status === DriverProfile::STATUS_APPROVED) {
            $profile->forceFill([
                'verification_status' => DriverProfile::STATUS_UNDER_REVIEW,
                'verified_at' => null,
                'submitted_at' => now(),
            ])->save();
        }
    }

    /**
     * @return list<string>
     */
    private function missingRequirements(User $user, DriverProfile $profile): array
    {
        $missing = [];
        if (blank($user->name)) {
            $missing[] = 'name';
        }
        if (blank($profile->national_id_number)) {
            $missing[] = 'national_id_number';
        }
        if (blank($profile->national_id_front_path)) {
            $missing[] = 'national_id_front';
        }
        if (blank($profile->national_id_back_path)) {
            $missing[] = 'national_id_back';
        }
        if (blank($profile->personal_photo_path) && blank($user->profile_image_path)) {
            $missing[] = 'personal_photo';
        }
        if (blank($profile->driving_license_front_path) && blank($user->license_image_path)) {
            $missing[] = 'driving_license_front';
        }
        if (blank($profile->emergency_contact_name)) {
            $missing[] = 'emergency_contact_name';
        }
        if (blank($profile->emergency_contact_phone)) {
            $missing[] = 'emergency_contact_phone';
        }

        $vehicle = DriverVehicle::query()
            ->where('user_id', $user->id)
            ->where('is_current', true)
            ->first();
        if (! $vehicle) {
            $missing[] = 'vehicle';
        } else {
            if (blank($vehicle->model)) {
                $missing[] = 'vehicle.model';
            }
            if (blank($vehicle->plate_number)) {
                $missing[] = 'vehicle.plate_number';
            }
            if (! $vehicle->service_transportation_id) {
                $missing[] = 'vehicle.transportation';
            }
        }

        return $missing;
    }

    private function resolveTransportationId(mixed $value): int
    {
        if ($value === null || $value === '') {
            throw ApiException::make('transportation_required', 'transportation is required (car or motorcycle).', 422);
        }

        $name = strtolower(trim((string) $value));
        $row = ServiceTransportation::query()
            ->where(function ($q) use ($name) {
                $q->where('code', $name)
                    ->orWhereRaw('LOWER(label) = ?', [$name]);
            })
            ->first();

        if (! $row) {
            throw ApiException::make(
                'transportation_invalid',
                'transportation must be a valid name/code from service_transportations (car|motorcycle).',
                422,
            );
        }

        return (int) $row->id;
    }

    private function normalizePhone(string $phone, bool $allowEmpty = false): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return $allowEmpty ? null : throw ApiException::make('phone_invalid', 'phone is invalid.', 422);
        }
        if (strlen($digits) < 8) {
            throw ApiException::make('phone_invalid', 'phone must contain at least 8 digits.', 422);
        }

        return $digits;
    }

    private function assertDriver(User $user): void
    {
        if ((int) $user->user_role_id !== UserRole::DRIVER) {
            throw ApiException::make('driver_only', 'This action is only available for drivers.', 403);
        }
    }
}
