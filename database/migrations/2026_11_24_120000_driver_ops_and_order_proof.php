<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->boolean('is_online')->default(false)->after('verified_at');
            $table->decimal('last_latitude', 10, 7)->nullable()->after('is_online');
            $table->decimal('last_longitude', 10, 7)->nullable()->after('last_latitude');
            $table->timestamp('last_online_at')->nullable()->after('last_longitude');
            $table->index('is_online');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_proof_path', 255)->nullable()->after('rating_comment');
            $table->timestampTz('picked_up_at')->nullable()->after('delivery_proof_path');
            $table->timestampTz('delivered_at')->nullable()->after('picked_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_proof_path', 'picked_up_at', 'delivered_at']);
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropIndex(['is_online']);
            $table->dropColumn(['is_online', 'last_latitude', 'last_longitude', 'last_online_at']);
        });
    }
};
