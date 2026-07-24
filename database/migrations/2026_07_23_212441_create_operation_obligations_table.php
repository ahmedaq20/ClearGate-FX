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
        Schema::create('operation_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('counterparty_id')->constrained('customers')->restrictOnDelete();
            $table->string('counterparty_role', 30)->index();
            $table->string('type', 30)->index();
            $table->string('reason', 40)->index();
            $table->decimal('amount', 18, 4);
            $table->string('currency', 10)->index();
            $table->decimal('exchange_rate', 18, 8)->nullable();
            $table->decimal('settled_amount', 18, 4)->default(0);
            $table->decimal('balance_amount', 18, 4);
            $table->string('status', 30)->default('open')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['operation_id', 'type', 'status'], 'op_obl_operation_type_status_idx');
            $table->index(['counterparty_id', 'type', 'status'], 'op_obl_counterparty_type_status_idx');
            $table->index(['currency', 'status'], 'op_obl_currency_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operation_obligations');
    }
};
