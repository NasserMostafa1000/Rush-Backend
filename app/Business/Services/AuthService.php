<?php

namespace App\Business\Services;

use App\Business\Auth\DTO\AuthRequest;
use App\Business\Auth\Factories\AuthProviderFactory;
use App\Business\Exceptions\ApiException;
use App\Business\Support\InviteCode;
use App\Data\Models\Order;
use App\Data\Models\OrderStatus;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Http\UploadedFile;

class AuthService
{
    public function __construct(
        private readonly AuthProviderFactory $factory,
        private readonly CloudflareR2Service $r2,
        private readonly LoyaltyService $loyalty,
        private readonly DriverService $drivers,
        private readonly LocationService $locations,
    ) {
    }

    /**
     * @return array{token: string, user: array}
     */
    public function register(
        string $loginProviderName,
        ?string $token,
        ?string $phone,
        string $userType,
        ?string $preferredLanguage = null,
        ?string $name = null,
        ?string $inviteCode = null,
        ?UploadedFile $licenseImage = null,
        ?float $latitude = null,
        ?float $longitude = null,
    ): array {
        $licensePath = $licenseImage
            ? $this->r2->upload($licenseImage, 'licenses')
            : null;

        $request = new AuthRequest(
            loginProviderName: $loginProviderName,
            token: $token,
            phone: $phone,
            userType: $userType,
            preferredLanguage: $preferredLanguage,
            name: $name,
            inviteCode: $inviteCode,
            licenseImagePath: $licensePath,
        );

        $user = $this->factory->make($loginProviderName)->register($request);

        // New store/company accounts must provide a license. Existing accounts re-registering just get a token.
        if ($user->wasRecentlyCreated && $userType === 'store' && blank($licensePath)) {
            $user->delete();
            throw ApiException::make('license_required', 'license_image is required for store/company registration.', 422);
        }

        // Persist R2 object key on the user row for store (and any upload).
        if ($licensePath) {
            $user->forceFill(['license_image_path' => $licensePath])->save();
        }

        $this->finalizeNewUser($user, $inviteCode, $licensePath);

        if ($user->isDriver()) {
            $this->drivers->ensureProfile($user);
        }

        $this->maybeStoreLocation($user, $latitude, $longitude);

        return $this->issueToken($user->fresh());
    }

    /**
     * @return array{token: string, user: array}
     */
    public function login(
        string $loginProviderName,
        ?string $token,
        ?string $phone,
        ?float $latitude = null,
        ?float $longitude = null,
    ): array {
        $request = new AuthRequest(
            loginProviderName: $loginProviderName,
            token: $token,
            phone: $phone,
        );

        $user = $this->factory->make($loginProviderName)->login($request);
        $user->forceFill(['last_login_at' => now()])->save();
        if (! $user->invite_code) {
            $user->forceFill(['invite_code' => InviteCode::generate()])->save();
        }
        if ($user->isDriver()) {
            $this->drivers->ensureProfile($user);
        }

        $this->maybeStoreLocation($user, $latitude, $longitude);

        return $this->issueToken($user->fresh());
    }

    /**
     * Persist GPS + H3 for drivers (and any authenticated user that sends coords).
     *
     * @return array{location: array}
     */
    public function updateLocation(User $user, float $latitude, float $longitude): array
    {
        return ['location' => $this->locations->update($user, $latitude, $longitude)];
    }

    private function maybeStoreLocation(User $user, ?float $latitude, ?float $longitude): void
    {
        if ($latitude === null || $longitude === null) {
            return;
        }

        // Drivers always store location on login/open; others may send it optionally.
        $this->locations->update($user->fresh(), $latitude, $longitude);
    }

    public function me(User $user): array
    {
        return ['user' => $this->present($user)];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function updatePreferredLanguage(User $user, string $language): array
    {
        $language = strtolower(trim($language));
        if (! in_array($language, ['en', 'ar'], true)) {
            $language = 'en';
        }

        $user->forceFill(['preferred_language' => $language])->save();

        return ['user' => $this->present($user->fresh())];
    }

    /**
     * Edit Profile (Figma: Change photo, Full name, Email address, Mobile number, language).
     *
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, preferred_language?: ?string, push_notifications_enabled?: bool}  $data
     * @return array{user: array}
     */
    public function updateProfile(User $user, array $data, ?UploadedFile $profileImage = null): array
    {
        $updates = [];

        if (array_key_exists('name', $data) && $data['name'] !== null) {
            $updates['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('email', $data) && $data['email'] !== null && $data['email'] !== '') {
            $email = strtolower(trim((string) $data['email']));
            if (User::query()->where('email', $email)->where('id', '!=', $user->id)->exists()) {
                throw ApiException::make('email_taken', 'This email is already used by another account.', 409);
            }
            $updates['email'] = $email;
        }

        if (array_key_exists('phone', $data) && $data['phone'] !== null && $data['phone'] !== '') {
            $digits = preg_replace('/\D+/', '', (string) $data['phone']) ?? '';
            if (strlen($digits) < 8) {
                throw ApiException::make('phone_invalid', 'phone must contain at least 8 digits.', 422);
            }
            if (User::query()->where('phone', $digits)->where('id', '!=', $user->id)->exists()) {
                throw ApiException::make('phone_taken', 'This phone is already used by another account.', 409);
            }
            $updates['phone'] = $digits;
        }

        if (! empty($data['preferred_language'])) {
            $language = strtolower(trim((string) $data['preferred_language']));
            $updates['preferred_language'] = in_array($language, ['en', 'ar'], true) ? $language : 'en';
        }

        if (array_key_exists('push_notifications_enabled', $data) && $data['push_notifications_enabled'] !== null) {
            $updates['push_notifications_enabled'] = (bool) $data['push_notifications_enabled'];
        }

        if ($profileImage) {
            $updates['profile_image_path'] = $this->r2->upload($profileImage, 'profiles');
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }

        return ['user' => $this->present($user->fresh())];
    }

    private function finalizeNewUser(User $user, ?string $inviteCode, ?string $licensePath): void
    {
        $updates = [];

        if (! $user->invite_code) {
            $updates['invite_code'] = InviteCode::generate();
        }

        if ($licensePath && ! $user->license_image_path) {
            $updates['license_image_path'] = $licensePath;
        }

        if ($inviteCode && ! $user->invited_by_user_id) {
            $inviter = User::query()
                ->where('invite_code', strtoupper(trim($inviteCode)))
                ->where('id', '!=', $user->id)
                ->first();
            if ($inviter) {
                $updates['invited_by_user_id'] = $inviter->id;
            }
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
    }

    /** @return array{token: string, user: array} */
    private function issueToken(User $user): array
    {
        $plain = $user->createToken('rush-api', ['*'])->plainTextToken;

        return [
            'token' => $plain,
            'user' => $this->present($user),
        ];
    }

    private function present(User $user): array
    {
        $user->loadMissing(['role', 'osProvider']);

        $ordersCount = Order::query()->where('user_id', $user->id)->count();
        $completedOrders = Order::query()
            ->where('user_id', $user->id)
            ->where('status_id', OrderStatus::DELIVERED)
            ->count();

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_role_id' => (int) $user->user_role_id,
            'user_type' => $user->userTypeCode(),
            'preferred_language' => $user->preferred_language ?: 'en',
            'os_provider_id' => (int) $user->os_provider_id,
            'auth_provider' => $user->authProviderCode(),
            'google_linked' => filled($user->google_id),
            'apple_linked' => filled($user->apple_id),
            'invite_code' => $user->invite_code,
            'loyalty_points' => (int) $user->loyalty_points,
            'loyalty' => array_merge($this->loyalty->info(), [
                'balance' => (int) $user->loyalty_points,
                'balance_aed_value' => (string) $this->loyalty->aedForPoints((int) $user->loyalty_points),
            ]),
            'orders_count' => $ordersCount,
            'completed_orders_count' => $completedOrders,
            'license_image_path' => $user->license_image_path,
            'license_image_url' => $this->r2->url($user->license_image_path),
            'profile_image_path' => $user->profile_image_path,
            'profile_image_url' => $this->r2->url($user->profile_image_path),
            'push_notifications_enabled' => (bool) $user->push_notifications_enabled,
            'location' => $this->locations->present($user),
            'driver' => $user->isDriver() ? $this->drivers->present($user) : null,
            'created_at' => optional($user->created_at)?->toIso8601String(),
            'last_login_at' => optional($user->last_login_at)?->toIso8601String(),
        ];
    }
}
