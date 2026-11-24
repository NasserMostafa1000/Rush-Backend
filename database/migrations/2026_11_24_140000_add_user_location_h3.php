<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('last_login_at');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('h3_index', 32)->nullable()->after('longitude');
            $table->timestamp('location_updated_at')->nullable()->after('h3_index');

            $table->index('h3_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['h3_index']);
            $table->dropColumn(['latitude', 'longitude', 'h3_index', 'location_updated_at']);
        });
    }
};
