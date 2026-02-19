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
            ['name' => 'kashier_merchant_id', 'value' => '', 'type' => 'string'],
            ['name' => 'kashier_api_key', 'value' => '', 'type' => 'string'],
            ['name' => 'kashier_webhook_secret', 'value' => '', 'type' => 'string'],
            ['name' => 'kashier_mode', 'value' => 'test', 'type' => 'string'],
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
            'kashier_merchant_id',
            'kashier_api_key',
            'kashier_webhook_secret',
            'kashier_mode',
        ])->delete();
    }
};
