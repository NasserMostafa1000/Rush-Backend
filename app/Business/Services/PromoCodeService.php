<?php

namespace App\Business\Services;

use App\Business\Exceptions\ApiException;
use App\Data\Models\PromoCode;
use App\Data\Models\PromoCodeRedemption;
use App\Data\Models\User;
use Illuminate\Support\Facades\DB;

class PromoCodeService
{
    public function create(array $data): array
    {
        $code = strtoupper(trim((string) $data['code']));
        $type = strtolower((string) $data['type']);
        if (! in_array($type, ['fixed', 'percent'], true)) {
            throw ApiException::make('promo_type_invalid', 'type must be fixed or percent.', 422);
        }

        if (PromoCode::query()->where('code', $code)->exists()) {
            throw ApiException::make('promo_exists', 'Promo code already exists.', 409);
        }

        $promo = PromoCode::query()->create([
            'code' => $code,
            'type' => $type,
            'value' => $data['value'],
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return ['promo_code' => $this->present($promo)];
    }

    public function list(): array
    {
        return [
            'promo_codes' => PromoCode::query()
                ->orderByDesc('id')
                ->get()
                ->map(fn (PromoCode $p) => $this->present($p))
                ->values()
                ->all(),
        ];
    }

    public function resolveForUser(?string $code, User $user, float $subtotal): array
    {
        if ($code === null || trim($code) === '') {
            return ['promo' => null, 'discount' => 0.0];
        }

        $promo = PromoCode::query()->where('code', strtoupper(trim($code)))->first();
        if (! $promo || ! $promo->isUsable()) {
            throw ApiException::make('promo_invalid', 'Promo code is invalid or expired.', 422);
        }

        if (PromoCodeRedemption::query()->where('promo_code_id', $promo->id)->where('user_id', $user->id)->exists()) {
            throw ApiException::make('promo_already_used', 'You already used this promo code.', 422);
        }

        return [
            'promo' => $promo,
            'discount' => $promo->calculateDiscount($subtotal),
        ];
    }

    public function redeem(PromoCode $promo, User $user, string $orderId, float $discount): void
    {
        DB::transaction(function () use ($promo, $user, $orderId, $discount) {
            PromoCodeRedemption::query()->create([
                'promo_code_id' => $promo->id,
                'user_id' => $user->id,
                'order_id' => $orderId,
                'discount_amount' => $discount,
            ]);

            $promo->increment('times_redeemed');
        });
    }

    private function present(PromoCode $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'type' => $p->type,
            'value' => (string) $p->value,
            'max_redemptions' => $p->max_redemptions,
            'times_redeemed' => (int) $p->times_redeemed,
            'is_active' => (bool) $p->is_active,
            'starts_at' => $p->starts_at?->utc()->toIso8601String(),
            'expires_at' => $p->expires_at?->utc()->toIso8601String(),
            'created_at' => $p->created_at?->utc()->toIso8601String(),
        ];
    }
}
