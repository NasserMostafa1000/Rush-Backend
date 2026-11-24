<?php

namespace App\Presentation\Http\Controllers\Api\Payment;

use App\Business\Services\PaymentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function __invoke(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        return response()->json(
            $this->payments->handleStripeWebhook($payload, $signature)
        );
    }
}
