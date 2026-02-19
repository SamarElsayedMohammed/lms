<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/bootstrap',
        __DIR__ . '/config',
        __DIR__ . '/lang',
        __DIR__ . '/public',
        __DIR__ . '/resources',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->withBootstrapFiles([
        __DIR__ . '/config/constants.php',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        typeDeclarations: true,
        codeQuality: true,
        naming: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withSkip([
        __DIR__ . '/vendor',
    ])
    ->withCache()
    ->withParallel(
        timeoutSeconds: 600,
        maxNumberOfProcess: 8,
        jobSize: 20,
    )
    ->withDeadCodeLevel(0);
