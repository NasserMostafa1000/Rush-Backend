<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_preferences', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('label', 120);
            $table->timestamps();
        });

        $now = now();
        DB::table('delivery_preferences')->insert([
            [
                'id' => 1,
                'code' => 'call_on_arrival',
                'label' => 'Call on arrival',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'code' => 'dont_knock_on_the_door',
                'label' => "Don't knock on the door",
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'code' => 'leave',
                'label' => 'Leave',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 4,
                'code' => 'leave_at_reception',
                'label' => 'Leave it at the reception',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 5,
                'code' => 'ring_the_bell',
                'label' => 'Ring the bell',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_preferences');
    }
};
