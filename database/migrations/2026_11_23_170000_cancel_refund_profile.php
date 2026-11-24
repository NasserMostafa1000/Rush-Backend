<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_statuses')->insertOrIgnore([
            'id' => 8,
            'code' => 'awaiting_driver',
            'label' => 'Waiting for driver acceptance',
        ]);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            $table->string('refund_status', 32)->nullable()->after('cancellation_reason');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('stripe_refund_id', 120)->nullable()->after('stripe_payment_intent_id');
            $table->decimal('refunded_amount', 12, 2)->nullable()->after('stripe_refund_id');
            $table->timestampTz('refunded_at')->nullable()->after('refunded_amount');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_image_path', 255)->nullable()->after('license_image_path');
            $table->boolean('push_notifications_enabled')->default(true)->after('profile_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['profile_image_path', 'push_notifications_enabled']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['stripe_refund_id', 'refunded_amount', 'refunded_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cancellation_reason', 'refund_status']);
        });

        DB::table('order_statuses')->where('id', 8)->delete();
    }
};
