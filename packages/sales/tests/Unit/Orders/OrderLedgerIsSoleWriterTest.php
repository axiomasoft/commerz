<?php

declare(strict_types=1);

// ORD-03: the ledger of the order lines changes only through Common/Internal/OrderLedger's conditional updates, so no
// module moves `cancelled_quantity`, `fulfilled_quantity` or `returned_quantity` past the outstanding units.

/** Whether a statement of `$code` writes one of the ledger counters. */
function olwWritesLedger(string $code): bool
{
    return array_any(
        explode(';', $code),
        static fn (string $statement): bool => preg_match('/->(update|increment|decrement|upsert|insert)\w*\(|\bupdate\s+\S+\s+set\b/i', $statement) === 1
            && preg_match('/\b(cancelled|fulfilled|returned)_quantity\b/', $statement) === 1,
    );
}

it('leaves the counters of the order lines to OrderLedger alone', function (): void {
    $src = dirname(__DIR__, 3).'/src';
    $writers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }

        $path = substr($file->getPathname(), strlen($src) + 1);

        if ($file->getExtension() === 'php' && $path !== 'Common/Internal/OrderLedger.php' && olwWritesLedger((string) file_get_contents($file->getPathname()))) {
            $writers[] = $path;
        }
    }

    expect($writers)->toBe([]);
});

it('recognises a write of a counter however it is made', function (string $code, bool $writes): void {
    expect(olwWritesLedger($code))->toBe($writes);
})->with([
    'update' => ["\$db->table(\$lines)->where('id', \$id)->update(['fulfilled_quantity' => 2]);", true],
    'increment' => ["\$db->table(\$lines)->increment('cancelled_quantity', 1);", true],
    'raw sql' => ['$db->statement("update cmz_order_lines set returned_quantity = returned_quantity + 1");', true],
    'read' => ["Rows::int(\$line, 'cancelled_quantity');", false],
    'another column' => ["\$db->table(\$orders)->update(['state' => 'cancelled']);", false],
]);
