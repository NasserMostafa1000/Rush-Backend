<?php

namespace App\Presentation\Http\Controllers\Api\Loyalty;

use App\Business\Services\LoyaltyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyalty)
    {
    }

    public function info()
    {
        return response()->json(['loyalty' => $this->loyalty->info()]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'aed_per_point' => ['nullable', 'numeric', 'min:0'],
            'invite_reward_points' => ['nullable', 'integer', 'min:0'],
            'min_completed_orders_for_invite' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'loyalty' => $this->loyalty->updateSettings($data),
        ]);
    }
}
