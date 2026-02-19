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
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('id'); 
            $table->string('title', 191);
            $table->string('message', 250);
            $table->string('type', 191);
            $table->integer('type_id');
            $table->string('type_link', 191)->nullable();
            $table->string('image', 191)->nullable();
            $table->timestamp('date_sent')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
