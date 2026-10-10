<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Data\QuoteTotals;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Data\OrderLineView;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\StoreId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/** Reads orders with their lines into views; the snapshot is decoded as it was written (ORD-02), nothing is re-read from its sources. */
final readonly class OrderRows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
    ) {}

    /** @throws OrderNotFound */
    public function get(string $id): OrderView
    {
        $row = $this->db()->table($this->tables->table('orders'))->where('id', $id)->first();

        return $this->hydrate([$row ?? throw new OrderNotFound($id)])[0];
    }

    public function findByNumber(StoreId $store, string $number): ?OrderView
    {
        $row = $this->db()->table($this->tables->table('orders'))
            ->where('store_id', $store->value)
            ->where('number', $number)
            ->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /** The order a cart was placed as; null while it is not placed. */
    public function idOfCart(string $cartId): ?string
    {
        $id = $this->db()->table($this->tables->table('orders'))->where('cart_id', $cartId)->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * Builds the orders of the rows, reading all their lines in one query.
     *
     * @param  list<object>  $rows
     * @return list<OrderView>
     */
    public function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $lines = [];

        foreach ($this->db()->table($this->tables->table('order_lines'))
            ->whereIn('order_id', array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows))
            ->orderBy('position')
            ->get() as $line) {
            $lines[Rows::string($line, 'order_id')][] = self::line($line);
        }

        return array_map(static fn (object $row): OrderView => self::order($row, $lines[Rows::string($row, 'id')] ?? []), $rows);
    }

    /** @param  list<OrderLineView>  $lines */
    private static function order(object $row, array $lines): OrderView
    {
        $currency = Currency::of(Rows::string($row, 'currency'));
        $money = static fn (string $column): Money => Money::ofMinor(Rows::int($row, $column), $currency);
        $date = static function (string $column) use ($row): ?DateTimeImmutable {
            $value = Rows::nullableString($row, $column);

            return $value === null ? null : new DateTimeImmutable($value);
        };
        $customer = Rows::nullableString($row, 'customer_ref');
        $cancelReason = Rows::nullableString($row, 'cancel_reason');
        $shipping = Rows::nullableString($row, 'shipping_address');
        $billing = Rows::nullableString($row, 'billing_address');
        $delivery = Rows::nullableString($row, 'delivery');

        return new OrderView(
            new OrderId(Rows::string($row, 'id')),
            Rows::string($row, 'number'),
            new StoreId(Rows::string($row, 'store_id')),
            new ChannelId(Rows::string($row, 'channel_id')),
            new CartId(Rows::string($row, 'cart_id')),
            CartKind::from(Rows::string($row, 'origin')),
            OrderState::from(Rows::string($row, 'state')),
            PaymentState::from(Rows::string($row, 'payment_state')),
            FulfillmentState::from(Rows::string($row, 'fulfillment_state')),
            $cancelReason === null ? null : CancelReason::from($cancelReason),
            $currency,
            Rows::string($row, 'locale'),
            $customer === null ? null : CustomerRef::fromString($customer),
            SalesCodec::stringsFromJson(Rows::string($row, 'customer_groups')),
            Rows::nullableString($row, 'customer_email'),
            Rows::nullableString($row, 'customer_name'),
            $lines,
            SalesCodec::adjustmentsFromJson(Rows::string($row, 'order_adjustments'), $currency),
            $delivery === null ? null : SalesCodec::deliveryFromJson($delivery, $currency),
            SalesCodec::adjustmentsFromJson(Rows::string($row, 'delivery_adjustments'), $currency),
            SalesCodec::taxLinesFromJson(Rows::string($row, 'delivery_tax_lines'), $currency),
            new QuoteTotals(
                $money('subtotal_minor'),
                $money('discount_total_minor'),
                $money('delivery_total_minor'),
                $money('tax_total_minor'),
                $money('total_minor'),
            ),
            $money('amount_due_minor'),
            $money('authorized_minor'),
            $money('captured_minor'),
            $money('refunded_minor'),
            SalesCodec::stringsFromJson(Rows::string($row, 'codes')),
            $shipping === null ? null : SalesCodec::addressFromJson($shipping),
            $billing === null ? null : SalesCodec::addressFromJson($billing),
            Rows::string($row, 'quote_fingerprint'),
            $date('payment_due_at'),
            Rows::date($row, 'placed_at'),
            $date('completed_at'),
            $date('cancelled_at'),
        );
    }

    private static function line(object $row): OrderLineView
    {
        $id = Rows::string($row, 'id');
        $currency = Currency::of(Rows::string($row, 'currency'));
        $compareAt = Rows::nullableInt($row, 'compare_at_minor');

        return new OrderLineView(
            new OrderLineId($id),
            SellableRef::fromString(Rows::string($row, 'sellable_ref')),
            Rows::string($row, 'sku'),
            Rows::string($row, 'name'),
            SellableKind::from(Rows::string($row, 'kind')),
            Rows::bool($row, 'requires_shipping'),
            SalesCodec::optionsFromJson(Rows::string($row, 'options')),
            Rows::nullableString($row, 'tax_class'),
            Rows::nullableInt($row, 'weight_grams'),
            Rows::int($row, 'quantity'),
            Money::ofMinor(Rows::int($row, 'unit_price_minor'), $currency),
            $compareAt === null ? null : Money::ofMinor($compareAt, $currency),
            Rows::bool($row, 'tax_included'),
            Rows::string($row, 'price_source'),
            Money::ofMinor(Rows::int($row, 'subtotal_minor'), $currency),
            SalesCodec::adjustmentsFromJson(Rows::string($row, 'adjustments'), $currency, $id),
            SalesCodec::taxLinesFromJson(Rows::string($row, 'tax_lines'), $currency, $id),
            Money::ofMinor(Rows::int($row, 'total_minor'), $currency),
            Rows::int($row, 'cancelled_quantity'),
            Rows::int($row, 'fulfilled_quantity'),
            Rows::int($row, 'returned_quantity'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
