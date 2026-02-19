<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Language;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if any language is marked as default
        $hasDefault = Language::where('is_default', true)->exists();
        
        if (!$hasDefault) {
            // If no default language exists, set English as default
            $englishLanguage = Language::where('code', 'en')->first();
            
            if ($englishLanguage) {
                $englishLanguage->update(['is_default' => true]);
            } else {
                // If English doesn't exist, create it and set as default
                Language::create([
                    'name' => 'English',
                    'name_in_english' => 'English',
                    'code' => 'en',
                    'app_file' => 'en_app.json',
                    'panel_file' => 'en.json',
                    'web_file' => 'en_web.json',
                    'rtl' => false,
                    'image' => 'flags/us.svg',
                    'country_code' => 'US',
                    'is_default' => true,
                    'status' => 1
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration doesn't need to be reversed
        // as it only ensures data integrity
    }
};
