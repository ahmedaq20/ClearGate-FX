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
        Schema::create('capital_box_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capital_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('box_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 10);
            $table->decimal('allocated_balance', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['capital_account_id', 'box_id', 'currency'], 'capital_box_allocations_unique');
            $table->index(['box_id', 'currency']);
            $table->index('allocated_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capital_box_allocations');
    }
};
