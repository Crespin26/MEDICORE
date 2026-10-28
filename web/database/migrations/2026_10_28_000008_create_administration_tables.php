<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('opening_hours')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        DB::table('clinic_settings')->insert(['id' => 1, 'name' => config('app.name'), 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('clinic_setting_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->unsignedInteger('version');
            $table->text('reason');
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
        Schema::create('administration_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->string('type');
            $table->date('date_from');
            $table->date('date_to');
            $table->text('reason');
            $table->unsignedInteger('row_count');
            $table->string('sha256', 64);
            $table->timestamp('created_at');
        });
        Schema::table('invoices', fn (Blueprint $table) => $table->json('issuer_details')->nullable());
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('issuer_details'));
        Schema::dropIfExists('administration_exports');
        Schema::dropIfExists('clinic_setting_events');
        Schema::dropIfExists('clinic_settings');
    }
};
