<?php

namespace App\Presentation\Http\Controllers\Api\Order;

use App\Business\Services\OrderService;
use App\Business\Services\PaymentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json($this->orders->index($request->user()));
    }

    public function store(Request $request)
    {
        if (is_string($request->input('details'))) {
            $decoded = json_decode($request->input('details'), true);
            if (! is_array($decoded)) {
                return response()->json([
                    'message' => 'details must be a valid JSON array when sent as a string.',
                    'code' => 'details_invalid',
                ], 422);
            }
            $request->merge(['details' => $decoded]);
        }

        $data = $request->validate([
            'service' => ['required'],
            'total' => ['nullable', 'numeric', 'min:0'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tip_amount' => ['nullable', 'numeric', 'min:0'],
            'distance_km' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', 'string', 'in:card,cash'],
            'payer' => ['nullable', 'string', 'in:sender,receiver'],
            'pickup_timing' => ['nullable', 'string', 'in:asap,scheduled'],
            'scheduled_pickup_at' => ['nullable', 'date'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            'loyalty_points_to_redeem' => ['nullable', 'integer', 'min:0'],
            'estimated_pickup_minutes' => ['nullable', 'integer', 'min:0'],
            'estimated_delivery_minutes' => ['nullable', 'integer', 'min:0'],
            'invoice_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.transportation' => ['nullable'],
            'details.*.transportation_id' => ['nullable'],
            'details.*.delivery_preference' => ['nullable'],
            'details.*.delivery_preference_id' => ['nullable'],
            'details.*.delivery_speed' => ['nullable'],
            'details.*.delivery_speed_id' => ['nullable'],
            'details.*.minutes' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'details.*.pickup_latitude' => ['required', 'numeric'],
            'details.*.pickup_longitude' => ['required', 'numeric'],
            'details.*.dropoff_latitude' => ['required', 'numeric'],
            'details.*.dropoff_longitude' => ['required', 'numeric'],
            'details.*.pickup_address' => ['nullable', 'string', 'max:255'],
            'details.*.dropoff_address' => ['nullable', 'string', 'max:255'],
            'details.*.package_size' => ['nullable', 'string', 'max:40'],
            'details.*.pickup_notes' => ['nullable', 'string', 'max:200'],
            'details.*.dropoff_notes' => ['nullable', 'string', 'max:200'],
            'details.*.store_name' => ['nullable', 'string', 'max:160'],
            'details.*.shopping_request' => ['nullable', 'string'],
            'details.*.is_already_paid' => ['nullable', 'boolean'],
            'details.*.expected_order_value' => ['nullable', 'numeric', 'min:0'],
            'details.*.sender_name' => ['required', 'string', 'max:120'],
            'details.*.sender_phone' => ['required', 'string', 'max:32'],
            'details.*.receiver_name' => ['required', 'string', 'max:120'],
            'details.*.receiver_phone' => ['required', 'string', 'max:32'],
            'details.*.inside_the_package' => ['nullable', 'string', 'max:500'],
        ]);

        $payload = $this->orders->create(
            $request->user(),
            $data,
            $request->file('invoice_image'),
        );

        return response()->json($payload, 201);
    }

    public function show(Request $request, string $order)
    {
        return response()->json(
            $this->orders->show($request->user(), $order)
        );
    }

    public function paymentStatus(Request $request, string $order)
    {
        return response()->json(
            $this->payments->paymentStatus($request->user(), $order)
        );
    }

    public function cancel(Request $request, string $order)
    {
        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ]);

        return response()->json(
            $this->orders->cancel($request->user(), $order, $data['cancellation_reason'])
        );
    }

    public function rate(Request $request, string $order)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(
            $this->orders->rate($request->user(), $order, (int) $data['rating'], $data['comment'] ?? null)
        );
    }
}
