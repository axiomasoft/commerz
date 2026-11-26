<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Privacy\Events\CustomerPersonalDataErased;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Common\Internal\CartLines;
use Commerz\Sales\Common\Internal\CartRows;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderRows;
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
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\StoreId;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

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
        private CommittedEvents $events,
        private Clock $clock,
    ) {}

    public function draft(DraftOrder $command): CartView
    {
        // Lines of the same sellable add up, as `Carts::addLine` does to an existing line.
        $quantities = [];
        $sellables = [];
        $sellerRefs = [];

        foreach ($command->lines as $line) {
            $key = (string) $line->sellable;
            $quantities[$key] = ($quantities[$key] ?? 0) + $line->quantity;
            $sellables[$key] = $line->sellable;
            $sellerRefs[$key] ??= $line->sellerRef;
        }

        // Sellables are read before the transaction, as for every cart change.
        $snapshots = $sellables !== []
            ? $this->cartLines->assertAllPurchasable($command->context, array_values($sellables))
            : [];

        return $this->transactions->run(function () use ($command, $quantities, $sellables, $sellerRefs, $snapshots): CartView {
            $this->channels->assertActive($command->context->channel, $command->context->currency);

            $id = $this->carts->insert($command->context, CartKind::Draft, $command->actor, [
                'codes' => SalesCodec::stringsToJson($command->codes),
                'shipping_address' => $command->shippingAddress === null ? null : SalesCodec::addressToJson($command->shippingAddress),
                'billing_address' => $command->billingAddress === null ? null : SalesCodec::addressToJson($command->billingAddress),
                'delivery_method' => $command->deliveryMethod,
            ]);

            foreach ($quantities as $key => $quantity) {
                $this->cartLines->add($this->carts->get($id), $sellables[$key], $quantity, $snapshots[$key]->sellerRef ?? $sellerRefs[$key]);
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

    public function erasePersonalData(CustomerRef $customer): int
    {
        return $this->transactions->run(function () use ($customer): int {
            $customerRef = (string) $customer;
            $now = PgTimestamp::now($this->clock);
            $ordersTable = $this->tables->table('orders');
            $cartsTable = $this->tables->table('carts');

            $orderRows = $this->db()->table($ordersTable)
                ->where('customer_ref', $customerRef)
                ->get(['id', 'shipping_address', 'billing_address'])
                ->all();

            $orderIds = [];

            foreach ($orderRows as $row) {
                $id = Rows::string($row, 'id');
                $orderIds[] = $id;
                $shipping = Rows::nullableString($row, 'shipping_address');
                $billing = Rows::nullableString($row, 'billing_address');

                $this->db()->table($ordersTable)
                    ->where('id', $id)
                    ->update([
                        'customer_email' => null,
                        'customer_name' => null,
                        'shipping_address' => self::maskAddressJson($shipping),
                        'billing_address' => self::maskAddressJson($billing),
                        'updated_at' => $now,
                    ]);
            }

            $cartRows = $this->db()->table($cartsTable)
                ->where('customer_ref', $customerRef)
                ->get(['id', 'shipping_address', 'billing_address'])
                ->all();

            foreach ($cartRows as $row) {
                $id = Rows::string($row, 'id');
                $shipping = Rows::nullableString($row, 'shipping_address');
                $billing = Rows::nullableString($row, 'billing_address');

                if ($shipping === null && $billing === null) {
                    continue;
                }

                $this->db()->table($cartsTable)
                    ->where('id', $id)
                    ->update([
                        'shipping_address' => self::maskAddressJson($shipping),
                        'billing_address' => self::maskAddressJson($billing),
                        'updated_at' => $now,
                    ]);
            }

            $bookingRefs = array_map(static fn (string $id): string => 'order:'.$id, $orderIds);

            if ($orderIds !== []) {
                foreach ($this->db()->table($this->tables->table('fulfillments'))
                    ->whereIn('order_id', $orderIds)
                    ->pluck('id') as $fulfillmentId) {
                    if (is_string($fulfillmentId)) {
                        $bookingRefs[] = 'fulfillment:'.$fulfillmentId;
                    }
                }
            }

            $this->events->dispatch(new CustomerPersonalDataErased($customer, $bookingRefs));

            return count($orderRows);
        });
    }

    private static function maskAddressJson(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $address = SalesCodec::addressFromJson($json);

        return SalesCodec::addressToJson(new Address(
            country: $address->country,
            region: $address->region,
        ));
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
