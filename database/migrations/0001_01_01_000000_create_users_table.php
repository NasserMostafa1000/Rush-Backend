<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lookup: personal | store | driver  (smallint 0–255)
        Schema::create('user_roles', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name', 64);
        });

        // Lookup: google | apple | phone
        Schema::create('os_providers', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name', 64);
        });

        DB::table('user_roles')->insert([
            ['id' => 1, 'code' => 'personal', 'name' => 'Personal'],
            ['id' => 2, 'code' => 'store', 'name' => 'Store'],
            ['id' => 3, 'code' => 'driver', 'name' => 'Driver'],
        ]);

        DB::table('os_providers')->insert([
            ['id' => 1, 'code' => 'google', 'name' => 'Google'],
            ['id' => 2, 'code' => 'apple', 'name' => 'Apple'],
            ['id' => 3, 'code' => 'phone', 'name' => 'Phone'],
        ]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 32)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->unsignedTinyInteger('user_role_id');
            $table->string('preferred_language', 8)->default('en');
            $table->unsignedTinyInteger('os_provider_id');
            $table->string('google_id')->nullable()->unique();
            $table->string('apple_id')->nullable()->unique();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->foreign('user_role_id')->references('id')->on('user_roles');
            $table->foreign('os_provider_id')->references('id')->on('os_providers');
            $table->index('user_role_id');
            $table->index('os_provider_id');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('os_providers');
        Schema::dropIfExists('user_roles');
    }
};
