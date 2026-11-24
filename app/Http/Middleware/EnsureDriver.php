<?php

namespace App\Http\Middleware;

use App\Business\Exceptions\ApiException;
use App\Data\Models\UserRole;
use Closure;
use Illuminate\Http\Request;

class EnsureDriver
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || (int) $user->user_role_id !== UserRole::DRIVER) {
            throw ApiException::make('driver_only', 'This endpoint is only available for drivers.', 403);
        }

        return $next($request);
    }
}
