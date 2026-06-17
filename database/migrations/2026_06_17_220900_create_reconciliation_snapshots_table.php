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
        Schema::create('reconciliation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->decimal('capital_balance', 18, 4);
            $table->decimal('boxes_total_balance', 18, 4);
            $table->decimal('free_capital', 18, 4);
            $table->decimal('difference', 18, 4);
            $table->enum('status', ['balanced', 'mismatch']);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
            $table->index('created_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_snapshots');
    }
};
