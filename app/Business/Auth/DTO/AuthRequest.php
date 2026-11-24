<?php

namespace App\Business\Auth\DTO;

final class AuthRequest
{
    public function __construct(
        public readonly string $loginProviderName,
        public readonly ?string $token = null,
        public readonly ?string $phone = null,
        public readonly ?string $userType = null,
        public readonly ?string $preferredLanguage = null,
        public readonly ?string $name = null,
        public readonly ?string $inviteCode = null,
        public readonly ?string $licenseImagePath = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            loginProviderName: strtolower(trim((string) ($data['login_provider_name'] ?? ''))),
            token: isset($data['token']) ? (string) $data['token'] : null,
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            userType: isset($data['user_type']) ? strtolower((string) $data['user_type']) : null,
            preferredLanguage: isset($data['preferred_language'])
                ? strtolower((string) $data['preferred_language'])
                : null,
            name: isset($data['name']) ? (string) $data['name'] : null,
            inviteCode: isset($data['invite_code']) ? strtoupper(trim((string) $data['invite_code'])) : null,
            licenseImagePath: isset($data['license_image_path']) ? (string) $data['license_image_path'] : null,
        );
    }
}
