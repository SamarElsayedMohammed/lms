<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settings = [
            [
                'name' => 'affiliate_min_withdrawal',
                'value' => '500',
                'type' => 'number',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['name' => $setting['name']],
                $setting
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Setting::whereIn('name', ['affiliate_min_withdrawal'])->delete();
    }
};
