<?php

namespace Tests\Feature\Notification;

use App\Business\Services\NotificationService;
use App\Data\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_mark_one_and_mark_all_read(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        /** @var NotificationService $svc */
        $svc = app(NotificationService::class);
        $n1 = $svc->push($user, [
            'title_en' => 'Hello',
            'title_ar' => '?????',
            'body_en' => 'Body',
            'body_ar' => '??',
            'route' => '/orders/RSH-ABC123',
        ]);
        $svc->push($user, [
            'title_en' => 'Two',
            'title_ar' => '?????',
            'body_en' => 'Body 2',
            'body_ar' => '?? 2',
            'route' => '/loyalty',
        ]);

        $list = $this->getJson('/api/v1/notifications')->assertOk();
        $list->assertJsonPath('unread_count', 2)
            ->assertJsonCount(2, 'notifications');

        $this->postJson('/api/v1/notifications/'.$n1->public_id.'/read')
            ->assertOk()
            ->assertJsonPath('notification.is_read', true);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }
}
