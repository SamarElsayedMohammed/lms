<?php

namespace App\Console\Commands;

use App\Models\Language;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Stichoza\GoogleTranslate\GoogleTranslate;

class CustomTranslateMissing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'custom:translate-missing {type} {locale}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Translate missing keys in a specific JSON file based on the provided type and locale.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Increase execution time limit
        set_time_limit(300); // 5 minutes

        $type = $this->argument('type');
        $locale = $this->argument('locale');

        $base = config('auto-translate.base_locale', 'en');

        // Find the language record by locale
        $language = Language::where('code', $locale)->first();
        if (!$language) {
            $this->error("Language with code '{$locale}' not found in database.");
            return Command::FAILURE;
        }

        $fileName = match ($type) {
            'web' => 'en_web.json',
            'panel' => 'en_original.json',
            'app' => 'en_app.json',
            default => $this->error('Invalid type specified.') && exit(Command::FAILURE),
        };

        $baseFilePath = base_path('resources/lang/' . $fileName);
        $localeFilePath = match ($type) {
            'web' => base_path('resources/lang/' . $locale . '_web.json'),
            'panel' => base_path('resources/lang/' . $locale . '.json'),
            'app' => base_path('resources/lang/' . $locale . '_app.json'),
            default => $this->error('Invalid type specified.') && exit(Command::FAILURE),
        };

        if (!File::exists($baseFilePath)) {
            $this->error("Base file '{$baseFilePath}' not found.");
            return Command::FAILURE;
        }

        $baseTranslations = json_decode(File::get($baseFilePath), true);
        $localeTranslations = File::exists($localeFilePath) ? json_decode(File::get($localeFilePath), true) : [];

        // Find missing translations (empty, missing, or still in English)
        $missingTranslations = [];
        foreach ($baseTranslations as $key => $englishValue) {
            $currentValue = isset($localeTranslations[$key]) ? trim((string) $localeTranslations[$key]) : '';

            // Translate if: empty, missing, or still contains the original English value
            if (empty($currentValue) || $currentValue === $englishValue) {
                $missingTranslations[$key] = $englishValue;
            }
        }

        if (empty($missingTranslations)) {
            $this->info("No missing translations found for type '{$type}' and locale '{$locale}'.");
            return Command::SUCCESS;
        }

        $this->info('Found ' . count($missingTranslations) . ' missing translations. Starting translation...');

        $translator = new GoogleTranslate();
        $translator->setSource($base);
        $translator->setTarget($locale);

        $translatedCount = 0;
        $batchSize = 10; // Process in smaller batches
        $missingKeys = array_keys($missingTranslations);
        $totalBatches = ceil(count($missingKeys) / $batchSize);

        for ($batch = 0; $batch < $totalBatches; $batch++) {
            $batchKeys = array_slice($missingKeys, $batch * $batchSize, $batchSize);
            $this->info('Processing batch '
            . ($batch + 1)
            . ' of '
            . $totalBatches
            . ' ('
            . count($batchKeys)
            . ' items)');

            foreach ($batchKeys as $key) {
                $baseTranslation = $missingTranslations[$key];
                try {
                    // Add timeout and retry logic
                    $translatedText = $this->translateWithRetry($translator, $baseTranslation, 3);
                    $localeTranslations[$key] = $translatedText;
                    $translatedCount++;
                } catch (\Exception $e) {
                    $this->error('Error translating "' . $key . '": ' . $e->getMessage());
                    $localeTranslations[$key] = $baseTranslation; // Keep original if translation fails
                }

                // Small delay between translations to avoid rate limiting
                usleep(100000); // 0.1 second delay
            }

            // Save progress after each batch
            File::put($localeFilePath, json_encode($localeTranslations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->info('Batch ' . ($batch + 1) . ' completed. Total translated: ' . $translatedCount);
        }

        File::put($localeFilePath, json_encode($localeTranslations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // Update database with the correct file name
        $fileName = basename($localeFilePath);
        if ($type == 'panel') {
            $language->panel_file = $fileName;
        } elseif ($type == 'web') {
            $language->web_file = $fileName;
        } elseif ($type == 'app') {
            $language->app_file = $fileName;
        }
        $language->save();

        $this->info(
            "Translation completed successfully. {$translatedCount} strings translated and saved as {$fileName}.",
        );
        $this->info("Database updated: {$type}_file = {$fileName}");
        return Command::SUCCESS;
    }

    /**
     * Translate with retry logic
     */
    private function translateWithRetry($translator, $text, $maxRetries = 3)
    {
        $attempt = 0;
        while ($attempt < $maxRetries) {
            try {
                return $translator->translate($text);
            } catch (\Exception $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                $this->warn("Translation attempt {$attempt} failed, retrying... ({$e->getMessage()})");
                sleep(1); // Wait 1 second before retry
            }
        }
    }
}
