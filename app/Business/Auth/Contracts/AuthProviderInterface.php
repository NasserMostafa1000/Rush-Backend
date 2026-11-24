<?php

namespace App\Business\Auth\Contracts;

use App\Business\Auth\DTO\AuthRequest;
use App\Data\Models\User;

interface AuthProviderInterface
{
    public function register(AuthRequest $request): User;

    public function login(AuthRequest $request): User;
}
