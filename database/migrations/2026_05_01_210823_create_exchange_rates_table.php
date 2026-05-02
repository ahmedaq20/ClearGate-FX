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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 10);
            $table->decimal('rate', 18, 6);
            $table->enum('source', ['manual', 'import'])->default('manual');
            $table->date('date');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->index(['currency_code', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
