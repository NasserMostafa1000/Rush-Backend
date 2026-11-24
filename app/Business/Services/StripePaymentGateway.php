<?php

namespace App\Business\Services;

use App\Business\Exceptions\ApiException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripePaymentGateway
{
    public function createPaymentIntent(int $amountMinor, string $currency, array $metadata = []): array
    {
        if (config('services.stripe.fake')) {
            $id = 'pi_fake_'.bin2hex(random_bytes(8));

            return [
                'id' => $id,
                'client_secret' => $id.'_secret_fake',
                'status' => 'requires_payment_method',
            ];
        }

        $secret = config('services.stripe.secret');
        if (! $secret) {
            throw ApiException::make('stripe_not_configured', 'Stripe is not configured.', 500);
        }

        $stripe = new StripeClient($secret);
        $intent = $stripe->paymentIntents->create([
            'amount' => $amountMinor,
            'currency' => strtolower($currency),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => $metadata,
        ]);

        return [
            'id' => $intent->id,
            'client_secret' => $intent->client_secret,
            'status' => $intent->status,
        ];
    }

    /**
     * Refund a captured PaymentIntent back to the original payment method.
     *
     * @return array{id: string, status: string}
     */
    public function createRefund(string $paymentIntentId, int $amountMinor, ?string $reason = null): array
    {
        if (config('services.stripe.fake')) {
            return [
                'id' => 're_fake_'.bin2hex(random_bytes(8)),
                'status' => 'succeeded',
            ];
        }

        $secret = config('services.stripe.secret');
        if (! $secret) {
            throw ApiException::make('stripe_not_configured', 'Stripe is not configured.', 500);
        }

        $stripe = new StripeClient($secret);
        $params = ['payment_intent' => $paymentIntentId];
        if ($amountMinor > 0) {
            $params['amount'] = $amountMinor;
        }
        // Stripe only accepts: duplicate | fraudulent | requested_by_customer
        if ($reason) {
            $params['reason'] = 'requested_by_customer';
        }

        $refund = $stripe->refunds->create($params);

        return [
            'id' => $refund->id,
            'status' => $refund->status ?? 'succeeded',
        ];
    }

    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        if (config('services.stripe.fake')) {
            return;
        }

        $secret = config('services.stripe.secret');
        if (! $secret) {
            return;
        }

        try {
            (new StripeClient($secret))->paymentIntents->cancel($paymentIntentId, []);
        } catch (\Throwable) {
            // Already captured/cancelled — refund path handles captured payments.
        }
    }

    public function constructEvent(string $payload, ?string $signatureHeader): object
    {
        if (config('services.stripe.fake')) {
            $data = json_decode($payload, false);
            if (! is_object($data)) {
                throw ApiException::make('webhook_invalid', 'Invalid Stripe webhook payload.', 400);
            }

            return $data;
        }

        $secret = config('services.stripe.webhook_secret');
        if (! $secret) {
            throw ApiException::make('stripe_not_configured', 'Stripe webhook secret is not configured.', 500);
        }

        try {
            return Webhook::constructEvent($payload, (string) $signatureHeader, $secret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            throw ApiException::make('webhook_invalid', 'Invalid Stripe webhook signature.', 400);
        }
    }
}
