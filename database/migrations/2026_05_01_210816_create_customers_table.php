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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('vault_id')->constrained();
            $table->string('customer_code', 20)->unique();
            $table->string('name', 100);
            $table->string('phone', 30)->nullable();
            $table->enum('type', ['customer', 'supplier'])->default('customer');
            $table->text('note')->nullable();
            $table->enum('category', ['regular', 'vip', 'agent', 'company'])->default('regular');
            $table->decimal('balance_usd', 18, 4)->default(0);
            $table->string('country', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index('user_id');
            $table->index('vault_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
