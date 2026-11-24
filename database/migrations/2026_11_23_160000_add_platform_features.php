<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_roles')->insertOrIgnore([
            'id' => 4,
            'code' => 'admin',
            'name' => 'Admin',
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('invite_code', 16)->nullable()->unique()->after('public_id');
            $table->foreignId('invited_by_user_id')->nullable()->after('invite_code')
                ->constrained('users')->nullOnDelete();
            $table->unsignedInteger('loyalty_points')->default(0)->after('invited_by_user_id');
            $table->string('license_image_path', 255)->nullable()->after('loyalty_points');
            $table->boolean('invite_reward_granted')->default(false)->after('license_image_path');
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->text('value')->nullable();
            $table->timestampsTz();
        });

        $now = now();
        DB::table('app_settings')->insert([
            ['key' => 'loyalty.aed_per_point', 'value' => '0.10', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'loyalty.invite_reward_points', 'value' => '50', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'loyalty.min_completed_orders_for_invite', 'value' => '1', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('type', 16); // fixed | percent
            $table->decimal('value', 12, 2);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('order_id', 10)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->timestampsTz();

            $table->unique(['promo_code_id', 'user_id']);
            $table->index('order_id');
        });

        Schema::create('order_statuses', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('label', 64);
        });

        DB::table('order_statuses')->insert([
            ['id' => 1, 'code' => 'pending_payment', 'label' => 'Pending payment'],
            ['id' => 2, 'code' => 'confirmed', 'label' => 'Confirmed'],
            ['id' => 3, 'code' => 'driver_assigned', 'label' => 'Driver assigned'],
            ['id' => 4, 'code' => 'picked_up', 'label' => 'Picked up'],
            ['id' => 5, 'code' => 'delivered', 'label' => 'Delivered'],
            ['id' => 6, 'code' => 'cancelled', 'label' => 'Cancelled'],
            ['id' => 7, 'code' => 'awaiting_cash', 'label' => 'Awaiting cash'],
        ]);

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('status_id')->default(1)->after('service_id');
            $table->foreignId('driver_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('payment_method', 16)->default('card')->after('total'); // card | cash
            $table->string('payer', 16)->default('sender')->after('payment_method'); // sender | receiver
            $table->string('pickup_timing', 16)->default('asap')->after('payer'); // asap | scheduled
            $table->timestampTz('scheduled_pickup_at')->nullable()->after('pickup_timing');
            $table->decimal('distance_km', 10, 2)->nullable()->after('scheduled_pickup_at');
            $table->decimal('subtotal', 12, 2)->default(0)->after('distance_km');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal');
            $table->decimal('tip_amount', 12, 2)->default(0)->after('discount_amount');
            $table->decimal('loyalty_discount_amount', 12, 2)->default(0)->after('tip_amount');
            $table->unsignedInteger('loyalty_points_redeemed')->default(0)->after('loyalty_discount_amount');
            $table->foreignId('promo_code_id')->nullable()->after('loyalty_points_redeemed')
                ->constrained('promo_codes')->nullOnDelete();
            $table->unsignedSmallInteger('estimated_pickup_minutes')->nullable()->after('promo_code_id');
            $table->unsignedSmallInteger('estimated_delivery_minutes')->nullable()->after('estimated_pickup_minutes');
            $table->unsignedTinyInteger('rating')->nullable()->after('estimated_delivery_minutes');
            $table->text('rating_comment')->nullable()->after('rating');
            $table->timestampTz('cancelled_at')->nullable()->after('rating_comment');

            $table->foreign('status_id')->references('id')->on('order_statuses');
            $table->index('status_id');
            $table->index('driver_id');
        });

        Schema::table('order_details', function (Blueprint $table) {
            $table->string('pickup_address', 255)->nullable()->after('dropoff_longitude');
            $table->string('dropoff_address', 255)->nullable()->after('pickup_address');
            $table->string('package_size', 40)->nullable()->after('dropoff_address');
            $table->text('shopping_request')->nullable()->after('store_name');
            $table->boolean('is_already_paid')->nullable()->after('shopping_request');
            $table->decimal('expected_order_value', 12, 2)->nullable()->after('is_already_paid');
            $table->string('invoice_image_path', 255)->nullable()->after('expected_order_value');
        });

        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 10);
            $table->unsignedTinyInteger('status_id');
            $table->string('note', 255)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('status_id')->references('id')->on('order_statuses');
            $table->index(['order_id', 'occurred_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('order_id', 10);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 32)->default('stripe');
            $table->string('method', 16); // card | cash
            $table->string('status', 32)->default('pending'); // pending | requires_action | paid | failed | cancelled
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('aed');
            $table->string('stripe_payment_intent_id', 120)->nullable()->unique();
            $table->string('stripe_client_secret', 255)->nullable();
            $table->string('transaction_id', 120)->nullable();
            $table->json('meta')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index('order_id');
            $table->index('status');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title_en', 160);
            $table->string('title_ar', 160);
            $table->text('body_en');
            $table->text('body_ar');
            $table->string('route', 255)->nullable();
            $table->json('data')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'read_at']);
        });

        Schema::create('saved_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label', 32); // home | work | other
            $table->string('address', 255);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('notes', 200)->nullable();
            $table->timestampsTz();

            $table->index('user_id');
        });

        // Backfill invite codes for existing users
        $users = DB::table('users')->select('id')->get();
        foreach ($users as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'invite_code' => strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_addresses');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_status_events');

        Schema::table('order_details', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_address',
                'dropoff_address',
                'package_size',
                'shopping_request',
                'is_already_paid',
                'expected_order_value',
                'invoice_image_path',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
            $table->dropForeign(['driver_id']);
            $table->dropForeign(['promo_code_id']);
            $table->dropColumn([
                'status_id',
                'driver_id',
                'payment_method',
                'payer',
                'pickup_timing',
                'scheduled_pickup_at',
                'distance_km',
                'subtotal',
                'discount_amount',
                'tip_amount',
                'loyalty_discount_amount',
                'loyalty_points_redeemed',
                'promo_code_id',
                'estimated_pickup_minutes',
                'estimated_delivery_minutes',
                'rating',
                'rating_comment',
                'cancelled_at',
            ]);
        });

        Schema::dropIfExists('order_statuses');
        Schema::dropIfExists('promo_code_redemptions');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('app_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['invited_by_user_id']);
            $table->dropColumn([
                'invite_code',
                'invited_by_user_id',
                'loyalty_points',
                'license_image_path',
                'invite_reward_granted',
            ]);
        });

        DB::table('user_roles')->where('id', 4)->delete();
    }
};
