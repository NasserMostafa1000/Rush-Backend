<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_roles')->insertOrIgnore([
            'id' => 3,
            'code' => 'driver',
            'name' => 'Driver',
        ]);
    }

    public function down(): void
    {
        DB::table('user_roles')->where('id', 3)->delete();
    }
};
