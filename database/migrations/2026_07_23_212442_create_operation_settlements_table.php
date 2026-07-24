<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('operation_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('operation_obligation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('counterparty_id')->constrained('customers')->restrictOnDelete();
            $table->string('counterparty_role', 30)->index();
            $table->string('direction', 30)->index();
            $table->decimal('amount', 18, 4);
            $table->string('currency', 10)->index();
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->foreignId('box_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vault_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('settlement_date')->index();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['operation_id', 'direction'], 'op_set_operation_direction_idx');
            $table->index(['operation_obligation_id', 'settlement_date'], 'op_set_obligation_date_idx');
            $table->index(['counterparty_id', 'currency'], 'op_set_counterparty_currency_idx');
            $table->index(['box_id', 'settlement_date'], 'op_set_box_date_idx');
            $table->index(['vault_id', 'settlement_date'], 'op_set_vault_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operation_settlements');
    }
};
