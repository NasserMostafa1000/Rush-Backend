<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('services')->insertOrIgnore([
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
                'service_name' => 'package from market',
                'standard_price' => null,
                'rushing_time_price' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('services')->whereIn('id', [2, 3])->delete();
    }
};
