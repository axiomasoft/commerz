<?php

declare(strict_types=1);

use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Refunds\Internal\RefundJournal;

// The closed bypasses of sales-design#locks for refunds: the refunds are written only by RefundJournal (fenced, under
// the lock of the order), an event moves a refund only after its claim in the inbox (PAY-01), and a refund moves only
// forward: succeeded is final, and failed takes nothing but a later success.

/** @return array<string, string> path under src => code */
function rgSources(): array
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

it('leaves the writes of the refunds to RefundJournal', function (): void {
    $writers = array_keys(array_filter(rgSources(), static fn (string $code): bool => array_any(
        explode(';', $code),
        static fn (string $statement): bool => preg_match('/->(update|increment|decrement|upsert|insert)\w*\(|\b(update|insert\s+into)\s+\S+/i', $statement) === 1
            && preg_match("/table\\('refunds'\\)|\\bcmz_refunds\\b/", $statement) === 1,
    )));

    expect($writers)->toBe(['Refunds/Internal/RefundJournal.php']);
});

it('moves a refund on an event only after its claim in the inbox', function (): void {
    $code = rgSources()['Refunds/Internal/DatabaseRefunds.php'];
    $claim = strpos($code, '$this->inbox->claim(');
    $write = strpos($code, '$this->journal->write(');

    expect($claim)->toBeInt()
        ->and($write)->toBeInt()
        ->and($claim < $write)->toBeTrue();
});

it('moves a refund only forward', function (RefundStatus $current, RefundStatus $observed, ?RefundStatus $next): void {
    expect(RefundJournal::transition($current, $observed))->toBe($next);
})->with([
    'initiating → succeeded' => [RefundStatus::Initiating, RefundStatus::Succeeded, RefundStatus::Succeeded],
    'initiating → failed' => [RefundStatus::Initiating, RefundStatus::Failed, RefundStatus::Failed],
    'initiating → pending' => [RefundStatus::Initiating, RefundStatus::Pending, RefundStatus::Pending],
    'initiating → unknown' => [RefundStatus::Initiating, RefundStatus::Unknown, RefundStatus::Unknown],
    'pending → succeeded' => [RefundStatus::Pending, RefundStatus::Succeeded, RefundStatus::Succeeded],
    'pending → failed' => [RefundStatus::Pending, RefundStatus::Failed, RefundStatus::Failed],
    'pending stays on pending' => [RefundStatus::Pending, RefundStatus::Pending, null],
    'pending does not become unknown' => [RefundStatus::Pending, RefundStatus::Unknown, null],
    'unknown → pending' => [RefundStatus::Unknown, RefundStatus::Pending, RefundStatus::Pending],
    'unknown → succeeded' => [RefundStatus::Unknown, RefundStatus::Succeeded, RefundStatus::Succeeded],
    'unknown → failed' => [RefundStatus::Unknown, RefundStatus::Failed, RefundStatus::Failed],
    'succeeded is final' => [RefundStatus::Succeeded, RefundStatus::Failed, null],
    'succeeded ignores pending' => [RefundStatus::Succeeded, RefundStatus::Pending, null],
    'failed takes a later success' => [RefundStatus::Failed, RefundStatus::Succeeded, RefundStatus::Succeeded],
    'failed ignores pending' => [RefundStatus::Failed, RefundStatus::Pending, null],
    'failed does not become unknown' => [RefundStatus::Failed, RefundStatus::Unknown, null],
    'nothing goes back to initiating' => [RefundStatus::Pending, RefundStatus::Initiating, null],
]);
