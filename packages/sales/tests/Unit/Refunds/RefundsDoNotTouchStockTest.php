<?php

declare(strict_types=1);

// REF-01: money goes back without the goods. No file of the refunds module refers to the stock ports, so a refund can
// neither restock nor release anything; goods come back only through Returns::receive with an explicit restock.

it('keeps every file of the refunds module away from the stock ports', function (): void {
    $src = dirname(__DIR__, 3).'/src/Refunds';
    $files = [];
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $files[] = $file->getPathname();

        if (preg_match('/Commerz\\\\Ports\\\\Stock\b|\bStock(Returns|Reservations)\b/', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = substr($file->getPathname(), strlen($src) + 1);
        }
    }

    expect($files)->not->toBeEmpty()
        ->and($offenders)->toBe([]);
});
