<?php

namespace App\Http\Middleware;

use App\Business\Exceptions\ApiError;
use App\Data\Models\UserRole;
use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || (int) $user->user_role_id !== UserRole::ADMIN) {
            return ApiError::response('Admin access required.', 'forbidden', 403);
        }

        return $next($request);
    }
}
