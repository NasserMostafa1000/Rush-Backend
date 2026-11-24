<?php

namespace App\Presentation\Http\Controllers\Api\Admin;

use App\Business\Exceptions\ApiException;
use App\Business\Services\DriverService;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DriverVerificationController extends Controller
{
    public function __construct(private readonly DriverService $drivers)
    {
    }

    public function update(Request $request, User $user)
    {
        if ((int) $user->user_role_id !== UserRole::DRIVER) {
            throw ApiException::make('not_a_driver', 'User is not a driver.', 422);
        }

        $data = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected,under_review'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(
            $this->drivers->setVerificationStatus($user, $data['status'], $data['note'] ?? null)
        );
    }
}
