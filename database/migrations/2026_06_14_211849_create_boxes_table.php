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
        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('type', ['turkish', 'local_bank_wallet', 'usdt_wallet']);
            $table->decimal('current_balance', 18, 4)->default(0);
            $table->string('currency', 10);
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('assigned_user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('boxes');
    }
};
