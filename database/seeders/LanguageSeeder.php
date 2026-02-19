<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class LanguageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if any language is already set as default
        $hasDefault = Language::where('is_default', true)->exists();

        if (!$hasDefault) {
            // Create English as the default language if it doesn't exist
            $englishLanguage = Language::updateOrCreate(['code' => 'en'], [
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
                'status' => 1,
            ]);

            // Create the language files if they don't exist
            $this->createLanguageFiles($englishLanguage);
        }
    }

    /**
     * Create language files if they don't exist
     */
    private function createLanguageFiles(Language $language)
    {
        $langPath = base_path('resources/lang');

        // Create panel file
        $panelFile = $langPath . '/' . $language->panel_file;
        if (!File::exists($panelFile)) {
            $defaultContent = json_encode([
                'welcome' => 'Welcome',
                'dashboard' => 'Dashboard',
                'courses' => 'Courses',
                'instructors' => 'Instructors',
                'students' => 'Students',
                'settings' => 'Settings',
                'logout' => 'Logout',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($panelFile, $defaultContent);
        }

        // Create web file
        $webFile = $langPath . '/' . $language->web_file;
        if (!File::exists($webFile)) {
            $defaultContent = json_encode([
                'welcome' => 'Welcome',
                'learn_online' => 'Learn Online',
                'get_started' => 'Get Started',
                'about_us' => 'About Us',
                'contact_us' => 'Contact Us',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($webFile, $defaultContent);
        }

        // Create app file
        $appFile = $langPath . '/' . $language->app_file;
        if (!File::exists($appFile)) {
            $defaultContent = json_encode([
                'welcome' => 'Welcome',
                'login' => 'Login',
                'register' => 'Register',
                'profile' => 'Profile',
                'settings' => 'Settings',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            File::put($appFile, $defaultContent);
        }
    }
}
