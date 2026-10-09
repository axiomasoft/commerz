<?php

declare(strict_types=1);

// INV-03: only the ledger writes levels and movements, so only it — the unlocked reader LevelQueries and the
// reconciler (P3.7) besides — may name their tables.

it('names the level and movement tables only in the ledger, its reader and the reconciler', function (): void {
    $src = dirname(__DIR__, 2).'/src';
    $allowed = ['Common/Internal/StockLedger.php', 'Common/Internal/LevelQueries.php', 'Internal/Reconciler.php'];
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($src) + 1);
        $code = (string) file_get_contents($file->getPathname());

        if (! in_array($relative, $allowed, true) && preg_match('/[\'"]stock_(levels|movements)[\'"]/', $code) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([])
        ->and(file_exists($src.'/Common/Internal/StockLedger.php'))->toBeTrue();
});
