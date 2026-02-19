<?php

/**
 * Test Runner for LMS Project
 *
 * This script runs all tests and generates coverage reports
 */

namespace Tests;

require_once __DIR__ . '/../vendor/autoload.php';

class TestRunner
{
    public function runAllTests()
    {
        echo "🚀 Starting LMS Project Test Suite\n";
        echo "=====================================\n\n";

        // Run Unit Tests
        echo "📋 Running Unit Tests...\n";
        $this->runCommand('php vendor/bin/phpunit tests/Unit --coverage-html tests/coverage/unit');

        // Run Feature Tests
        echo "\n📋 Running Feature Tests...\n";
        $this->runCommand('php vendor/bin/phpunit tests/Feature --coverage-html tests/coverage/feature');

        // Run All Tests with Coverage
        echo "\n📋 Running All Tests with Coverage...\n";
        $this->runCommand(
            'php vendor/bin/phpunit --coverage-html tests/coverage/full --coverage-text=tests/coverage.txt',
        );

        echo "\n✅ Test Suite Complete!\n";
        echo "📊 Coverage reports generated in tests/coverage/\n";
    }

    private function runCommand($command)
    {
        $output = [];
        $returnCode = 0;

        exec($command, $output, $returnCode);

        foreach ($output as $line) {
            echo $line . "\n";
        }

        if ($returnCode !== 0) {
            echo "❌ Command failed with return code: $returnCode\n";
        }
    }
}

// Run the test suite
$runner = new TestRunner();
$runner->runAllTests();
