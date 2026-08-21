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
        Schema::table('webinar_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('webinar_registrations', 'form_responses')) {
                $table->json('form_responses')->nullable()->after('attended')
                      ->comment('JSON key-value storage for dynamic registration custom fields submitted by attendee');
            }
            if (!Schema::hasColumn('webinar_registrations', 'utm_source')) {
                $table->string('utm_source')->nullable()->after('form_responses')
                      ->comment('Marketing UTM source tracking');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webinar_registrations', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('webinar_registrations', 'form_responses')) $cols[] = 'form_responses';
            if (Schema::hasColumn('webinar_registrations', 'utm_source')) $cols[] = 'utm_source';
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
