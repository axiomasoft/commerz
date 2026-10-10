<?php

declare(strict_types=1);

// The closed bypasses of sales-design#locks for fulfillments: the documents are written only by FulfillmentRows, the
// carrier is asked only by FulfillmentBooking (outside any transaction), and no fulfillment is created without the
// ledger moving before the stock does.

/** @return array<string, string> path under src => code */
function fgSources(): array
{
    $src = dirname(__DIR__, 3).'/src';
    $sources = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $sources[substr($file->getPathname(), strlen($src) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($sources);

    return $sources;
}

/** Whether a statement of `$code` writes the tables of the fulfillment documents. */
function fgWritesFulfillments(string $code): bool
{
    return array_any(
        explode(';', $code),
        static fn (string $statement): bool => preg_match('/->(update|increment|decrement|upsert|insert)\w*\(|\b(update|insert\s+into)\s+\S+/i', $statement) === 1
            && preg_match("/table\\('fulfillments?(_lines)?'\\)|\\bcmz_fulfillment(s|_lines)\\b/", $statement) === 1,
    );
}

it('leaves the writes of the fulfillment documents to FulfillmentRows', function (): void {
    expect(array_keys(array_filter(fgSources(), fgWritesFulfillments(...))))->toBe(['Fulfillments/Internal/FulfillmentRows.php']);
});

it('recognises a write of the fulfillments however it is made', function (string $code, bool $writes): void {
    expect(fgWritesFulfillments($code))->toBe($writes);
})->with([
    'update' => ["\$db->table(\$this->tables->table('fulfillments'))->where('id', \$id)->update(['status' => 'shipped']);", true],
    'insert of lines' => ["\$db->table(\$this->tables->table('fulfillment_lines'))->insert([]);", true],
    'raw sql' => ['$db->statement("update cmz_fulfillments set status = \'delivered\'");', true],
    'read' => ["\$db->table(\$this->tables->table('fulfillments'))->where('order_id', \$id)->exists();", false],
    'another table' => ["\$db->table(\$this->tables->table('orders'))->update(['state' => 'placed']);", false],
]);

it('lets only FulfillmentBooking ask the carrier', function (): void {
    $users = array_keys(array_filter(
        fgSources(),
        static fn (string $code): bool => str_contains($code, 'use Commerz\Ports\Delivery\Contracts\ShipmentBooking;'),
    ));

    expect($users)->toBe(['Fulfillments/Internal/FulfillmentBooking.php']);
});

it('moves the ledger of the lines before it consumes the stock', function (): void {
    $code = fgSources()['Fulfillments/Internal/DatabaseFulfillments.php'];
    $ledger = strpos($code, '$this->ledger->fulfill(');
    $stock = strpos($code, '$this->stock->consume(');

    expect($ledger)->toBeInt()
        ->and($stock)->toBeInt()
        ->and($ledger < $stock)->toBeTrue();
});

it('calls the carrier only after refusing to run inside a transaction', function (): void {
    $code = fgSources()['Fulfillments/Internal/DatabaseFulfillments.php'];
    $guard = strpos($code, 'OperationInsideTransaction(');
    $send = strpos($code, '$this->booking->send(');

    expect($guard)->toBeInt()
        ->and($send)->toBeInt()
        ->and($guard < $send)->toBeTrue();
});
