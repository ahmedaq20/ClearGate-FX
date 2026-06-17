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
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 30)->unique();
            $table->date('transaction_date');
            $table->foreignId('supplier_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('box_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('supplier_currency', 10)->nullable();
            $table->decimal('supplier_amount', 18, 4)->nullable();
            $table->decimal('supplier_exchange_rate', 18, 8)->nullable();
            $table->string('customer_currency', 10);
            $table->decimal('customer_amount', 18, 4);
            $table->decimal('customer_exchange_rate', 18, 8);
            $table->enum('commission_type', ['percentage', 'fixed']);
            $table->decimal('commission_rate', 18, 4);
            $table->decimal('commission_amount', 18, 4);
            $table->decimal('customer_net_amount', 18, 4);
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('transaction_date');
            $table->index('supplier_id');
            $table->index('box_id');
            $table->index('customer_id');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
