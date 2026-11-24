<?php

namespace App\Presentation\Http\Controllers\Api\Notification;

use App\Business\Services\NotificationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->notifications->listFor($request->user())
        );
    }

    public function markRead(Request $request, string $notification)
    {
        return response()->json(
            $this->notifications->markRead($request->user(), $notification)
        );
    }

    public function markAllRead(Request $request)
    {
        return response()->json(
            $this->notifications->markAllRead($request->user())
        );
    }
}
