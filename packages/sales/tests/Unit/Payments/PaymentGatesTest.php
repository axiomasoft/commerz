<?php

declare(strict_types=1);

// The closed bypasses of sales-design#locks: the payments are written only by PaymentJournal (fenced, under the lock
// of the order), the gateway is called only by PaymentWorkflow (outside any transaction, PAY-02), and an event moves a
// payment only after its claim in the inbox (PAY-01).

/** @return array<string, string> path under src => code */
function pgSources(): array
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

/** Whether a statement of `$code` writes the payments table. */
function pgWritesPayments(string $code): bool
{
    return array_any(
        explode(';', $code),
        static fn (string $statement): bool => preg_match('/->(update|increment|decrement|upsert|insert)\w*\(|\b(update|insert\s+into)\s+\S+/i', $statement) === 1
            && preg_match("/table\\('payments'\\)|\\bcmz_payments\\b/", $statement) === 1,
    );
}

it('leaves the writes of the payments to PaymentJournal', function (): void {
    $writers = array_keys(array_filter(pgSources(), pgWritesPayments(...)));

    expect($writers)->toBe(['Payments/Internal/PaymentJournal.php']);
});

it('recognises a write of the payments however it is made', function (string $code, bool $writes): void {
    expect(pgWritesPayments($code))->toBe($writes);
})->with([
    'update' => ["\$db->table(\$this->tables->table('payments'))->where('id', \$id)->update(['status' => 'captured']);", true],
    'insert' => ["\$db->table(\$this->tables->table('payments'))->insert([]);", true],
    'raw sql' => ['$db->statement("update cmz_payments set status = \'failed\'");', true],
    'read' => ["\$db->table(\$this->tables->table('payments'))->where('order_id', \$id)->exists();", false],
]);

it('lets only PaymentWorkflow call the gateway of a payment', function (): void {
    $users = array_keys(array_filter(
        pgSources(),
        static fn (string $code, string $path): bool => ! str_starts_with($path, 'Gateways/')
            && str_contains($code, 'use Commerz\Sales\Gateways\Contracts\PaymentGateway;'),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($users)->toBe(['Internal/SalesPlugin.php', 'Payments/Internal/PaymentWorkflow.php']);
});

it('moves a payment on an event only after its claim in the inbox', function (): void {
    $code = pgSources()['Payments/Internal/DatabasePayments.php'];
    $claim = strpos($code, '$this->inbox->claim(');
    $apply = strpos($code, 'PaymentTransitions::apply(');

    expect($claim)->toBeInt()
        ->and($apply)->toBeInt()
        ->and($claim < $apply)->toBeTrue()
        ->and(array_keys(array_filter(pgSources(), static fn (string $code): bool => str_contains($code, 'PaymentTransitions::apply('))))
        ->toBe(['Payments/Internal/DatabasePayments.php', 'Payments/Internal/PaymentWorkflow.php']);
});
