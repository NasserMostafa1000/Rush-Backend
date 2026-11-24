<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Align services with product: send / receive / personal shopping / request delivery
        DB::table('services')->where('id', 3)->update([
            'service_name' => 'personal shopping',
            'updated_at' => now(),
        ]);

        DB::table('services')->insertOrIgnore([
            'id' => 4,
            'service_name' => 'request delivery',
            'standard_price' => null,
            'rushing_time_price' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // standard | rush (+ minutes stored on each order detail)
        Schema::create('delivery_speeds', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('label', 64);
        });

        DB::table('delivery_speeds')->insert([
            ['id' => 1, 'code' => 'standard', 'label' => 'Standard'],
            ['id' => 2, 'code' => 'rush', 'label' => 'Rush'],
        ]);

        Schema::create('orders', function (Blueprint $table) {
            $table->string('id', 10)->primary(); // RSH-XXXXXX
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('service_id');
            $table->decimal('total', 12, 2)->default(0);
            $table->timestampTz('ordered_at'); // UTC
            $table->timestampsTz();

            $table->foreign('service_id')->references('id')->on('services');
            $table->index('user_id');
            $table->index('service_id');
            $table->index('ordered_at');
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->string('id', 10)->primary(); // RSH-XXXXXX
            $table->string('order_id', 10);
            $table->unsignedTinyInteger('transportation_id');
            $table->unsignedTinyInteger('delivery_preference_id')->nullable();
            $table->unsignedTinyInteger('delivery_speed_id');
            $table->unsignedSmallInteger('minutes')->nullable();

            $table->decimal('pickup_latitude', 10, 7);
            $table->decimal('pickup_longitude', 10, 7);
            $table->decimal('dropoff_latitude', 10, 7);
            $table->decimal('dropoff_longitude', 10, 7);

            $table->string('pickup_notes', 300)->nullable();
            $table->string('dropoff_notes', 300)->nullable();
            $table->string('store_name', 160)->nullable(); // personal shopping
            $table->string('sender_name', 120);
            $table->string('sender_phone', 32);
            $table->string('receiver_name', 120);
            $table->string('receiver_phone', 32);
            $table->string('inside_the_package', 500)->nullable();

            $table->timestampsTz();

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('transportation_id')->references('id')->on('service_transportations');
            $table->foreign('delivery_preference_id')->references('id')->on('delivery_preferences');
            $table->foreign('delivery_speed_id')->references('id')->on('delivery_speeds');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('delivery_speeds');

        DB::table('services')->where('id', 4)->delete();
        DB::table('services')->where('id', 3)->update([
            'service_name' => 'package from market',
            'updated_at' => now(),
        ]);
    }
};
