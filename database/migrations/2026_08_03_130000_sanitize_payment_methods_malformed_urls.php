<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sanitize payment_methods table logos
        if (Schema::hasTable('payment_methods')) {
            $methods = DB::table('payment_methods')->whereNotNull('logo')->get();
            foreach ($methods as $method) {
                if (!empty($method->logo)) {
                    $cleaned = trim((string) $method->logo, " \t\n\r\0\x0B'\"`");
                    if ($cleaned !== $method->logo) {
                        DB::table('payment_methods')->where('id', $method->id)->update(['logo' => $cleaned]);
                    }
                }
            }
        }

        // Sanitize manual_deposit_methods table images
        if (Schema::hasTable('manual_deposit_methods')) {
            $methods = DB::table('manual_deposit_methods')->whereNotNull('image')->get();
            foreach ($methods as $method) {
                if (!empty($method->image)) {
                    $cleaned = trim((string) $method->image, " \t\n\r\0\x0B'\"`");
                    if ($cleaned !== $method->image) {
                        DB::table('manual_deposit_methods')->where('id', $method->id)->update(['image' => $cleaned]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive cleanup migration; no action required on rollback.
    }
};
