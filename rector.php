<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Rector\CodeQuality\Rector\Catch_\ThrowWithPreviousExceptionRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php80\Rector\Ternary\TernaryToNullsafeCoalesceRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\Class_\ReadOnlyAnonymousClassRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;
use RectorLaravel\Set\LaravelSetList;

return static function (RectorConfig $rectorConfig): void {
    $srcPaths = array_values(array_filter(glob(__DIR__.'/packages/*/src') ?: [], 'is_dir'));
    $migrationPaths = array_values(array_filter(glob(__DIR__.'/packages/*/database/migrations') ?: [], 'is_dir'));
    $testPaths = array_values(array_filter(glob(__DIR__.'/packages/*/tests') ?: [], 'is_dir'));

    $rectorConfig->disableParallel();
    $rectorConfig->paths([...$srcPaths, ...$migrationPaths, ...$testPaths]);
    $rectorConfig->importNames(importDocBlockNames: false);
    $rectorConfig->removeUnusedImports();
    $rectorConfig->sets([LaravelSetList::LARAVEL_130]);

    PackmanRectorSet::configure($rectorConfig, [
        // `$x !== null ? $x->y : $fallback` is not `$x->y ?? $fallback` when y itself is null.
        TernaryToNullsafeCoalesceRector::class,
        ReadOnlyClassRector::class,
        ReadOnlyPropertyRector::class,
        ReadOnlyAnonymousClassRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
        ExplicitNullableObjectInstanceRector::class,
        RemoveEmptyClassMethodRector::class,
        RemoveUnusedPublicMethodParameterRector::class,
        NarrowObjectReturnTypeRector::class,
        ThrowWithPreviousExceptionRector::class,
        SimplifyBoolIdenticalTrueRector::class,
        AddArrowFunctionReturnTypeRector::class => [
            __DIR__.'/packages/*/tests/*',
        ],
    ]);
};
