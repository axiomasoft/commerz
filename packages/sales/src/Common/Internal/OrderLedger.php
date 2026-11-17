<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\LineNotFound;
use Commerz\Sales\Common\Exceptions\QuantityExceedsOutstanding;
use Commerz\Sales\Orders\Data\OrderId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;

/**
 * The only writer of the ledger of the order lines (ORD-03): `cancelled_quantity`, `fulfilled_quantity` and
 * `returned_quantity`. Each change is one conditional update, so no two changes can take the same units even without
 * the lock of the order; the CHECK constraints of `order_lines` are the second line. Called under the lock of the order.
 */
#[Internal]
final readonly class OrderLedger
{
    /** Units neither cancelled nor fulfilled. */
    private const string OUTSTANDING = 'quantity - cancelled_quantity - fulfilled_quantity';

    /** Fulfilled units not returned. */
    private const string RETURNABLE = 'fulfilled_quantity - returned_quantity';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /** @throws LineNotFound|QuantityExceedsOutstanding */
    public function cancel(OrderId $order, LineQuantity $line): void
    {
        $this->add($order, $line, 'cancelled_quantity', self::OUTSTANDING);
    }

    /** @throws LineNotFound|QuantityExceedsOutstanding */
    public function fulfill(OrderId $order, LineQuantity $line): void
    {
        $this->add($order, $line, 'fulfilled_quantity', self::OUTSTANDING);
    }

    /** @throws LineNotFound|QuantityExceedsOutstanding */
    public function returnReceived(OrderId $order, LineQuantity $line): void
    {
        $this->add($order, $line, 'returned_quantity', self::RETURNABLE);
    }

    /** @param  self::OUTSTANDING|self::RETURNABLE  $available */
    private function add(OrderId $order, LineQuantity $line, string $column, string $available): void
    {
        $lines = $this->db()->table($this->tables->table('order_lines'))
            ->where('id', $line->lineId->value)
            ->where('order_id', $order->value);

        if ((clone $lines)->whereRaw("{$available} >= ?", [$line->quantity])->increment($column, $line->quantity) === 1) {
            return;
        }

        $left = $lines->selectRaw("{$available} as available")->first();

        if ($left === null) {
            throw new LineNotFound($line->lineId->value);
        }

        throw new QuantityExceedsOutstanding($line->lineId->value, $line->quantity, Rows::int($left, 'available'));
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
