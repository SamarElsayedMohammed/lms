<?php

declare(strict_types=1);

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
                'name' => 'hls_auto_encode',
                'value' => '1',
                'type' => 'boolean',
            ],
            [
                'name' => 'hls_max_file_size_mb',
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
        Setting::whereIn('name', ['hls_auto_encode', 'hls_max_file_size_mb'])->delete();
    }
};
