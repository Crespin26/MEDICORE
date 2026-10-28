<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_sequences', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
        DB::table('billing_sequences')->insert([['name' => 'invoice', 'value' => 0], ['name' => 'receipt', 'value' => 0]]);
        Schema::create('billable_services', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('currency', 3);
            $table->unsignedBigInteger('price_minor');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('create_token')->unique();
            $table->string('request_fingerprint', 64);
            $table->string('patient_name');
            $table->string('number')->nullable()->unique();
            $table->string('currency', 3);
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'status']);
        });
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('billable_service_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('label');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('total_minor');
        });
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('recorder_name');
            $table->uuid('token')->unique();
            $table->string('receipt_number')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->string('method');
            $table->string('reference')->nullable();
            $table->dateTime('paid_at');
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('voider_name')->nullable();
            $table->text('void_reason')->nullable();
        });
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('billable_service_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->string('action');
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['billing_events', 'invoice_payments', 'invoice_lines', 'invoices', 'billable_services', 'billing_sequences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
