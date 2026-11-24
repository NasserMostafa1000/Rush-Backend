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

abstract class AbstractSocialAuthProvider implements AuthProviderInterface
{
    abstract protected function providerKey(): string;

    abstract protected function identityColumn(): string;

    /**
     * @return array{provider_user_id: string, email: ?string, name: ?string}
     */
    abstract protected function verifyToken(string $token): array;

    public function register(AuthRequest $request): User
    {
        if ($request->token === null || $request->token === '') {
            throw ApiException::make(
                'token_required',
                'A provider token is required for '.$this->providerKey().' registration.',
                422,
            );
        }

        $roleCode = $request->userType ?: 'personal';
        if (! in_array($roleCode, ['personal', 'store', 'driver'], true)) {
            throw ApiException::make('user_type_invalid', 'user_type must be personal, store, or driver.', 422);
        }

        $identity = $this->verifyToken($request->token);
        $column = $this->identityColumn();

        $existing = User::query()->where($column, $identity['provider_user_id'])->first();
        if ($existing) {
            return $existing;
        }

        return $this->createUser($identity, $column, $roleCode, $request);
    }

    public function login(AuthRequest $request): User
    {
        if ($request->token === null || $request->token === '') {
            throw ApiException::make(
                'token_required',
                'A provider token is required for '.$this->providerKey().' login.',
                422,
            );
        }

        $identity = $this->verifyToken($request->token);
        $column = $this->identityColumn();

        $user = User::query()->where($column, $identity['provider_user_id'])->first();
        if ($user) {
            return $user;
        }

        return $this->createUser(
            $identity,
            $column,
            'personal',
            new AuthRequest(
                loginProviderName: $this->providerKey(),
                token: $request->token,
                phone: $request->phone,
                userType: 'personal',
                preferredLanguage: $request->preferredLanguage,
                name: $request->name,
            ),
        );
    }

    /**
     * @param  array{provider_user_id: string, email: ?string, name: ?string}  $identity
     */
    private function createUser(array $identity, string $column, string $roleCode, AuthRequest $request): User
    {
        if (! empty($identity['email'])) {
            $emailOwner = User::query()->where('email', $identity['email'])->first();
            if ($emailOwner && blank($emailOwner->{$column})) {
                $emailOwner->forceFill([
                    $column => $identity['provider_user_id'],
                    'os_provider_id' => $emailOwner->os_provider_id ?: OsProvider::idFromCode($this->providerKey()),
                    'name' => $emailOwner->name ?: ($request->name ?: ($identity['name'] ?? null)),
                    'license_image_path' => $emailOwner->license_image_path ?: $request->licenseImagePath,
                ])->save();

                return $emailOwner->fresh();
            }

            if ($emailOwner && $emailOwner->{$column} !== $identity['provider_user_id']) {
                throw ApiException::make('email_taken', 'This email is already linked to another account.', 409);
            }
        }

        return User::query()->create([
            'public_id' => (string) Str::ulid(),
            'invite_code' => InviteCode::generate(),
            'name' => $request->name ?: ($identity['name'] ?? null),
            'email' => $identity['email'] ?: null,
            'phone' => $request->phone,
            $column => $identity['provider_user_id'],
            'user_role_id' => UserRole::idFromCode($roleCode),
            'preferred_language' => $request->preferredLanguage ?: 'en',
            'os_provider_id' => OsProvider::idFromCode($this->providerKey()),
            'license_image_path' => $request->licenseImagePath,
        ]);
    }

    /**
     * @return array{provider_user_id: string, email: ?string, name: ?string}|null
     */
    protected function parseTestToken(string $token): ?array
    {
        if (! str_starts_with($token, 'test:')) {
            return null;
        }

        $parts = explode(':', $token, 4);
        $id = $parts[1] ?? '';
        if ($id === '') {
            throw ApiException::make('token_invalid', 'Invalid test token.', 401);
        }

        return [
            'provider_user_id' => $id,
            'email' => ($parts[2] ?? '') !== '' ? $parts[2] : null,
            'name' => ($parts[3] ?? '') !== '' ? $parts[3] : null,
        ];
    }
}
