<?php

namespace App\Business\Auth\Providers;

use App\Business\Auth\Contracts\AuthProviderInterface;
use App\Business\Auth\DTO\AuthRequest;
use App\Business\Exceptions\ApiException;
use App\Business\Support\InviteCode;
use App\Data\Models\OsProvider;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Support\Str;

class PhoneAuthProvider implements AuthProviderInterface
{
    public function register(AuthRequest $request): User
    {
        $phone = $this->normalizePhone($request->phone);
        if ($phone === null) {
            throw ApiException::make('phone_required', 'phone is required for phone registration.', 422);
        }

        $roleCode = $request->userType ?: 'personal';
        if (! in_array($roleCode, ['personal', 'store', 'driver'], true)) {
            throw ApiException::make('user_type_invalid', 'user_type must be personal, store, or driver.', 422);
        }

        $this->assertOtpIfRequired($phone, $request->token);

        if (User::query()->where('phone', $phone)->exists()) {
            throw ApiException::make('account_exists', 'An account already exists for this phone. Use login instead.', 409);
        }

        return User::query()->create([
            'public_id' => (string) Str::ulid(),
            'invite_code' => InviteCode::generate(),
            'name' => $request->name,
            'phone' => $phone,
            'user_role_id' => UserRole::idFromCode($roleCode),
            'preferred_language' => $request->preferredLanguage ?: 'en',
            'os_provider_id' => OsProvider::PHONE,
            'phone_verified_at' => now(),
            'license_image_path' => $request->licenseImagePath,
        ]);
    }

    public function login(AuthRequest $request): User
    {
        $phone = $this->normalizePhone($request->phone);
        if ($phone === null) {
            throw ApiException::make('phone_required', 'phone is required for phone login.', 422);
        }

        $this->assertOtpIfRequired($phone, $request->token);

        $user = User::query()->where('phone', $phone)->first();
        if (! $user) {
            throw ApiException::make('account_not_found', 'No account found for this phone. Register first.', 404);
        }

        return $user;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 8) {
            throw ApiException::make('phone_invalid', 'phone must contain at least 8 digits.', 422);
        }

        return $digits;
    }

    private function assertOtpIfRequired(string $phone, ?string $otp): void
    {
        if (! config('rush.auth.phone_otp_required')) {
            return;
        }

        if ($otp === null || $otp === '') {
            throw ApiException::make('otp_required', 'OTP (token) is required for phone auth.', 422);
        }

        $expected = config('rush.auth.phone_otp_demo');
        if ($expected && ! hash_equals((string) $expected, $otp)) {
            $fallback = str_repeat(substr($phone, -2), 2);
            if (! hash_equals($fallback, $otp)) {
                throw ApiException::make('otp_invalid', 'Invalid OTP.', 401);
            }
        }
    }
}
