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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('vault_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->unsignedBigInteger('from_customer_id')->nullable();
            $table->unsignedBigInteger('to_customer_id')->nullable();
            $table->enum('type', ['receive', 'send', 'transfer']);
            $table->decimal('amount', 18, 4);
            $table->string('currency_code', 10);
            $table->decimal('exchange_rate', 18, 6);
            $table->decimal('usd_value', 18, 4);
            $table->enum('commission_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('commission_rate', 8, 4)->nullable();
            $table->tinyInteger('commission_sign')->nullable();
            $table->decimal('commission_usd', 18, 4)->default(0);
            $table->decimal('net_usd_value', 18, 4);
            $table->tinyInteger('direction');
            $table->text('note')->nullable();
            $table->string('reference_number', 50)->nullable()->unique();
            $table->string('country', 50)->nullable();
            $table->date('transaction_date');
            $table->timestamps();
            $table->softDeletes()->index();

            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->foreign('from_customer_id')->references('id')->on('customers');
            $table->foreign('to_customer_id')->references('id')->on('customers');
            $table->index(['user_id', 'transaction_date']);
            $table->index('vault_id');
            $table->index('customer_id');
            $table->index('transaction_date');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
