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
        Schema::create('capital_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capital_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('box_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_expense_id')->nullable()->index();
            $table->enum('type', ['deposit', 'withdraw', 'expense', 'box_transfer']);
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_before', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->date('transaction_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('transaction_date');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capital_transactions');
    }
};
