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
        Schema::create('owner_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capital_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->enum('category', ['vehicle', 'housing', 'family', 'education', 'medical', 'travel', 'other']);
            $table->decimal('amount', 18, 4);
            $table->date('expense_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('expense_date');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('owner_expenses');
    }
};
