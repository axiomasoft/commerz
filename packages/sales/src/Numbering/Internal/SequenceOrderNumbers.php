<?php

declare(strict_types=1);

namespace Commerz\Sales\Numbering\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Sales\Common\Internal\Rows;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Commerz\Sales\Numbering\Data\OrderNumbering;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * One database sequence for all stores; the visible number is the store's prefix plus the next value. A rolled back
 * placement leaves a gap, which is fine: an order number is not an invoice number.
 */
#[Internal]
final readonly class SequenceOrderNumbers implements OrderNumberGenerator
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    public function key(): string
    {
        return 'sequence';
    }

    public function next(OrderNumbering $numbering): string
    {
        $row = $this->connections->connection($this->config->connection)->selectOne(
            'select nextval(?::regclass) as n',
            [$this->tables->table('order_number_seq')],
            useReadPdo: false,
        );

        if (! is_object($row)) {
            throw new RuntimeException('The order number sequence returned no value.');
        }

        return $numbering->store->orderNumberPrefix.Rows::int($row, 'n');
    }
}
