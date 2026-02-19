<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Setting;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add subscription-related settings
        $settings = [
            [
                'name' => 'subscription_auto_renew_default',
                'value' => '1',
                'type' => 'boolean',
            ],
            [
                'name' => 'subscription_enabled',
                'value' => '1',
                'type' => 'boolean',
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
        Setting::whereIn('name', [
            'subscription_auto_renew_default',
            'subscription_enabled',
        ])->delete();
    }
};
