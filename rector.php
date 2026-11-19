<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/demo',
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/src/Doctrine/AuditableEntityListener.php',
        ],
        // is_callable([$connection, 'getServerVersion']) probes a method that is private on doctrine/dbal 3: a
        // first-class callable of it would throw there instead of answering false.
        ArrayToFirstClassCallableRector::class => [
            __DIR__ . '/src/Doctrine/ExactCollation.php',
        ],
    ])
    // Machine-wide cap: at most 2 worker processes; generous timeout for slow (WSL2 / CI) hosts.
    ->withParallel(300, 2, 10)
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
