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
            $table->foreignId('operation_id')
                ->nullable()
                ->after('box_id')
                ->constrained('operations')
                ->nullOnDelete();

            $table->index('operation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('box_balance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operation_id');
        });
    }
};
