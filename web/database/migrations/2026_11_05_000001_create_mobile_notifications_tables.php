<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Préférences par compte, boîte privée et appareils liés au jeton de session. */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('appointment_updates')->default(false);
            $table->boolean('appointment_reminders')->default(false);
            $table->unsignedSmallInteger('reminder_minutes')->default(60);
            $table->boolean('push_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('appointment_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->timestamp('appointment_starts_at');
            $table->string('deduplication_key', 64)->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('push_dispatched_at')->nullable();
            $table->boolean('wants_push')->default(false);
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->unique()->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->string('installation_hash', 64)->unique();
            $table->text('installation_id');
            $table->string('platform', 10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
        Schema::dropIfExists('appointment_notifications');
        Schema::dropIfExists('notification_preferences');
    }
};
