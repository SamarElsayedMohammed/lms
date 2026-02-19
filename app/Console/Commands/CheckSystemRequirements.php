<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SystemRequirementsService;
use Illuminate\Console\Command;

final class CheckSystemRequirements extends Command
{
    protected $signature = 'system:requirements';

    protected $description = 'Check system requirements for eLMS installation';

    public function handle(): int
    {
        $this->info('Checking System Requirements...');
        $this->newLine();

        $requirements = SystemRequirementsService::check();

        // Display Core Requirements
        $this->comment('═══════════════════════════════════════════════════════');
        $this->comment('  CORE REQUIREMENTS (Required for basic functionality)');
        $this->comment('═══════════════════════════════════════════════════════');
        $this->newLine();

        $coreTable = [];
        foreach ($requirements['core'] as $req) {
            $coreTable[] = [
                $req['name'],
                $req['passed'] ? '<fg=green>✓ Passed</>' : '<fg=red>✗ Failed</>',
                $req['message'],
            ];
        }

        $this->table(['Requirement', 'Status', 'Details'], $coreTable);

        // Display Optional Requirements
        $this->newLine();
        $this->comment('═══════════════════════════════════════════════════════');
        $this->comment('  OPTIONAL FEATURES (Enhanced video security)');
        $this->comment('═══════════════════════════════════════════════════════');
        $this->newLine();

        $optionalTable = [];
        foreach ($requirements['optional'] as $req) {
            $optionalTable[] = [
                $req['name'],
                $req['passed'] ? '<fg=green>✓ Available</>' : '<fg=yellow>✗ Missing</>',
                $req['message'],
                $req['impact'],
            ];
        }

        $this->table(['Feature', 'Status', 'Details', 'Impact if Missing'], $optionalTable);

        // Summary
        $summary = SystemRequirementsService::getSummary();

        $this->newLine();
        $this->comment('═══════════════════════════════════════════════════════');
        $this->comment('  SUMMARY');
        $this->comment('═══════════════════════════════════════════════════════');
        $this->newLine();

        if ($summary['core_passed']) {
            $this->info('✓ All core requirements are met!');
            $this->info('  Your system is ready to run eLMS.');
        } else {
            $this->error('✗ ' . $summary['core_failed_count'] . ' core requirement(s) failed!');
            $this->error('  Please install missing requirements before proceeding.');
        }

        $this->newLine();

        if ($summary['optional_passed']) {
            $this->info('✓ All optional features are available!');
            $this->info('  HLS video encoding is fully supported.');
        } else {
            $this->warn('⚠ ' . $summary['optional_failed_count'] . ' optional feature(s) missing.');
            $this->warn('  Core functionality will work, but HLS video encoding is not available.');
            $this->newLine();
            $this->info('To enable HLS video encoding:');
            $this->line('  Run: php artisan ffmpeg:status');
            $this->line('  This will show detailed instructions for enabling missing features.');
        }

        $this->newLine();

        return $summary['core_passed'] ? self::SUCCESS : self::FAILURE;
    }
}
