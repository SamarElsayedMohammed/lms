<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_section_analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_section_id')->constrained('feature_sections')->cascadeOnDelete();
            $table->date('date');
            $table->integer('views')->default(0);
            $table->integer('clicks')->default(0);
            $table->integer('enrollments')->default(0);
            $table->decimal('revenue', 10, 2)->default(0.00);
            $table->timestamps();

            $table->unique(['feature_section_id', 'date'], 'feature_section_analytics_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_section_analytics_daily');
    }
};
