<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL has no TINYINT; unsignedTinyInteger → smallint (0–255 range enforced in app).
        Schema::create('services', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('service_name', 120);
            $table->decimal('standard_price', 12, 2)->nullable();
            $table->decimal('rushing_time_price', 12, 2)->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('services')->insert([
            [
                'id' => 1,
                'service_name' => 'send a package',
                'standard_price' => null,
                'rushing_time_price' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'service_name' => 'receive a package',
                'standard_price' => null,
                'rushing_time_price' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'service_name' => 'personal shopping',
                'standard_price' => null,
                'rushing_time_price' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 4,
                'service_name' => 'request delivery',
                'standard_price' => null,
                'rushing_time_price' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
