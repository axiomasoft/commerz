<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/packages/core/src',
        __DIR__.'/packages/catalog/src',
        __DIR__.'/packages/inventory/src',
        __DIR__.'/packages/pricing/src',
        __DIR__.'/packages/promotions/src',
        __DIR__.'/packages/shipping/src',
        __DIR__.'/packages/sales/src',
        __DIR__.'/packages/testkit/src',
        __DIR__.'/packages/core/tests',
        __DIR__.'/packages/catalog/tests',
        __DIR__.'/packages/inventory/tests',
        __DIR__.'/packages/pricing/tests',
        __DIR__.'/packages/promotions/tests',
        __DIR__.'/packages/shipping/tests',
        __DIR__.'/packages/sales/tests',
        __DIR__.'/packages/testkit/tests',
    ])
    ->withPhpSets(php84: true)
    ->withSets([LaravelSetList::LARAVEL_130]);
