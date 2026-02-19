<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Use raw SQL to modify the column to be nullable
        // This works even without doctrine/dbal package
        // Check the actual column type first and modify accordingly
        try {
            $columnInfo = DB::select("SHOW COLUMNS FROM promo_codes WHERE Field = 'max_discount_amount'");
            if (!empty($columnInfo)) {
                $columnType = $columnInfo[0]->Type;
                // Modify column to be nullable, preserving the original type
                DB::statement("ALTER TABLE promo_codes MODIFY COLUMN max_discount_amount {$columnType} NULL");
            }
        } catch (\Exception $e) {
            // Fallback: try with INT if column info query fails
            DB::statement('ALTER TABLE promo_codes MODIFY COLUMN max_discount_amount INT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Use raw SQL to modify the column to be NOT NULL
        DB::statement('ALTER TABLE promo_codes MODIFY COLUMN max_discount_amount INT NOT NULL');
    }
};
