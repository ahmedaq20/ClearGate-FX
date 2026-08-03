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
            $table->string('commission_payer', 20)->default('customer')->after('commission_rate')->index();
            $table->decimal('customer_commission_amount', 18, 4)->default(0)->after('commission_amount');
            $table->decimal('supplier_commission_amount', 18, 4)->default(0)->after('customer_commission_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn([
                'commission_payer',
                'customer_commission_amount',
                'supplier_commission_amount',
            ]);
        });
    }
};
