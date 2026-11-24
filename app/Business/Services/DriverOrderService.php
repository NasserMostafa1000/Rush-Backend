<?php

namespace App\Business\Services;

use App\Business\Exceptions\ApiException;
use App\Data\Models\DriverProfile;
use App\Data\Models\DriverVehicle;
use App\Data\Models\Order;
use App\Data\Models\OrderDriverDecline;
use App\Data\Models\OrderStatus;
use App\Data\Models\User;
use App\Data\Models\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DriverOrderService
{
    public function __construct(
        private readonly DriverService $drivers,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly NotificationService $notifications,
        private readonly CloudflareR2Service $r2,
        private readonly LocationService $locations,
        private readonly H3Service $h3,
    ) {
    }

    /**
     * @return array{driver: array}
     */
    public function setOnline(User $driver, bool $online, ?float $lat = null, ?float $lng = null): array
    {
        $profile = $this->drivers->ensureProfile($driver);

        if ($online && ! $profile->canGoOnline()) {
            throw ApiException::make(
                'driver_not_verified',
                'Driver must be approved before going online.',
                422,
                ['verification_status' => $profile->verification_status],
            );
        }

        if ($online) {
            $vehicle = DriverVehicle::query()
                ->where('user_id', $driver->id)
                ->where('is_current', true)
                ->first();
            if (! $vehicle) {
                throw ApiException::make('vehicle_required', 'Add a vehicle before going online.', 422);
            }
        }

        $updates = [
            'is_online' => $online,
            'last_online_at' => now(),
        ];
        if ($lat !== null) {
            $updates['last_latitude'] = $lat;
        }
        if ($lng !== null) {
            $updates['last_longitude'] = $lng;
        }

        $profile->forceFill($updates)->save();

        // Keep users.h3_index in sync so nearby-order fanout can query by hex.
        if ($lat !== null && $lng !== null) {
            $this->locations->update($driver, $lat, $lng);
        }

        return ['driver' => $this->drivers->present($driver->fresh())];
    }

    /**
     * Nearby/available offers for an online approved driver (H3 disk around driver).
     *
     * @return array{orders: list<array>}
     */
    public function available(User $driver): array
    {
        $profile = $this->assertOnlineApproved($driver);
        $transportationId = $this->currentTransportationId($driver);

        $query = Order::query()
            ->with([
                'service', 'status', 'details.transportation',
                'details.deliveryPreference', 'details.deliverySpeed',
            ])
            ->where('status_id', OrderStatus::AWAITING_DRIVER)
            ->whereNull('driver_id')
            ->orderByDesc('ordered_at')
            ->limit(50);

        if ($transportationId) {
            $query->whereHas('details', fn ($q) => $q->where('transportation_id', $transportationId));
        }

        $declinedIds = OrderDriverDecline::query()
            ->where('user_id', $driver->id)
            ->pluck('order_id')
            ->all();
        if ($declinedIds !== []) {
            $query->whereNotIn('id', $declinedIds);
        }

        $orders = $query->get();

        // Nearby if ANY pickup (multi-store) is inside the driver's H3 neighborhood.
        $driverLat = $driver->latitude !== null ? (float) $driver->latitude : null;
        $driverLng = $driver->longitude !== null ? (float) $driver->longitude : null;
        if ($driverLat !== null && $driverLng !== null) {
            $cells = array_flip($this->h3->disk($driverLat, $driverLng));
            $orders = $orders->filter(function (Order $order) use ($cells) {
                foreach ($order->details as $detail) {
                    $cell = $this->h3->index((float) $detail->pickup_latitude, (float) $detail->pickup_longitude);
                    if (isset($cells[$cell])) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return [
            'orders' => $orders->map(fn (Order $o) => $this->orders->present($o))->values()->all(),
            'is_online' => (bool) $profile->is_online,
        ];
    }

    /**
     * @return array{orders: list<array>}
     */
    public function mine(User $driver, ?string $scope = null): array
    {
        $this->assertDriver($driver);

        $query = Order::query()
            ->with([
                'service', 'status', 'details.transportation',
                'details.deliveryPreference', 'details.deliverySpeed', 'payments',
            ])
            ->where('driver_id', $driver->id)
            ->orderByDesc('ordered_at')
            ->limit(100);

        $scope = $scope ? strtolower($scope) : null;
        if ($scope === 'active') {
            $query->whereIn('status_id', [OrderStatus::DRIVER_ASSIGNED, OrderStatus::PICKED_UP]);
        } elseif ($scope === 'history') {
            $query->whereIn('status_id', [OrderStatus::DELIVERED, OrderStatus::CANCELLED]);
        }

        return [
            'orders' => $query->get()->map(fn (Order $o) => $this->orders->present($o))->values()->all(),
        ];
    }

    /**
     * @return array{order: array}
     */
    public function show(User $driver, string $orderId): array
    {
        $this->assertDriver($driver);
        $order = $this->findVisibleToDriver($driver, $orderId);

        return ['order' => $this->orders->present($order)];
    }

    /**
     * Race-safe accept: row lock + status/driver_id re-check so two drivers
     * cannot take the same order (including multi-detail / multi-store orders).
     *
     * @return array{order: array}
     */
    public function accept(User $driver, string $orderId): array
    {
        $this->assertOnlineApproved($driver);

        $order = DB::transaction(function () use ($driver, $orderId) {
            $order = Order::query()
                ->where('id', strtoupper($orderId))
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw ApiException::make('not_found', 'Order not found.', 404);
            }

            if ((int) $order->status_id !== OrderStatus::AWAITING_DRIVER || $order->driver_id !== null) {
                throw ApiException::make('order_unavailable', 'Order is no longer available.', 409, [
                    'current_status' => OrderStatus::codeFromId((int) $order->status_id),
                ]);
            }

            $transportationId = $this->currentTransportationId($driver);
            if (! $transportationId) {
                throw ApiException::make('vehicle_required', 'Add a vehicle before accepting orders.', 422);
            }

            $matches = $order->details()->where('transportation_id', $transportationId)->exists();
            if (! $matches) {
                throw ApiException::make(
                    'transportation_mismatch',
                    'Your vehicle type does not match this order.',
                    422,
                );
            }

            // Atomic claim — second concurrent transaction still sees old row under lock.
            $claimed = Order::query()
                ->where('id', $order->id)
                ->where('status_id', OrderStatus::AWAITING_DRIVER)
                ->whereNull('driver_id')
                ->update(['driver_id' => $driver->id]);

            if ($claimed !== 1) {
                throw ApiException::make('order_unavailable', 'Order is no longer available.', 409);
            }

            $order->refresh();
            $this->payments->transitionOrder($order, OrderStatus::DRIVER_ASSIGNED, 'Driver accepted the order');

            return $order->fresh([
                'service', 'status', 'promoCode', 'driver',
                'details.transportation', 'details.deliveryPreference', 'details.deliverySpeed',
                'statusEvents.status', 'payments',
            ]);
        });

        $customer = User::query()->find($order->user_id);
        if ($customer) {
            $this->notifications->push($customer, [
                'title_en' => 'Driver assigned',
                'title_ar' => 'تم تعيين سائق',
                'body_en' => "A driver accepted order {$order->id}.",
                'body_ar' => "قبل سائق الطلب {$order->id}.",
                'route' => '/orders/'.$order->id,
                'data' => [
                    'type' => 'driver_assigned',
                    'order_id' => $order->id,
                    'driver_id' => $driver->public_id,
                ],
            ]);
        }

        return ['order' => $this->orders->present($order)];
    }

    /**
     * Decline an awaiting offer (order stays available for other matching drivers).
     *
     * @return array{ok: true, order_id: string}
     */
    public function reject(User $driver, string $orderId): array
    {
        $this->assertOnlineApproved($driver);

        $order = Order::query()
            ->where('id', strtoupper($orderId))
            ->where('status_id', OrderStatus::AWAITING_DRIVER)
            ->whereNull('driver_id')
            ->first();

        if (! $order) {
            throw ApiException::make('order_unavailable', 'Order is no longer available to decline.', 409);
        }

        OrderDriverDecline::query()->firstOrCreate([
            'order_id' => $order->id,
            'user_id' => $driver->id,
        ]);

        return [
            'ok' => true,
            'order_id' => $order->id,
        ];
    }

    /**
     * @return array{order: array}
     */
    public function markPickedUp(User $driver, string $orderId): array
    {
        $order = $this->findAssigned($driver, $orderId);

        if ((int) $order->status_id !== OrderStatus::DRIVER_ASSIGNED) {
            throw ApiException::make('invalid_status', 'Order must be driver_assigned to mark picked up.', 422, [
                'current_status' => OrderStatus::codeFromId((int) $order->status_id),
            ]);
        }

        $this->payments->transitionOrder($order, OrderStatus::PICKED_UP, 'Driver picked up the package');
        $order->forceFill(['picked_up_at' => now('UTC')])->save();

        $customer = User::query()->find($order->user_id);
        if ($customer) {
            $this->notifications->push($customer, [
                'title_en' => 'Order picked up',
                'title_ar' => 'تم استلام الطلب',
                'body_en' => "Order {$order->id} was picked up.",
                'body_ar' => "تم استلام الطلب {$order->id}.",
                'route' => '/orders/'.$order->id,
                'data' => ['type' => 'order_picked_up', 'order_id' => $order->id],
            ]);
        }

        return ['order' => $this->orders->present($order->fresh([
            'service', 'status', 'promoCode', 'driver',
            'details.transportation', 'details.deliveryPreference', 'details.deliverySpeed',
            'statusEvents.status', 'payments',
        ]))];
    }

    /**
     * @return array{order: array}
     */
    public function markDelivered(User $driver, string $orderId, ?UploadedFile $proof = null): array
    {
        $order = $this->findAssigned($driver, $orderId);

        if ((int) $order->status_id !== OrderStatus::PICKED_UP) {
            throw ApiException::make('invalid_status', 'Order must be picked_up to mark delivered.', 422, [
                'current_status' => OrderStatus::codeFromId((int) $order->status_id),
            ]);
        }

        $updates = ['delivered_at' => now('UTC')];
        if ($proof) {
            $updates['delivery_proof_path'] = $this->r2->upload($proof, 'drivers/proofs');
        }

        $this->payments->transitionOrder($order, OrderStatus::DELIVERED, 'Driver delivered the order');
        $order->forceFill($updates)->save();

        return ['order' => $this->orders->present($order->fresh([
            'service', 'status', 'promoCode', 'driver',
            'details.transportation', 'details.deliveryPreference', 'details.deliverySpeed',
            'statusEvents.status', 'payments',
        ]))];
    }

    /**
     * @return array{earnings: array}
     */
    public function earnings(User $driver): array
    {
        $this->assertDriver($driver);

        $delivered = Order::query()
            ->where('driver_id', $driver->id)
            ->where('status_id', OrderStatus::DELIVERED);

        $count = (clone $delivered)->count();
        $tips = (float) (clone $delivered)->sum('tip_amount');
        $active = Order::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status_id', [OrderStatus::DRIVER_ASSIGNED, OrderStatus::PICKED_UP])
            ->count();

        return [
            'earnings' => [
                'delivered_orders' => $count,
                'active_orders' => $active,
                'total_tips' => number_format($tips, 2, '.', ''),
                'currency' => config('services.stripe.currency', 'aed'),
            ],
        ];
    }

    private function findAssigned(User $driver, string $orderId): Order
    {
        $this->assertDriver($driver);

        $order = Order::query()
            ->with([
                'service', 'status', 'promoCode', 'driver',
                'details.transportation', 'details.deliveryPreference', 'details.deliverySpeed',
                'statusEvents.status', 'payments',
            ])
            ->where('id', strtoupper($orderId))
            ->where('driver_id', $driver->id)
            ->first();

        if (! $order) {
            throw ApiException::make('not_found', 'Order not found for this driver.', 404);
        }

        return $order;
    }

    private function findVisibleToDriver(User $driver, string $orderId): Order
    {
        $order = Order::query()
            ->with([
                'service', 'status', 'promoCode', 'driver',
                'details.transportation', 'details.deliveryPreference', 'details.deliverySpeed',
                'statusEvents.status', 'payments',
            ])
            ->where('id', strtoupper($orderId))
            ->where(function ($q) use ($driver) {
                $q->where('driver_id', $driver->id)
                    ->orWhere(function ($q2) {
                        $q2->where('status_id', OrderStatus::AWAITING_DRIVER)->whereNull('driver_id');
                    });
            })
            ->first();

        if (! $order) {
            throw ApiException::make('not_found', 'Order not found.', 404);
        }

        // Offers must match the driver's current vehicle type.
        if ($order->driver_id === null) {
            $transportationId = $this->currentTransportationId($driver);
            $matches = $transportationId
                && $order->details->contains(fn ($d) => (int) $d->transportation_id === $transportationId);
            if (! $matches) {
                throw ApiException::make(
                    'transportation_mismatch',
                    'Your vehicle type does not match this order.',
                    422,
                );
            }
        }

        return $order;
    }

    private function assertOnlineApproved(User $driver): DriverProfile
    {
        $profile = $this->drivers->ensureProfile($driver);
        if (! $profile->canGoOnline()) {
            throw ApiException::make('driver_not_verified', 'Driver must be approved.', 422);
        }
        if (! $profile->is_online) {
            throw ApiException::make('driver_offline', 'Go online to receive and accept orders.', 422);
        }

        return $profile;
    }

    private function currentTransportationId(User $driver): ?int
    {
        $vehicle = DriverVehicle::query()
            ->where('user_id', $driver->id)
            ->where('is_current', true)
            ->first();

        return $vehicle ? (int) $vehicle->service_transportation_id : null;
    }

    private function assertDriver(User $user): void
    {
        if ((int) $user->user_role_id !== UserRole::DRIVER) {
            throw ApiException::make('driver_only', 'This action is only available for drivers.', 403);
        }
    }
}
