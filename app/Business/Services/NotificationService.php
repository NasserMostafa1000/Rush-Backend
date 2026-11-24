<?php

namespace App\Business\Services;

use App\Data\Models\AppNotification;
use App\Data\Models\DriverProfile;
use App\Data\Models\DriverVehicle;
use App\Data\Models\Order;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(private readonly H3Service $h3)
    {
    }

    /**
     * @param  array{
     *   title_en: string,
     *   title_ar: string,
     *   body_en: string,
     *   body_ar: string,
     *   route?: string|null,
     *   data?: array|null
     * }  $payload
     */
    public function push(User $user, array $payload): AppNotification
    {
        return AppNotification::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'title_en' => $payload['title_en'],
            'title_ar' => $payload['title_ar'],
            'body_en' => $payload['body_en'],
            'body_ar' => $payload['body_ar'],
            'route' => $payload['route'] ?? null,
            'data' => $payload['data'] ?? null,
        ]);
    }

    /**
     * Notify nearby approved+online drivers whose vehicle matches the order
     * (H3 disk around every pickup in the order — supports multi-store details).
     */
    public function notifyApprovedDriversNewOrder(Order $order): int
    {
        $order->loadMissing(['details.transportation', 'service']);
        if ($order->details->isEmpty()) {
            return 0;
        }

        $transportationIds = $order->details
            ->pluck('transportation_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($transportationIds === []) {
            return 0;
        }

        // Union of H3 cells around every pickup (multi-store / multi-leg).
        $cells = [];
        $anchorLat = null;
        $anchorLng = null;
        foreach ($order->details as $detail) {
            $lat = (float) $detail->pickup_latitude;
            $lng = (float) $detail->pickup_longitude;
            if ($anchorLat === null) {
                $anchorLat = $lat;
                $anchorLng = $lng;
            }
            foreach ($this->h3->disk($lat, $lng) as $cell) {
                $cells[$cell] = true;
            }
        }
        $cellList = array_keys($cells);
        if ($cellList === [] || $anchorLat === null) {
            return 0;
        }

        $serviceCode = $order->service?->service_name;

        $drivers = User::query()
            ->where('user_role_id', UserRole::DRIVER)
            ->where('push_notifications_enabled', true)
            ->whereIn('h3_index', $cellList)
            ->whereNotNull('h3_index')
            ->whereHas('driverProfile', function ($q) {
                $q->where('verification_status', DriverProfile::STATUS_APPROVED)
                    ->where('is_online', true);
            })
            ->whereHas('driverVehicles', function ($q) use ($transportationIds) {
                $q->where('is_current', true)
                    ->whereIn('service_transportation_id', $transportationIds);
            })
            ->get();

        $drivers = $drivers->sortBy(function (User $driver) use ($anchorLat, $anchorLng) {
            return $this->h3->distanceMeters(
                $anchorLat,
                $anchorLng,
                (float) $driver->latitude,
                (float) $driver->longitude,
            );
        })->values();

        $count = 0;
        foreach ($drivers as $driver) {
            $meters = $this->h3->distanceMeters(
                $anchorLat,
                $anchorLng,
                (float) $driver->latitude,
                (float) $driver->longitude,
            );

            $vehicle = DriverVehicle::query()
                ->where('user_id', $driver->id)
                ->where('is_current', true)
                ->first();

            $this->push($driver, [
                'title_en' => 'New delivery request nearby',
                'title_ar' => 'طلب توصيل قريب منك',
                'body_en' => "New order {$order->id} is about ".round($meters).'m away.',
                'body_ar' => "طلب جديد {$order->id} على بعد حوالي ".round($meters).' متر.',
                'route' => '/driver/orders/'.$order->id,
                'data' => [
                    'type' => 'new_order_offer',
                    'order_id' => $order->id,
                    'status' => 'awaiting_driver',
                    'service' => $serviceCode,
                    'details_count' => $order->details->count(),
                    'transportation_ids' => $transportationIds,
                    'transportation' => $vehicle?->transportation?->code,
                    'distance_meters' => (int) round($meters),
                    'h3_index' => $driver->h3_index,
                ],
            ]);
            $count++;
        }

        return $count;
    }

    public function listFor(User $user, int $limit = 50): array
    {
        $items = AppNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'notifications' => $items->map(fn (AppNotification $n) => $this->present($n, $user))->values()->all(),
            'unread_count' => AppNotification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
        ];
    }

    public function markRead(User $user, string $publicId): array
    {
        $notification = AppNotification::query()
            ->where('user_id', $user->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now('UTC')])->save();
        }

        return ['notification' => $this->present($notification->fresh(), $user)];
    }

    public function markAllRead(User $user): array
    {
        $updated = AppNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now('UTC')]);

        return ['updated' => $updated];
    }

    private function present(AppNotification $n, User $user): array
    {
        $lang = $user->preferred_language === 'ar' ? 'ar' : 'en';

        return [
            'id' => $n->public_id,
            'title' => $lang === 'ar' ? $n->title_ar : $n->title_en,
            'body' => $lang === 'ar' ? $n->body_ar : $n->body_en,
            'title_en' => $n->title_en,
            'title_ar' => $n->title_ar,
            'body_en' => $n->body_en,
            'body_ar' => $n->body_ar,
            'route' => $n->route,
            'data' => $n->data,
            'is_read' => $n->isRead(),
            'read_at' => $n->read_at?->utc()->toIso8601String(),
            'created_at' => $n->created_at?->utc()->toIso8601String(),
        ];
    }
}
