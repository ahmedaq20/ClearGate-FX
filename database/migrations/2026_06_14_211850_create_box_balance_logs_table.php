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
        Schema::create('box_balance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('box_id')->constrained()->cascadeOnDelete();
            $table->enum('operation_type', ['add', 'subtract']);
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_before', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('operation_type');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('box_balance_logs');
    }
};
