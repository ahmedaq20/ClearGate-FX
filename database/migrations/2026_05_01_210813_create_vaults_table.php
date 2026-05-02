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
        Schema::create('vaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->string('name', 100);
            $table->decimal('initial_balance', 18, 4)->default(0);
            $table->decimal('balance_usd', 18, 4)->default(0);
            $table->string('currency_code', 10)->default('USD');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vaults');
    }
};
