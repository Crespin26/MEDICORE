<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_record_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->restrictOnDelete();
            $table->string('kind');
            $table->text('label');
            $table->text('details')->nullable();
            $table->date('occurred_on')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['patient_id', 'doctor_id', 'kind']);
        });
        Schema::create('medical_entry_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('author_name');
            $table->unsignedInteger('version');
            $table->text('correction_reason')->nullable();
            $table->text('snapshot');
            $table->timestamp('created_at');
            $table->unique(['medical_record_entry_id', 'version']);
        });
        Schema::create('medical_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->restrictOnDelete();
            $table->text('title');
            $table->text('original_name');
            $table->string('path')->unique();
            $table->string('mime_type');
            $table->string('extension', 4);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->timestamps();
            $table->index(['patient_id', 'doctor_id']);
        });
        Schema::create('medical_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('version')->nullable();
            $table->timestamp('created_at');
            $table->index(['patient_id', 'actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_access_events');
        Schema::dropIfExists('medical_documents');
        Schema::dropIfExists('medical_entry_revisions');
        Schema::dropIfExists('medical_record_entries');
    }
};
