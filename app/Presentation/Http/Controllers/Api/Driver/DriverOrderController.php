<?php

namespace App\Presentation\Http\Controllers\Api\Driver;

use App\Business\Services\DriverOrderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DriverOrderController extends Controller
{
    public function __construct(private readonly DriverOrderService $driverOrders)
    {
    }

    public function setOnline(Request $request)
    {
        $data = $request->validate([
            'is_online' => ['required', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return response()->json(
            $this->driverOrders->setOnline(
                $request->user(),
                (bool) $data['is_online'],
                isset($data['latitude']) ? (float) $data['latitude'] : null,
                isset($data['longitude']) ? (float) $data['longitude'] : null,
            )
        );
    }

    public function available(Request $request)
    {
        return response()->json(
            $this->driverOrders->available($request->user())
        );
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'scope' => ['nullable', 'string', 'in:active,history,all'],
        ]);

        return response()->json(
            $this->driverOrders->mine($request->user(), $data['scope'] ?? null)
        );
    }

    public function show(Request $request, string $order)
    {
        return response()->json(
            $this->driverOrders->show($request->user(), $order)
        );
    }

    public function accept(Request $request, string $order)
    {
        return response()->json(
            $this->driverOrders->accept($request->user(), $order)
        );
    }

    public function reject(Request $request, string $order)
    {
        return response()->json(
            $this->driverOrders->reject($request->user(), $order)
        );
    }

    public function pickedUp(Request $request, string $order)
    {
        return response()->json(
            $this->driverOrders->markPickedUp($request->user(), $order)
        );
    }

    public function delivered(Request $request, string $order)
    {
        $request->validate([
            'proof_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        return response()->json(
            $this->driverOrders->markDelivered(
                $request->user(),
                $order,
                $request->file('proof_image'),
            )
        );
    }

    public function earnings(Request $request)
    {
        return response()->json(
            $this->driverOrders->earnings($request->user())
        );
    }
}
