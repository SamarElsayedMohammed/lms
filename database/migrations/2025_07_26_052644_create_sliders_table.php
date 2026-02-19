<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSlidersTable extends Migration
{
    public function up()
    {
        Schema::create('sliders', function (Blueprint $table) {
            $table->id(); // `id` BIGINT UNSIGNED AUTO_INCREMENT
            $table->string('image'); // NOT NULL
            $table->string('order'); // NOT NULL
            $table->string('third_party_link')->nullable(); // DEFAULT NULL
            $table->timestamps(); // `created_at`, `updated_at` NULLABLE

            $table->string('model_type')->nullable(); // polymorphic relation type
            $table->unsignedBigInteger('model_id')->nullable(); // polymorphic relation ID

            $table->index(['model_type', 'model_id'], 'sliders_model_type_model_id_index');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sliders');
    }
}