<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('national_id_number', 64)->nullable();
            $table->string('national_id_front_path', 255)->nullable();
            $table->string('national_id_back_path', 255)->nullable();
            $table->string('personal_photo_path', 255)->nullable();
            $table->string('driving_license_front_path', 255)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('iban', 64)->nullable();
            $table->string('ziina_payment_link', 255)->nullable();
            $table->string('service_area', 120)->nullable();
            $table->string('verification_status', 32)->default('incomplete');
            $table->string('verification_note', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('verification_status');
        });

        Schema::create('driver_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('service_transportation_id');
            $table->string('model', 120);
            $table->string('color', 64)->nullable();
            $table->string('plate_number', 32);
            $table->string('vehicle_image_path', 255)->nullable();
            $table->string('document_path', 255)->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->foreign('service_transportation_id')
                ->references('id')
                ->on('service_transportations');
            $table->index(['user_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_vehicles');
        Schema::dropIfExists('driver_profiles');
    }
};
