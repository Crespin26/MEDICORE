<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->boolean('distinct_identity')->default(false);
            $table->timestamp('archived_at')->nullable()->index();
        });
        Schema::create('patient_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action');
            $table->text('reason');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('clinical_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('viewer_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['patient_id', 'owner_id', 'viewer_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restaurer une sauvegarde compatible pour préserver les décisions d’identité, d’archivage et de partage.');
    }
};
