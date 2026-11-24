<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_transportations', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('label', 64);
            $table->timestamps();
        });

        $now = now();
        DB::table('service_transportations')->insert([
            [
                'id' => 1,
                'code' => 'car',
                'label' => 'Car',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'code' => 'motorcycle',
                'label' => 'Motorcycle',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_transportations');
    }
};
