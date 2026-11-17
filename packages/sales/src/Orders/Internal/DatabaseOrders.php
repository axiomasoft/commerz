<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Common\Internal\CartLines;
use Commerz\Sales\Common\Internal\CartRows;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderRows;
use Commerz\Sales\Common\Internal\Rows;
use Commerz\Sales\Common\Internal\SalesCodec;
use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Orders\Data\CancelOrder;
use Commerz\Sales\Orders\Data\CancelOrderLines;
use Commerz\Sales\Orders\Data\DraftOrder;
use Commerz\Sales\Orders\Data\ExpireOrders;
use Commerz\Sales\Orders\Data\ExpireReport;
use Commerz\Sales\Orders\Data\ListOrders;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\StoreId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;

#[Internal]
final readonly class DatabaseOrders implements Orders
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Channels $channels,
        private CartRows $carts,
        private CartLines $cartLines,
        private OrderRows $rows,
        private OrderJournal $journal,
        private OrderCancellation $cancellation,
        private OrderExpiry $expiry,
    ) {}

    public function draft(DraftOrder $command): CartView
    {
        // Lines of the same sellable add up, as `Carts::addLine` does to an existing line.
        $quantities = [];
        $sellables = [];

        foreach ($command->lines as $line) {
            $key = (string) $line->sellable;
            $quantities[$key] = ($quantities[$key] ?? 0) + $line->quantity;
            $sellables[$key] = $line->sellable;
        }

        // Sellables are read before the transaction, as for every cart change.
        if ($sellables !== []) {
            $this->cartLines->assertAllPurchasable($command->context, array_values($sellables));
        }

        return $this->transactions->run(function () use ($command, $quantities, $sellables): CartView {
            $this->channels->assertActive($command->context->channel, $command->context->currency);

            $id = $this->carts->insert($command->context, CartKind::Draft, $command->actor, [
                'codes' => SalesCodec::stringsToJson($command->codes),
                'shipping_address' => $command->shippingAddress === null ? null : SalesCodec::addressToJson($command->shippingAddress),
                'billing_address' => $command->billingAddress === null ? null : SalesCodec::addressToJson($command->billingAddress),
                'delivery_method' => $command->deliveryMethod,
            ]);

            foreach ($quantities as $key => $quantity) {
                $this->cartLines->add($this->carts->get($id), $sellables[$key], $quantity);
            }

            return $this->carts->view($this->carts->get($id));
        });
    }

    public function find(OrderId $id): OrderView
    {
        return $this->rows->get($id->value);
    }

    public function findByNumber(StoreId $store, string $number): OrderView
    {
        return $this->rows->findByNumber($store, $number) ?? throw new OrderNotFound($number);
    }

    public function list(ListOrders $query): Page
    {
        $builder = $this->db()->table($this->tables->table('orders'))
            ->select('*')
            ->selectRaw('placed_at::text as placed_position')
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1);

        if ($query->channel !== null) {
            $builder->where('channel_id', $query->channel->value);
        }

        if ($query->customer !== null) {
            $builder->where('customer_ref', (string) $query->customer);
        }

        if ($query->state !== null) {
            $builder->where('state', $query->state->value);
        }

        if ($query->paymentState !== null) {
            $builder->where('payment_state', $query->paymentState->value);
        }

        if ($query->fulfillmentState !== null) {
            $builder->where('fulfillment_state', $query->fulfillmentState->value);
        }

        if ($query->after instanceof Cursor) {
            self::after($builder, $query->after);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new Page(
            $this->rows->hydrate($rows),
            $more && $last !== null ? Cursor::after(Rows::string($last, 'placed_position').'|'.Rows::string($last, 'id')) : null,
        );
    }

    public function history(OrderId $id): array
    {
        $this->rows->get($id->value);

        return $this->journal->history($id);
    }

    public function cancel(CancelOrder $command): OrderView
    {
        return $this->cancellation->cancel($command);
    }

    public function cancelLines(CancelOrderLines $command): OrderView
    {
        return $this->cancellation->cancelLines($command);
    }

    public function expireDue(ExpireOrders $command): ExpireReport
    {
        return $this->expiry->expireDue($command);
    }

    /** The keyset is (placed_at, id), newest first; the timestamp is PostgreSQL's own text of it, so no precision is lost. */
    private static function after(Builder $builder, Cursor $cursor): void
    {
        $parts = explode('|', $cursor->position());

        if (count($parts) !== 2
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2}){0,2}\z/', $parts[0]) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $parts[1]) !== 1) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        $builder->whereRaw('(placed_at, id) < (?::timestamptz, ?::uuid)', $parts);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
