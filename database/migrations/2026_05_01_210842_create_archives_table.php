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
        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->string('archivable_type', 50);
            $table->unsignedBigInteger('archivable_id');
            $table->foreignId('archived_by')->constrained('users');
            $table->text('reason')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at')->nullable();

            $table->index(['archivable_type', 'archivable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archives');
    }
};
