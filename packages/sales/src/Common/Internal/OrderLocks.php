<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;

/**
 * The lock every mutation of an order starts with: the order row `for update`, the resource class "order" of the
 * global lock order (sales-design#locks). Its lines, documents and payments are changed only under it.
 */
#[Internal]
final readonly class OrderLocks
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /** Waits for the order. @throws OrderNotFound */
    public function lock(OrderId $id): OrderRow
    {
        $row = $this->db()->table($this->tables->table('orders'))->where('id', $id->value)->lockForUpdate()->first();

        return $this->hydrate($row ?? throw new OrderNotFound($id->value));
    }

    /** Takes the order unless another transaction holds it (`skip locked`): null then, or when there is no such order. */
    public function lockSkip(OrderId $id): ?OrderRow
    {
        $row = $this->db()->table($this->tables->table('orders'))->where('id', $id->value)->lock('for update skip locked')->first();

        return $row === null ? null : $this->hydrate($row);
    }

    private function hydrate(object $row): OrderRow
    {
        $id = Rows::string($row, 'id');
        $due = Rows::nullableString($row, 'payment_due_at');
        $lines = [];

        foreach ($this->db()->table($this->tables->table('order_lines'))->where('order_id', $id)->orderBy('position')->get() as $line) {
            $lines[] = new OrderLineRow(
                new OrderLineId(Rows::string($line, 'id')),
                SellableRef::fromString(Rows::string($line, 'sellable_ref')),
                SellableKind::from(Rows::string($line, 'kind')),
                Rows::int($line, 'quantity'),
                Rows::int($line, 'cancelled_quantity'),
                Rows::int($line, 'fulfilled_quantity'),
                Rows::int($line, 'returned_quantity'),
            );
        }

        return new OrderRow(
            new OrderId($id),
            Rows::string($row, 'number'),
            OrderState::from(Rows::string($row, 'state')),
            PaymentState::from(Rows::string($row, 'payment_state')),
            Currency::of(Rows::string($row, 'currency')),
            $due === null ? null : new DateTimeImmutable($due),
            $lines,
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
