<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('employee_number', 40)->nullable()->unique();
            $table->string('job_title', 150);
            $table->string('department', 150)->nullable();
            $table->json('specialties')->nullable();
            $table->string('professional_phone', 30)->nullable();
            $table->string('license_number', 100)->nullable();
            $table->date('started_on')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('staff_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('category');
            $table->text('reason');
            $table->string('status')->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'starts_at', 'ends_at']);
        });
        Schema::create('personnel_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->string('action');
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_events');
        Schema::dropIfExists('staff_absences');
        Schema::dropIfExists('professional_profiles');
    }
};
