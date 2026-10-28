<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->dateTime('consulted_at');
            $table->string('status')->default('draft');
            $table->string('reason', 500);
            $table->text('observations')->nullable();
            $table->text('report')->nullable();
            $table->json('vitals')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('validated_at')->nullable();
            $table->timestamps();
            $table->index(['doctor_id', 'patient_id', 'consulted_at']);
        });
        Schema::create('consultation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('author_name');
            $table->unsignedInteger('version');
            $table->string('action');
            $table->text('correction_reason')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['consultation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_revisions');
        Schema::dropIfExists('consultations');
    }
};
