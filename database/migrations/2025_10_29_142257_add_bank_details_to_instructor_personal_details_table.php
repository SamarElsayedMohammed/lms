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
        Schema::table('instructor_personal_details', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('bank_account_number');
            $table->string('bank_account_holder_name')->nullable()->after('bank_name');
            $table->string('bank_ifsc_code')->nullable()->after('bank_account_holder_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instructor_personal_details', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account_holder_name', 'bank_ifsc_code']);
        });
    }
};
