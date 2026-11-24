<?php

namespace App\Business\Services;

use App\Data\Models\AppSetting;
use App\Data\Models\Order;
use App\Data\Models\OrderStatus;
use App\Data\Models\User;

class LoyaltyService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function info(): array
    {
        return [
            'aed_per_point' => AppSetting::floatValue('loyalty.aed_per_point', 0.10),
            'invite_reward_points' => AppSetting::intValue('loyalty.invite_reward_points', 50),
            'min_completed_orders_for_invite' => AppSetting::intValue('loyalty.min_completed_orders_for_invite', 1),
        ];
    }

    public function updateSettings(array $data): array
    {
        if (array_key_exists('aed_per_point', $data)) {
            AppSetting::setValue('loyalty.aed_per_point', (string) $data['aed_per_point']);
        }
        if (array_key_exists('invite_reward_points', $data)) {
            AppSetting::setValue('loyalty.invite_reward_points', (string) (int) $data['invite_reward_points']);
        }
        if (array_key_exists('min_completed_orders_for_invite', $data)) {
            AppSetting::setValue('loyalty.min_completed_orders_for_invite', (string) (int) $data['min_completed_orders_for_invite']);
        }

        return $this->info();
    }

    public function aedForPoints(int $points): float
    {
        if ($points <= 0) {
            return 0.0;
        }

        return round($points * AppSetting::floatValue('loyalty.aed_per_point', 0.10), 2);
    }

    public function pointsForAed(float $aed): int
    {
        $rate = AppSetting::floatValue('loyalty.aed_per_point', 0.10);
        if ($rate <= 0) {
            return 0;
        }

        return (int) floor($aed / $rate);
    }

    public function redeem(User $user, int $points, float $subtotalAfterPromo): array
    {
        $points = max(0, $points);
        if ($points === 0) {
            return ['points' => 0, 'discount' => 0.0];
        }

        if ($points > (int) $user->loyalty_points) {
            $points = (int) $user->loyalty_points;
        }

        $discount = min($this->aedForPoints($points), max(0.0, $subtotalAfterPromo));
        $points = $this->pointsForAed($discount);

        return ['points' => $points, 'discount' => $discount];
    }

    public function maybeGrantInviteReward(User $invitee): void
    {
        if (! $invitee->invited_by_user_id || $invitee->invite_reward_granted) {
            return;
        }

        $minOrders = AppSetting::intValue('loyalty.min_completed_orders_for_invite', 1);
        $completed = Order::query()
            ->where('user_id', $invitee->id)
            ->where('status_id', OrderStatus::DELIVERED)
            ->count();

        if ($completed < $minOrders) {
            return;
        }

        $inviter = User::query()->find($invitee->invited_by_user_id);
        if (! $inviter) {
            return;
        }

        $reward = AppSetting::intValue('loyalty.invite_reward_points', 50);
        $inviter->forceFill([
            'loyalty_points' => (int) $inviter->loyalty_points + $reward,
        ])->save();

        $invitee->forceFill(['invite_reward_granted' => true])->save();

        $this->notifications->push($inviter, [
            'title_en' => 'Loyalty points earned',
            'title_ar' => 'تم إضافة نقاط ولاء',
            'body_en' => "You earned {$reward} loyalty points from an invite.",
            'body_ar' => "حصلت على {$reward} نقطة ولاء من دعوة صديق.",
            'route' => '/loyalty',
            'data' => ['type' => 'invite_reward', 'points' => $reward],
        ]);
    }
}
