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
        Schema::table('box_balance_logs', function (Blueprint $table) {
            $table->foreignId('operation_settlement_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason', 40)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('box_balance_logs', function (Blueprint $table) {
            $table->dropForeign(['operation_settlement_id']);
            $table->dropColumn(['operation_settlement_id', 'reason']);
        });
    }
};
