<?php

namespace Tests\Feature\Loyalty;

use App\Business\Services\PaymentService;
use App\Data\Models\Order;
use App\Data\Models\OrderStatus;
use App\Data\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoyaltyInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_reward_after_completed_orders(): void
    {
        $inviter = User::factory()->create([
            'invite_code' => 'HELLO123',
            'loyalty_points' => 0,
        ]);

        $invitee = User::factory()->create([
            'invited_by_user_id' => $inviter->id,
            'invite_reward_granted' => false,
        ]);

        $order = Order::query()->create([
            'id' => 'RSH-AAAAAA',
            'user_id' => $invitee->id,
            'service_id' => 1,
            'status_id' => OrderStatus::CONFIRMED,
            'total' => 10,
            'subtotal' => 10,
            'ordered_at' => now('UTC'),
        ]);

        /** @var PaymentService $payments */
        $payments = app(PaymentService::class);
        $payments->transitionOrder($order, OrderStatus::DELIVERED, 'Delivered');

        $this->assertDatabaseHas('users', [
            'id' => $inviter->id,
            'loyalty_points' => 50,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $invitee->id,
            'invite_reward_granted' => true,
        ]);
    }

    public function test_public_loyalty_info_endpoint(): void
    {
        $this->getJson('/api/v1/loyalty')
            ->assertOk()
            ->assertJsonPath('loyalty.invite_reward_points', 50)
            ->assertJsonStructure(['loyalty' => ['aed_per_point', 'min_completed_orders_for_invite']]);
    }

    public function test_admin_can_update_loyalty_settings(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'user_role_id' => \App\Data\Models\UserRole::ADMIN,
        ]));

        $this->putJson('/api/v1/admin/loyalty', [
            'aed_per_point' => 0.25,
            'invite_reward_points' => 80,
            'min_completed_orders_for_invite' => 2,
        ])->assertOk()
            ->assertJsonPath('loyalty.aed_per_point', 0.25)
            ->assertJsonPath('loyalty.invite_reward_points', 80);
    }
}
