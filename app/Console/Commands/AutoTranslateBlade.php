<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AutoTranslateBlade extends Command
{
    protected $signature = 'blade:auto-translate {path=resources/views}';
    protected $description = 'Wrap static text in blade files with __() for translation and add to en.json';

    public function handle()
    {
        $path = base_path($this->argument('path'));
        $files = File::allFiles($path);
        $count = 0;

        // Load existing en.json
        $langFile = resource_path('lang/en.json');
        $translations = File::exists($langFile) ? json_decode(File::get($langFile), true) : [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = File::get($file->getRealPath());

            $newContent = preg_replace_callback(
                '/>([^<\{\}@][^<\{\}]*)</m',
                static function ($matches) use (&$translations) {
                    $text = trim($matches[1]);
                    if ($text === '' || str_starts_with($text, '{{')) {
                        return ">{$matches[1]}<";
                    }

                    // Add text to en.json if not exists
                    if (!isset($translations[$text])) {
                        $translations[$text] = $text;
                    }

                    return '> {{ __(\'' . addslashes($text) . '\') }} <';
                },
                $content,
            );

            if ($newContent !== $content) {
                File::put($file->getRealPath(), $newContent);
                $this->info('Updated: ' . $file->getFilename());
                $count++;
            }
        }

        // Save updated en.json
        File::put($langFile, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("✅ {$count} blade files updated successfully!");
        $this->info('✅ en.json updated with new words.');
    }
}
