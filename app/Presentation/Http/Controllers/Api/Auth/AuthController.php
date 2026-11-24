<?php

namespace App\Presentation\Http\Controllers\Api\Auth;

use App\Business\Services\AuthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'login_provider_name' => ['required', 'string', 'in:google,apple,phone'],
            'token' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:32'],
            'user_type' => ['nullable', 'string', 'in:personal,store,driver'],
            'preferred_language' => ['nullable', 'string', 'in:en,ar'],
            'name' => ['nullable', 'string', 'max:120'],
            'invite_code' => ['nullable', 'string', 'max:16'],
            'license_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $provider = $data['login_provider_name'];
        $userType = $data['user_type'] ?? 'personal';

        if ($provider === 'phone' && empty($data['phone'])) {
            return response()->json([
                'message' => 'phone is required for phone registration.',
                'code' => 'phone_required',
            ], 422);
        }

        if (in_array($provider, ['google', 'apple'], true) && empty($data['token'])) {
            return response()->json([
                'message' => 'token is required for '.$provider.' registration.',
                'code' => 'token_required',
            ], 422);
        }

        $payload = $this->auth->register(
            $provider,
            $data['token'] ?? null,
            $data['phone'] ?? null,
            $userType,
            $data['preferred_language'] ?? null,
            $data['name'] ?? null,
            $data['invite_code'] ?? null,
            $request->file('license_image'),
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
        );

        return response()->json($payload, 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'login_provider_name' => ['required', 'string', 'in:google,apple,phone'],
            'token' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:32'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(
            $this->auth->login(
                $data['login_provider_name'],
                $data['token'] ?? null,
                $data['phone'] ?? null,
                isset($data['latitude']) ? (float) $data['latitude'] : null,
                isset($data['longitude']) ? (float) $data['longitude'] : null,
            )
        );
    }

    public function updateLocation(Request $request)
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(
            $this->auth->updateLocation(
                $request->user(),
                (float) $data['latitude'],
                (float) $data['longitude'],
            )
        );
    }

    public function me(Request $request)
    {
        return response()->json($this->auth->me($request->user()));
    }

    public function logout(Request $request)
    {
        $this->auth->logout($request->user());

        return response()->json(['ok' => true]);
    }

    public function updateLanguage(Request $request)
    {
        $data = $request->validate([
            'preferred_language' => ['required', 'string', 'in:en,ar'],
        ]);

        return response()->json(
            $this->auth->updatePreferredLanguage($request->user(), $data['preferred_language'])
        );
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'preferred_language' => ['nullable', 'string', 'in:en,ar'],
            'push_notifications_enabled' => ['nullable', 'boolean'],
            'profile_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        return response()->json(
            $this->auth->updateProfile($request->user(), $data, $request->file('profile_image'))
        );
    }
}
