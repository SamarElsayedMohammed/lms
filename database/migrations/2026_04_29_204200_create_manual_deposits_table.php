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
        Schema::create('manual_deposits', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('user_id')->constrained()->onDelete('cascade');
            $blueprint->foreignId('manual_deposit_method_id')->constrained()->onDelete('cascade');
            $blueprint->decimal('amount', 15, 2);
            $blueprint->string('transaction_id')->nullable();
            $blueprint->string('receipt')->nullable();
            $blueprint->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $blueprint->text('admin_notes')->nullable();
            $blueprint->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manual_deposits');
    }
};
