<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('course_chapters', function (Blueprint $table) {
            if (Schema::hasColumn('course_chapters', 'order')) {
                $table->renameColumn('order', 'chapter_order');
            }
        });
    }

    public function down()
    {
        Schema::table('course_chapters', function (Blueprint $table) {
            if (Schema::hasColumn('course_chapters', 'chapter_order')) {
                $table->renameColumn('chapter_order', 'order');
            }
        });
    }
};
