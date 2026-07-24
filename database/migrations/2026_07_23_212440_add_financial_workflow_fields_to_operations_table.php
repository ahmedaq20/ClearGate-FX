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
        Schema::table('operations', function (Blueprint $table) {
            $table->string('customer_direction', 40)->nullable()->index();
            $table->string('customer_settlement_status', 30)->nullable()->index();
            $table->timestamp('customer_settled_at')->nullable()->index();
            $table->string('supplier_fulfillment_status', 30)->nullable()->index();
            $table->timestamp('supplier_fulfilled_at')->nullable()->index();
            $table->string('supplier_settlement_status', 30)->nullable()->index();
            $table->timestamp('supplier_settled_at')->nullable()->index();
            $table->string('commission_currency', 10)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn([
                'customer_direction',
                'customer_settlement_status',
                'customer_settled_at',
                'supplier_fulfillment_status',
                'supplier_fulfilled_at',
                'supplier_settlement_status',
                'supplier_settled_at',
                'commission_currency',
            ]);
        });
    }
};
